<?php

declare(strict_types=1);

/**
 * Plugin Name:       UAMS Media Reference Audit
 * Plugin URI:        https://www.uams.edu/
 * Description:       Finds media that is not attached to any post and media that is not referenced anywhere in your content, meta, options, or menus. Report first, delete second.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Brent Passmore
 * Author URI:        https://www.uams.edu/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uams-mra
 */
defined('ABSPATH') || exit;

define('UAMS_MRA_VERSION', '1.2.0');
define('UAMS_MRA_FILE', __FILE__);
define('UAMS_MRA_DIR', plugin_dir_path(__FILE__));
define('UAMS_MRA_URL', plugin_dir_url(__FILE__));
define('UAMS_MRA_CAP', 'manage_options');

require_once UAMS_MRA_DIR.'includes/class-store.php';
require_once UAMS_MRA_DIR.'includes/class-triage.php';
require_once UAMS_MRA_DIR.'includes/class-selection.php';
require_once UAMS_MRA_DIR.'includes/class-archive.php';
require_once UAMS_MRA_DIR.'includes/class-index.php';
require_once UAMS_MRA_DIR.'includes/class-scanner.php';
require_once UAMS_MRA_DIR.'includes/class-admin.php';
require_once UAMS_MRA_DIR.'includes/class-list-table.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once UAMS_MRA_DIR.'includes/class-cli.php';
    WP_CLI::add_command('uams-media-audit', 'UAMS_MRA_CLI');
}

/**
 * Default settings.
 */
function uams_mra_default_settings(): array
{
    return [
        'include_revisions' => 1, // Count a reference found only in a revision.
        'include_numeric_meta' => 1, // Treat a bare numeric meta value matching an attachment ID as a reference.
        'include_usermeta' => 1,
        'include_termmeta' => 1,
        'include_comments' => 1,
        'include_network' => 0, // Multisite: scan sibling sites for cross-site URL references.
        'extra_paths' => '', // Extra upload path prefixes, one per line, for CDN or offloaded media.
        'batch_posts' => 200,
        'batch_meta' => 2000,
        'batch_index' => 500,
        'batch_finalize' => 200,
    ];
}

/**
 * Read merged settings.
 *
 * @return array
 */
function uams_mra_settings()
{
    $saved = get_option('uams_mra_settings', []);

    return wp_parse_args(is_array($saved) ? $saved : [], uams_mra_default_settings());
}

register_activation_hook(__FILE__, 'uams_mra_activate');

/**
 * Create tables on activation. On multisite, only for the activated site.
 */
function uams_mra_activate(): void
{
    UAMS_MRA_Store::install();
}

add_action('wpmu_new_blog', 'uams_mra_new_site_tables');
add_action('wp_initialize_site', 'uams_mra_new_site_tables_modern');

/**
 * Legacy multisite hook, and the modern one's worker.
 *
 * @param  int|string  $blog_id  Site ID: an int from `wpmu_new_blog`, a numeric string from `WP_Site::$blog_id`.
 */
function uams_mra_new_site_tables($blog_id): void
{
    if (! is_plugin_active_for_network(plugin_basename(UAMS_MRA_FILE))) {
        return;
    }

    switch_to_blog($blog_id);
    UAMS_MRA_Store::install();
    restore_current_blog();
}

/**
 * Modern multisite hook.
 *
 * @param  WP_Site  $site  Site object.
 */
function uams_mra_new_site_tables_modern($site): void
{
    uams_mra_new_site_tables($site->blog_id);
}

/**
 * Make sure tables exist for the current site before we use them.
 */
function uams_mra_maybe_install(): void
{
    if (get_option('uams_mra_db_version') !== UAMS_MRA_VERSION) {
        UAMS_MRA_Store::install();
    }
}

add_action('admin_init', 'uams_mra_maybe_install');

add_action(UAMS_MRA_Archive::CLEANUP_HOOK, ['UAMS_MRA_Archive', 'cleanup']);

/**
 * Keep the daily archive cleanup scheduled.
 *
 * Archives sit in uploads and are a safety net for one sitting's decisions, not
 * storage. Without this a few aborted 165 MB jobs quietly become a disk problem.
 */
function uams_mra_schedule_cleanup(): void
{
    if (! wp_next_scheduled(UAMS_MRA_Archive::CLEANUP_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', UAMS_MRA_Archive::CLEANUP_HOOK);
    }
}

add_action('admin_init', 'uams_mra_schedule_cleanup');

register_deactivation_hook(__FILE__, 'uams_mra_deactivate');

/**
 * Unschedule the cleanup event and remove any archives left on disk.
 */
function uams_mra_deactivate(): void
{
    wp_clear_scheduled_hook(UAMS_MRA_Archive::CLEANUP_HOOK);
    UAMS_MRA_Archive::cleanup_all();
}

/**
 * Load translations.
 */
function uams_mra_load_textdomain(): void
{
    load_plugin_textdomain('uams-mra', false, dirname(plugin_basename(UAMS_MRA_FILE)).'/languages');
}

add_action('plugins_loaded', 'uams_mra_load_textdomain');

new UAMS_MRA_Admin;
