<?php

/**
 * The plugin file and uninstall.php against a real WordPress and database.
 *
 * Covers what loading the main file sets up (constants, hooks), the default and
 * merged settings, activation, the multisite new-site hooks, the install-on-upgrade
 * check, the daily cleanup schedule, deactivation, the translations path, and what
 * uninstall.php removes and leaves.
 *
 * uninstall.php declares a function, so it can be included once per process. The
 * helper reads the file and wraps that one declaration in a function_exists() guard
 * before running it, so every test can run the real file. Nothing else is changed.
 *
 * Mutants this suite cannot kill, each equivalent: the `|| exit` guards on the two
 * files (constants are always defined when the file runs, WP_UNINSTALL_PLUGIN is
 * defined by the helper); the version header read by get_file_data() is pinned by
 * PluginVersionTest; the hour offset of the first cleanup run is only bounded here.
 *
 * @see uamswp-media-reference-audit.php
 * @see uninstall.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Archive;
use UAMS_MRA_Store;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;
use WP_Site;

final class PluginFileTest extends IntegrationTestCase
{
    private const OPTIONS = ['uams_mra_settings', 'uams_mra_scan_state', 'uams_mra_db_version', 'uams_mra_repoint_state', 'uams_mra_repoint_fatal'];

    private const SUFFIXES = ['uams_mra_index', 'uams_mra_refs', 'uams_mra_results', 'uams_mra_offsite'];

    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
    }

    public function tear_down(): void
    {
        wp_clear_scheduled_hook(UAMS_MRA_Archive::CLEANUP_HOOK);
        delete_site_option('active_sitewide_plugins');

        parent::tear_down();
    }

    /**
     * Whether a table can be read, which also holds for the temporary tables the test case makes.
     */
    private function tableExists(string $table): bool
    {
        global $wpdb;

        $wpdb->suppress_errors(true);
        $found = $wpdb->query("SELECT 1 FROM {$table} LIMIT 1") !== false;
        $wpdb->suppress_errors(false);

        return $found;
    }

    /**
     * The next scheduled cleanup, read afresh.
     */
    private function nextCleanup(): int|false
    {
        return wp_next_scheduled(UAMS_MRA_Archive::CLEANUP_HOOK);
    }

    /**
     * Run uninstall.php as WordPress would.
     */
    private function uninstall(): void
    {
        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', plugin_basename(UAMS_MRA_FILE));
        }

        $source = (string) file_get_contents(dirname(UAMS_MRA_FILE).'/uninstall.php');
        $guarded = str_replace(
            'function uams_mra_uninstall_site(',
            "if (! function_exists('uams_mra_uninstall_site')) {\nfunction uams_mra_uninstall_site(",
            $source,
            $count
        );
        $this->assertSame(1, $count, 'The one function declaration in uninstall.php was found.');
        $guarded = str_replace("\n}\n\nif (is_multisite()", "\n}\n}\n\nif (is_multisite()", $guarded, $closed);
        $this->assertSame(1, $closed, 'The end of that declaration was found.');

        // The file opens with a PHP tag, which eval() does not take.
        eval('?>'.$guarded);
    }

    public function test_loading_the_file_defines_the_constants(): void
    {
        $this->assertSame(dirname(__DIR__, 2).'/uamswp-media-reference-audit.php', UAMS_MRA_FILE);
        $this->assertSame(plugin_dir_path(UAMS_MRA_FILE), constant('UAMS_MRA_DIR'));
        $this->assertSame(plugin_dir_url(UAMS_MRA_FILE), UAMS_MRA_URL);
        $this->assertSame('manage_options', UAMS_MRA_CAP);
    }

    public function test_loading_the_file_registers_its_hooks(): void
    {
        $this->assertSame(10, has_action('wpmu_new_blog', 'uams_mra_new_site_tables'));
        $this->assertSame(10, has_action('wp_initialize_site', 'uams_mra_new_site_tables_modern'));
        $this->assertSame(10, has_action('admin_init', 'uams_mra_maybe_install'));
        $this->assertSame(10, has_action('admin_init', 'uams_mra_schedule_cleanup'));
        $this->assertSame(10, has_action(UAMS_MRA_Archive::CLEANUP_HOOK, ['UAMS_MRA_Archive', 'cleanup']));
        $this->assertSame(10, has_action('plugins_loaded', 'uams_mra_load_textdomain'));
    }

    public function test_the_activation_and_deactivation_hooks_are_registered_for_the_plugin(): void
    {
        $basename = plugin_basename(UAMS_MRA_FILE);

        $this->assertSame(10, has_action('activate_'.$basename, 'uams_mra_activate'));
        $this->assertSame(10, has_action('deactivate_'.$basename, 'uams_mra_deactivate'));
    }

    public function test_the_default_settings_are_exact(): void
    {
        $this->assertSame(
            [
                'include_revisions' => 1,
                'include_numeric_meta' => 1,
                'include_usermeta' => 1,
                'include_termmeta' => 1,
                'include_comments' => 1,
                'include_network' => 0,
                'include_render' => 1,
                'include_offsite' => 1,
                'extra_paths' => '',
                'batch_posts' => 200,
                'batch_meta' => 2000,
                'batch_index' => 500,
                'batch_finalize' => 200,
                'batch_render' => 3,
                'batch_repoint' => 5,
                'batch_sideload' => 1,
                'sideload_max_mb' => 64,
                'render_max_pages' => 300,
            ],
            uams_mra_default_settings()
        );
    }

    public function test_settings_are_the_defaults_when_nothing_is_saved(): void
    {
        delete_option('uams_mra_settings');

        $this->assertSame(uams_mra_default_settings(), uams_mra_settings());
    }

    public function test_saved_settings_override_the_defaults_and_keep_the_rest(): void
    {
        update_option('uams_mra_settings', ['batch_posts' => 7, 'include_render' => 0, 'unknown_key' => 'kept']);

        $settings = uams_mra_settings();

        $this->assertSame(7, $settings['batch_posts']);
        $this->assertSame(0, $settings['include_render']);
        $this->assertSame('kept', $settings['unknown_key']);
        $this->assertSame(2000, $settings['batch_meta']);
        $this->assertSame(array_keys(uams_mra_default_settings()), array_slice(array_keys($settings), 0, count(uams_mra_default_settings())));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonArraySettings(): array
    {
        return [
            'a string' => ['broken'],
            'an empty string' => [''],
            'null stored as a string' => ['null'],
            'an integer' => [5],
            'false' => [false],
        ];
    }

    /**
     * @dataProvider nonArraySettings
     */
    public function test_a_saved_value_that_is_not_an_array_falls_back_to_the_defaults(mixed $saved): void
    {
        update_option('uams_mra_settings', $saved);

        $this->assertSame(uams_mra_default_settings(), uams_mra_settings());
    }

    public function test_activation_creates_the_tables(): void
    {
        global $wpdb;

        foreach (self::SUFFIXES as $suffix) {
            $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.$suffix);
            $this->assertFalse($this->tableExists($wpdb->prefix.$suffix), $suffix.' is gone before activation.');
        }

        uams_mra_activate();

        foreach (self::SUFFIXES as $suffix) {
            $this->assertTrue($this->tableExists($wpdb->prefix.$suffix), $suffix.' exists after activation.');
        }
    }

    public function test_install_records_the_version_it_ran_for(): void
    {
        delete_option('uams_mra_db_version');

        uams_mra_activate();

        $this->assertSame(UAMS_MRA_VERSION, get_option('uams_mra_db_version'));
    }

    public function test_maybe_install_runs_the_install_when_the_stored_version_differs(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS '.UAMS_MRA_Store::refs_table());
        update_option('uams_mra_db_version', '0.0.1');

        uams_mra_maybe_install();

        $this->assertTrue($this->tableExists(UAMS_MRA_Store::refs_table()));
        $this->assertSame(UAMS_MRA_VERSION, get_option('uams_mra_db_version'));
    }

    public function test_maybe_install_runs_the_install_when_no_version_is_stored(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS '.UAMS_MRA_Store::refs_table());
        delete_option('uams_mra_db_version');

        uams_mra_maybe_install();

        $this->assertTrue($this->tableExists(UAMS_MRA_Store::refs_table()));
    }

    public function test_maybe_install_does_nothing_when_the_version_matches(): void
    {
        global $wpdb;

        update_option('uams_mra_db_version', UAMS_MRA_VERSION);
        $wpdb->query('DROP TABLE IF EXISTS '.UAMS_MRA_Store::refs_table());

        uams_mra_maybe_install();

        $this->assertFalse($this->tableExists(UAMS_MRA_Store::refs_table()), 'A matching version means the tables are not touched.');
    }

    public function test_the_cleanup_is_scheduled_daily_an_hour_out_when_missing(): void
    {
        wp_clear_scheduled_hook(UAMS_MRA_Archive::CLEANUP_HOOK);
        $this->assertFalse(wp_next_scheduled(UAMS_MRA_Archive::CLEANUP_HOOK));

        $before = time();
        uams_mra_schedule_cleanup();
        $after = time();

        $next = $this->nextCleanup();
        $this->assertIsInt($next);
        $this->assertGreaterThanOrEqual($before + HOUR_IN_SECONDS, $next);
        $this->assertLessThanOrEqual($after + HOUR_IN_SECONDS, $next);
        $this->assertSame('daily', wp_get_schedule(UAMS_MRA_Archive::CLEANUP_HOOK));
    }

    public function test_an_existing_cleanup_schedule_is_left_alone(): void
    {
        $when = time() + 5 * HOUR_IN_SECONDS;
        wp_clear_scheduled_hook(UAMS_MRA_Archive::CLEANUP_HOOK);
        wp_schedule_event($when, 'hourly', UAMS_MRA_Archive::CLEANUP_HOOK);

        uams_mra_schedule_cleanup();

        $this->assertSame($when, wp_next_scheduled(UAMS_MRA_Archive::CLEANUP_HOOK));
        $this->assertSame('hourly', wp_get_schedule(UAMS_MRA_Archive::CLEANUP_HOOK));
    }

    public function test_deactivation_unschedules_the_cleanup_and_removes_every_archive(): void
    {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', UAMS_MRA_Archive::CLEANUP_HOOK);
        $job = UAMS_MRA_Archive::base_dir().'/job-fixture';
        wp_mkdir_p($job);
        file_put_contents($job.'/part.zip', 'x');
        $this->assertDirectoryExists($job);

        uams_mra_deactivate();

        $this->assertFalse(wp_next_scheduled(UAMS_MRA_Archive::CLEANUP_HOOK));
        $this->assertDirectoryDoesNotExist($job);
    }

    public function test_the_textdomain_is_loaded_from_the_languages_folder_of_the_plugin(): void
    {
        global $wp_textdomain_registry;

        if (! property_exists($wp_textdomain_registry, 'custom_paths')) {
            $this->markTestSkipped('This WordPress does not keep custom textdomain paths in a registry.');
        }

        $wp_textdomain_registry->set_custom_path('uams-mra', '/somewhere/else');

        uams_mra_load_textdomain();

        $paths = (new \ReflectionProperty($wp_textdomain_registry, 'custom_paths'))->getValue($wp_textdomain_registry);
        $this->assertSame(WP_PLUGIN_DIR.'/'.basename(dirname(UAMS_MRA_FILE)).'/languages', $paths['uams-mra']);
    }

    public function test_the_new_site_hooks_leave_a_site_alone_when_the_plugin_is_not_network_active(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite install.');
        }

        // Creating a site fires the legacy hook the plugin still listens on.
        $this->setExpectedDeprecated('wpmu_new_blog');

        $blog = self::factory()->blog->create();
        switch_to_blog($blog);
        $prefix = $GLOBALS['wpdb']->prefix;
        restore_current_blog();

        $this->assertFalse($this->tableExists($prefix.'uams_mra_index'));
    }

    public function test_a_new_site_gets_its_own_tables_when_the_plugin_is_network_active(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite install.');
        }

        $this->setExpectedDeprecated('wpmu_new_blog');
        update_site_option('active_sitewide_plugins', [plugin_basename(UAMS_MRA_FILE) => time()]);
        $current = get_current_blog_id();

        $blog = self::factory()->blog->create();

        switch_to_blog($blog);
        $prefix = $GLOBALS['wpdb']->prefix;
        restore_current_blog();

        $this->assertNotSame($current, $blog);
        $this->assertSame($current, get_current_blog_id(), 'The site context is restored.');

        foreach (self::SUFFIXES as $suffix) {
            $this->assertTrue($this->tableExists($prefix.$suffix), $suffix.' exists for the new site.');
        }
    }

    public function test_the_legacy_hook_builds_tables_for_the_site_it_is_given(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite install.');
        }

        // Creating a site fires the legacy hook the plugin still listens on.
        $this->setExpectedDeprecated('wpmu_new_blog');

        $blog = self::factory()->blog->create();
        update_site_option('active_sitewide_plugins', [plugin_basename(UAMS_MRA_FILE) => time()]);

        uams_mra_new_site_tables($blog);

        switch_to_blog($blog);
        $prefix = $GLOBALS['wpdb']->prefix;
        $stored = get_option('uams_mra_db_version');
        restore_current_blog();

        $this->assertTrue($this->tableExists($prefix.'uams_mra_refs'));
        $this->assertSame(UAMS_MRA_VERSION, $stored);
    }

    public function test_the_modern_hook_passes_the_site_id_of_the_site_object(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite install.');
        }

        // Creating a site fires the legacy hook the plugin still listens on.
        $this->setExpectedDeprecated('wpmu_new_blog');

        $blog = self::factory()->blog->create();
        update_site_option('active_sitewide_plugins', [plugin_basename(UAMS_MRA_FILE) => time()]);
        $site = get_site($blog);
        $this->assertInstanceOf(WP_Site::class, $site);

        uams_mra_new_site_tables_modern($site);

        switch_to_blog($blog);
        $prefix = $GLOBALS['wpdb']->prefix;
        restore_current_blog();

        $this->assertTrue($this->tableExists($prefix.'uams_mra_offsite'));
    }

    public function test_uninstall_drops_the_four_tables(): void
    {
        global $wpdb;

        foreach (self::SUFFIXES as $suffix) {
            $this->assertTrue($this->tableExists($wpdb->prefix.$suffix), $suffix.' exists before uninstall.');
        }

        $this->uninstall();

        foreach (self::SUFFIXES as $suffix) {
            $this->assertFalse($this->tableExists($wpdb->prefix.$suffix), $suffix.' is gone after uninstall.');
        }
    }

    public function test_uninstall_deletes_every_option_the_plugin_stores(): void
    {
        foreach (self::OPTIONS as $option) {
            update_option($option, 'value');
        }

        $this->uninstall();

        foreach (self::OPTIONS as $option) {
            $this->assertFalse(get_option($option), $option.' is deleted.');
        }
    }

    public function test_uninstall_deletes_the_triage_meta_from_every_attachment(): void
    {
        $one = self::factory()->attachment->create();
        $two = self::factory()->attachment->create();
        update_post_meta($one, '_uams_mra_disposition', 'keep');
        update_post_meta($one, '_uams_mra_note', 'a note');
        update_post_meta($two, '_uams_mra_disposition', 'delete');

        $this->uninstall();

        $this->assertSame('', get_post_meta($one, '_uams_mra_disposition', true));
        $this->assertSame('', get_post_meta($one, '_uams_mra_note', true));
        $this->assertSame('', get_post_meta($two, '_uams_mra_disposition', true));
    }

    public function test_uninstall_leaves_everything_else_alone(): void
    {
        global $wpdb;

        $attachment = self::factory()->attachment->create();
        $post = self::factory()->post->create();
        update_post_meta($attachment, '_other_meta', 'kept');
        update_post_meta($post, '_uams_mra_other', 'kept');
        update_option('uams_mra_unrelated', 'kept');
        update_option('blogname', 'Kept name');
        set_transient('uams_mra_probe', 'kept');
        $wpdb->query("CREATE TABLE {$wpdb->prefix}uams_mra_other (id int)");

        $this->uninstall();

        $this->assertSame('kept', get_post_meta($attachment, '_other_meta', true));
        $this->assertSame('kept', get_post_meta($post, '_uams_mra_other', true));
        $this->assertSame('kept', get_option('uams_mra_unrelated'));
        $this->assertSame('Kept name', get_option('blogname'));
        $this->assertSame('kept', get_transient('uams_mra_probe'));
        $this->assertTrue($this->tableExists($wpdb->prefix.'uams_mra_other'));
        $this->assertInstanceOf(\WP_Post::class, get_post($attachment));
        $this->assertInstanceOf(\WP_Post::class, get_post($post));
    }

    public function test_a_network_uninstall_cleans_every_site(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite install.');
        }

        // Creating a site fires the legacy hook the plugin still listens on.
        $this->setExpectedDeprecated('wpmu_new_blog');

        $blogs = [self::factory()->blog->create(), self::factory()->blog->create()];
        $prefixes = [];

        foreach ($blogs as $blog) {
            switch_to_blog($blog);
            UAMS_MRA_Store::install();
            update_option('uams_mra_settings', ['batch_posts' => 9]);
            update_option('uams_mra_unrelated', 'kept');
            $prefixes[$blog] = $GLOBALS['wpdb']->prefix;
            restore_current_blog();
        }

        $this->uninstall();

        foreach ($blogs as $blog) {
            foreach (self::SUFFIXES as $suffix) {
                $this->assertFalse($this->tableExists($prefixes[$blog].$suffix), "Site {$blog}: {$suffix} is gone.");
            }

            switch_to_blog($blog);
            $this->assertFalse(get_option('uams_mra_settings'), "Site {$blog}: the settings are deleted.");
            $this->assertSame('kept', get_option('uams_mra_unrelated'), "Site {$blog}: other options stay.");
            $this->assertSame(get_current_blog_id(), $blog);
            restore_current_blog();
        }
    }
}

// cspell:ignore hourly
