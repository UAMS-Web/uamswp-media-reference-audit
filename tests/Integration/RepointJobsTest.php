<?php

/**
 * The repoint jobs of UAMS_MRA_Repoint against a real WordPress and database.
 *
 * A job is stored in an option and moves through plan, apply and side-load one
 * batch at a time. Every step reads and writes the real offsite table, the
 * rewrite goes through core's own functions, and the one network call each phase
 * makes is answered through `pre_http_request`, so the cursor, the batch limit,
 * the verdicts and what ends up stored are all asserted on the stored result.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use ReflectionMethod;
use RuntimeException;
use UAMS_MRA_Index;
use UAMS_MRA_Repoint;
use UAMS_MRA_Store;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;
use WP_Error;

final class RepointJobsTest extends IntegrationTestCase
{
    private const OLD = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf';

    private const NEW = 'https://new.example.edu/wp-content/uploads/2024/05/report.pdf';

    /**
     * What the HTTP stand-in answers, by method then URL.
     *
     * A HEAD entry is [status, content length or null]. A GET entry is [status, body], a WP_Error,
     * or a callable that receives the request arguments and returns either.
     *
     * @var array{HEAD: array<string, mixed>, GET: array<string, mixed>}
     */
    private array $http = ['HEAD' => [], 'GET' => []];

    /**
     * Every request the stand-in saw.
     *
     * @var list<array{string, string, array<string, mixed>}>
     */
    private array $requests = [];

    /**
     * Files made on disk by a test, removed again afterwards.
     *
     * @var list<string>
     */
    private array $files = [];

    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
        UAMS_MRA_Store::reset();
        delete_option(UAMS_MRA_Repoint::STATE_OPTION);
        delete_option(UAMS_MRA_Repoint::FATAL_OPTION);
        delete_option('uams_mra_settings');

        $this->http = ['HEAD' => [], 'GET' => []];
        $this->requests = [];

        // Only an administrator who holds unfiltered_html may have a page rewritten; on multisite that is a super administrator.
        $admin = self::factory()->user->create(['role' => 'administrator']);

        if (is_multisite()) {
            grant_super_admin($admin);
        }

        wp_set_current_user($admin);

        add_filter('pre_http_request', [$this, 'answer'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'answer'], 10);

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tear_down();
    }

    /**
     * The HTTP stand-in.
     *
     * @param  false|array<string, mixed>|WP_Error  $pre
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|WP_Error
     */
    public function answer($pre, array $args, string $url): WP_Error|array
    {
        $method = (string) ($args['method'] ?? 'GET');
        $this->requests[] = [$method, $url, $args];

        $entry = $this->http[$method][$url] ?? null;

        if ($entry === null) {
            return new WP_Error('http_request_failed', 'No answer set for '.$method.' '.$url);
        }

        if ($method === 'HEAD') {
            $headers = $entry[1] === null ? [] : ['content-length' => (string) $entry[1]];

            return ['headers' => $headers, 'body' => '', 'response' => ['code' => $entry[0], 'message' => ''], 'cookies' => [], 'filename' => null];
        }

        if (is_callable($entry)) {
            $entry = $entry($args);
        }

        if ($entry instanceof WP_Error) {
            return $entry;
        }

        if (! empty($args['filename'])) {
            file_put_contents((string) $args['filename'], $entry[1]);
        }

        return ['headers' => [], 'body' => $entry[1], 'response' => ['code' => $entry[0], 'message' => ''], 'cookies' => [], 'filename' => $args['filename'] ?? null];
    }

    /**
     * Pass a value through untyped, so static analysis does not narrow it.
     */
    private function loose(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Call a protected static method of the repoint class.
     */
    private function call(string $method, mixed ...$args): mixed
    {
        return $this->loose((new ReflectionMethod(UAMS_MRA_Repoint::class, $method))->invoke(null, ...$args));
    }

    /**
     * Store one offsite row and return its id.
     *
     * @param  array<string, mixed>  $over
     */
    private function row(array $over = []): int
    {
        global $wpdb;

        static $pk = 1000;
        $pk++;

        $row = array_merge([
            'url' => self::OLD,
            'host' => 'old.example.edu',
            'base_norm' => 'report.pdf',
            'source_type' => 'content',
            'source_id' => $pk,
            'source_pk' => $pk,
            'source_key' => '',
            'source_label' => 'Post',
            'local_id' => 0,
            'verdict' => '',
            'remote_bytes' => 0,
            'local_bytes' => 0,
            'target_url' => '',
            'applied' => 0,
        ], $over);

        $row['url_hash'] = md5((string) $row['url']);

        $wpdb->insert(UAMS_MRA_Store::offsite_table(), $row);

        return (int) $wpdb->insert_id;
    }

    /**
     * One stored offsite row.
     *
     * @return array<string, mixed>
     */
    private function stored(int $id): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.UAMS_MRA_Store::offsite_table().' WHERE offsite_id = %d', $id), ARRAY_A);

        return is_array($row) ? $row : [];
    }

    /**
     * Run one offsite row through rewrite_row().
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function rewrite(array $over): array
    {
        $result = $this->call('rewrite_row', (object) array_merge(['url' => self::OLD, 'target_url' => self::NEW, 'source_key' => '', 'source_id' => 0, 'source_pk' => 0], $over));

        $this->assertIsArray($result);

        return $result;
    }

    /**
     * Store a meta value exactly as given.
     */
    private function metaRow(string $table, string $id_column, int $id, string $value): int
    {
        global $wpdb;

        $wpdb->insert($table, [$id_column => $id, 'meta_key' => 'uams_mra_jobs', 'meta_value' => $value]);

        return (int) $wpdb->insert_id;
    }

    private function metaValue(string $table, string $pk_column, int $pk): ?string
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$table} WHERE {$pk_column} = %d", $pk));
    }

    /**
     * Set the plugin settings.
     *
     * @param  array<string, mixed>  $settings
     */
    private function settings(array $settings): void
    {
        update_option('uams_mra_settings', $settings);
    }

    /**
     * Store a job in the state option.
     *
     * @param  array<string, mixed>  $job
     */
    private function putJob(array $job): void
    {
        update_option(UAMS_MRA_Repoint::STATE_OPTION, $job, false);
    }

    /**
     * A small JPEG of the given size, as bytes.
     */
    private function jpeg(int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * An attachment whose file is the given bytes under uploads, indexed.
     */
    private function attachment(string $file, string $bytes): int
    {
        $path = wp_get_upload_dir()['basedir'].'/'.$file;
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        $id = self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);
        update_post_meta($id, '_wp_attached_file', $file);
        UAMS_MRA_Index::build_batch($id - 1, 1);

        return $id;
    }

    // ----------------------------------------------------------------- fatal capture

    public function test_a_fatal_is_recorded_with_its_message_place_and_context(): void
    {
        $result = $this->fatalChild("UAMS_MRA_Repoint::watch_for_fatal('while testing'); trigger_a_fatal_error();");

        $this->assertSame('while testing', $result['context']);
        $this->assertStringContainsString('trigger_a_fatal_error', $result['message']);
        $this->assertStringContainsString('fatal-child', $result['file']);
        $this->assertIsInt($result['line']);
        $this->assertGreaterThan(0, $result['line']);
        $this->assertEqualsWithDelta(time(), $result['when'], 60);
        $this->assertFalse($result['autoload']);
    }

    public function test_a_request_that_ends_normally_records_nothing(): void
    {
        $this->assertNull($this->fatalChild("UAMS_MRA_Repoint::watch_for_fatal('fine'); echo 'ok';"));
    }

    public function test_a_warning_is_not_recorded_as_a_fatal(): void
    {
        $this->assertNull($this->fatalChild("UAMS_MRA_Repoint::watch_for_fatal('warned'); trigger_error('only a warning', E_USER_WARNING);"));
    }

    public function test_a_user_error_is_recorded_as_a_fatal(): void
    {
        $result = $this->fatalChild("UAMS_MRA_Repoint::watch_for_fatal('user'); trigger_error('stop here', E_USER_ERROR);");

        $this->assertSame('stop here', $result['message']);
        $this->assertSame('user', $result['context']);
    }

    public function test_a_fatal_with_no_context_given_is_recorded_with_an_empty_one(): void
    {
        $result = $this->fatalChild("UAMS_MRA_Repoint::watch_for_fatal(); trigger_error('stop', E_USER_ERROR);");

        $this->assertSame('', $result['context']);
    }

    public function test_watching_for_a_fatal_forgets_the_one_recorded_before(): void
    {
        update_option(UAMS_MRA_Repoint::FATAL_OPTION, ['message' => 'old']);

        UAMS_MRA_Repoint::watch_for_fatal('again');

        $this->assertFalse(get_option(UAMS_MRA_Repoint::FATAL_OPTION));
    }

    /**
     * Run a snippet in a child PHP with the repoint class and stand-ins for the option functions.
     *
     * @return array<string, mixed>|null What the shutdown handler stored, or null when it stored nothing.
     */
    private function fatalChild(string $snippet): ?array
    {
        $out = sys_get_temp_dir().'/uams-mra-fatal-'.bin2hex(random_bytes(4)).'.json';
        $script = sys_get_temp_dir().'/uams-mra-fatal-child-'.bin2hex(random_bytes(4)).'.php';
        $class = dirname(__DIR__, 2).'/includes/class-repoint.php';

        file_put_contents($script, "<?php\n"
            ."define('ABSPATH', '/');\n"
            ."function delete_option(\$name) { return true; }\n"
            .'function update_option($name, $value, $autoload = null) { file_put_contents('.var_export($out, true).", json_encode(\$value + ['autoload' => \$autoload])); return true; }\n"
            .'require '.var_export($class, true).";\n"
            .$snippet."\n");
        $this->files[] = $script;
        $this->files[] = $out;

        shell_exec(escapeshellarg(PHP_BINARY).' -d display_errors=0 -d log_errors=0 '.escapeshellarg($script).' 2>&1');

        if (! is_file($out)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_the_recorded_fatal_reads_back_and_clears(): void
    {
        $this->assertSame([], UAMS_MRA_Repoint::last_fatal());

        update_option(UAMS_MRA_Repoint::FATAL_OPTION, ['message' => 'boom', 'line' => 3]);
        $this->assertSame(['message' => 'boom', 'line' => 3], UAMS_MRA_Repoint::last_fatal());

        update_option(UAMS_MRA_Repoint::FATAL_OPTION, 'not a list');
        $this->assertSame([], UAMS_MRA_Repoint::last_fatal());

        update_option(UAMS_MRA_Repoint::FATAL_OPTION, ['message' => 'boom']);
        UAMS_MRA_Repoint::clear_fatal();
        $this->assertFalse(get_option(UAMS_MRA_Repoint::FATAL_OPTION));
    }

    public function test_the_next_target_names_the_phase_and_the_file_at_the_head_of_the_queue(): void
    {
        $this->assertSame('', UAMS_MRA_Repoint::next_target());

        $this->putJob(['phase' => 'sideload', 'queue' => []]);
        $this->assertSame('sideload', UAMS_MRA_Repoint::next_target());

        $this->putJob(['phase' => 'sideload', 'queue' => [['url' => '']]]);
        $this->assertSame('sideload', UAMS_MRA_Repoint::next_target());

        $this->putJob(['phase' => 'sideload', 'queue' => [['hashes' => []]]]);
        $this->assertSame('sideload', UAMS_MRA_Repoint::next_target());

        $this->putJob(['phase' => 'sideload', 'queue' => [['url' => 'https://o.example.edu/a.pdf'], ['url' => 'https://o.example.edu/b.pdf']]]);
        $this->assertSame('sideload: https://o.example.edu/a.pdf', UAMS_MRA_Repoint::next_target());
    }

    // ----------------------------------------------------------------- job state

    public function test_the_job_reads_back_as_stored_and_as_nothing_when_it_is_not_a_list(): void
    {
        $this->assertSame([], UAMS_MRA_Repoint::get_job());

        $this->putJob(['phase' => 'apply', 'done' => 2]);
        $this->assertSame(['phase' => 'apply', 'done' => 2], UAMS_MRA_Repoint::get_job());

        update_option(UAMS_MRA_Repoint::STATE_OPTION, 'garbage');
        $this->assertSame([], UAMS_MRA_Repoint::get_job());
    }

    public function test_discarding_the_job_removes_it(): void
    {
        $this->putJob(['phase' => 'apply']);

        UAMS_MRA_Repoint::discard();

        $this->assertFalse(get_option(UAMS_MRA_Repoint::STATE_OPTION));
        $this->assertSame([], UAMS_MRA_Repoint::get_job());
    }

    public function test_saving_a_job_stamps_it_and_keeps_it_out_of_the_autoloaded_options(): void
    {
        global $wpdb;

        $this->call('put_job', ['phase' => 'apply']);

        $job = UAMS_MRA_Repoint::get_job();

        $this->assertSame('apply', $job['phase']);
        $this->assertEqualsWithDelta(time(), $job['updated'], 5);
        $this->assertContains($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", UAMS_MRA_Repoint::STATE_OPTION)), ['no', 'off']);
    }

    public function test_the_stall_timeout_is_five_minutes_unless_filtered(): void
    {
        $this->assertSame(300, $this->call('stall_timeout'));

        add_filter('uams_mra_repoint_stall_timeout', static fn (): string => '45');

        $this->assertSame(45, $this->call('stall_timeout'));
    }

    public function test_a_job_counts_as_running_until_it_has_been_quiet_for_the_timeout(): void
    {
        add_filter('uams_mra_repoint_stall_timeout', static fn (): int => 10);

        $this->putJob(['phase' => 'apply', 'updated' => time() - 5]);
        $this->assertTrue(UAMS_MRA_Repoint::is_running());

        $this->putJob(['phase' => 'sideload', 'updated' => time() - 5]);
        $this->assertTrue(UAMS_MRA_Repoint::is_running());

        $this->putJob(['phase' => 'apply', 'updated' => time() - 11]);
        $this->assertFalse(UAMS_MRA_Repoint::is_running());
    }

    public function test_a_job_quiet_for_exactly_the_timeout_is_no_longer_running(): void
    {
        add_filter('uams_mra_repoint_stall_timeout', static fn (): int => 10);

        do {
            $now = time();
            $this->putJob(['phase' => 'apply', 'updated' => $now - 10]);
            $running = UAMS_MRA_Repoint::is_running();
        } while ($now !== time());

        $this->assertFalse($running);
    }

    public function test_no_job_a_finished_job_and_an_unstamped_job_are_not_running(): void
    {
        $this->assertFalse(UAMS_MRA_Repoint::is_running());

        $this->putJob(['phase' => 'done', 'updated' => time()]);
        $this->assertFalse(UAMS_MRA_Repoint::is_running());

        $this->putJob(['updated' => time()]);
        $this->assertFalse(UAMS_MRA_Repoint::is_running());

        $this->putJob(['phase' => 'apply']);
        $this->assertFalse(UAMS_MRA_Repoint::is_running());

        $this->putJob(['phase' => 'apply', 'updated' => 0]);
        $this->assertFalse(UAMS_MRA_Repoint::is_running());
    }

    public function test_the_batch_size_follows_the_setting_between_one_and_twenty_five(): void
    {
        $this->assertSame(5, $this->call('batch'));

        foreach ([[0, 1], [1, 1], [2, 2], [24, 24], [25, 25], [26, 25], [500, 25], ['7', 7]] as [$setting, $expected]) {
            $this->settings(['batch_repoint' => $setting]);
            $this->assertSame($expected, $this->call('batch'), 'setting '.$setting);
        }
    }

    // ----------------------------------------------------------------- start

    public function test_planning_clears_the_old_verdicts_of_unapplied_rows_and_counts_the_distinct_files(): void
    {
        $open = $this->row(['verdict' => 'identical', 'local_id' => 9, 'remote_bytes' => 5, 'local_bytes' => 5, 'target_url' => self::NEW, 'checked_at' => '2024-01-01 00:00:00']);
        $this->row(['verdict' => 'differs', 'source_type' => 'excerpt']);
        $this->row(['url' => 'https://old.example.edu/b.pdf', 'base_norm' => 'b.pdf', 'verdict' => 'nolocal']);
        $done = $this->row(['url' => 'https://old.example.edu/c.pdf', 'base_norm' => 'c.pdf', 'verdict' => 'identical', 'local_id' => 4, 'target_url' => self::NEW, 'applied' => 1]);

        $job = UAMS_MRA_Repoint::start_plan();

        $this->assertSame('plan', $job['phase']);
        $this->assertSame(2, $job['total']);
        $this->assertSame(0, $job['done']);
        $this->assertSame(0, $job['applied']);
        $this->assertSame(0, $job['failed']);
        $this->assertSame(0, $job['skipped']);
        $this->assertSame('Comparing each file with the copy in this library', $job['message']);

        $cleared = $this->stored($open);
        $this->assertSame('', $cleared['verdict']);
        $this->assertSame('0', $cleared['local_id']);
        $this->assertSame('0', $cleared['remote_bytes']);
        $this->assertSame('0', $cleared['local_bytes']);
        $this->assertSame('', $cleared['target_url']);
        $this->assertSame('0000-00-00 00:00:00', $cleared['checked_at']);

        $kept = $this->stored($done);
        $this->assertSame('identical', $kept['verdict']);
        $this->assertSame('4', $kept['local_id']);
        $this->assertSame(self::NEW, $kept['target_url']);

        $this->assertSame($job['phase'], UAMS_MRA_Repoint::get_job()['phase']);
        $this->assertSame(2, UAMS_MRA_Repoint::get_job()['total']);
    }

    public function test_applying_counts_the_rows_a_verdict_has_cleared(): void
    {
        $this->row(['verdict' => 'identical', 'target_url' => self::NEW]);
        $this->row(['verdict' => 'sideloaded', 'target_url' => self::NEW]);
        $this->row(['verdict' => 'resized', 'target_url' => self::NEW]);
        $this->row(['verdict' => 'differs', 'target_url' => self::NEW]);
        $this->row(['verdict' => 'identical', 'target_url' => '']);
        $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'applied' => 2]);

        $job = UAMS_MRA_Repoint::start_apply();

        $this->assertSame('apply', $job['phase']);
        $this->assertSame(0, $job['resized']);
        $this->assertSame(2, $job['total']);
        $this->assertSame(0, $job['done']);
        $this->assertSame(0, $job['applied']);
        $this->assertSame(0, $job['failed']);
        $this->assertSame(0, $job['skipped']);
        $this->assertSame('Rewriting stored URLs', $job['message']);
        $this->assertSame('apply', UAMS_MRA_Repoint::get_job()['phase']);

        $with = UAMS_MRA_Repoint::start_apply(true);

        $this->assertSame(1, $with['resized']);
        $this->assertSame(3, $with['total']);
    }

    public function test_applying_and_pulling_over_are_refused_while_the_report_predates_the_row_columns(): void
    {
        $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'source_pk' => 0]);

        foreach (['start_apply', 'start_sideload'] as $start) {
            UAMS_MRA_Repoint::discard();

            $job = UAMS_MRA_Repoint::$start();

            $this->assertSame('done', $job['phase'], $start);
            $this->assertSame(0, $job['total']);
            $this->assertSame(0, $job['done']);
            $this->assertSame(0, $job['applied']);
            $this->assertSame(0, $job['failed']);
            $this->assertSame(0, $job['skipped']);
            $this->assertSame('This report was built by an older version and does not record which database row each URL sits in. Run a scan first.', $job['message']);
            $this->assertSame('done', UAMS_MRA_Repoint::get_job()['phase']);
        }
    }

    public function test_pulling_over_queues_one_entry_per_original_with_documents_before_images(): void
    {
        $this->row(['url' => 'https://old.example.edu/u/photo-300x200.jpg', 'verdict' => 'nolocal']);
        $this->row(['url' => 'https://old.example.edu/u/photo.jpg', 'verdict' => 'nolocal']);
        $this->row(['url' => 'https://old.example.edu/u/photo-scaled.jpg', 'verdict' => 'nolocal']);
        $this->row(['url' => 'https://old.example.edu/u/zeta.pdf', 'verdict' => 'nolocal']);
        $this->row(['url' => 'https://old.example.edu/u/alpha.PDF', 'verdict' => 'nolocal']);
        $this->row(['url' => 'https://old.example.edu/u/skip.pdf', 'verdict' => 'crashed']);
        $this->row(['url' => 'https://old.example.edu/u/other.pdf', 'verdict' => 'differs']);
        $this->row(['url' => 'https://old.example.edu/u/done.pdf', 'verdict' => 'nolocal', 'applied' => 1]);

        $job = UAMS_MRA_Repoint::start_sideload();

        $this->assertSame('sideload', $job['phase']);
        $this->assertSame(['https://old.example.edu/u/alpha.PDF', 'https://old.example.edu/u/zeta.pdf', 'https://old.example.edu/u/photo.jpg'], array_column($job['queue'], 'url'));
        $this->assertSame(3, $job['total']);
        $this->assertSame(0, $job['done']);
        $this->assertSame(0, $job['applied']);
        $this->assertSame(0, $job['failed']);
        $this->assertSame(0, $job['skipped']);
        $this->assertSame('Pulling files into this library', $job['message']);

        $photo = $job['queue'][2];
        $this->assertCount(3, $photo['hashes']);
        $this->assertEqualsCanonicalizing([
            md5('https://old.example.edu/u/photo-300x200.jpg'),
            md5('https://old.example.edu/u/photo.jpg'),
            md5('https://old.example.edu/u/photo-scaled.jpg'),
        ], $photo['hashes']);
        $this->assertSame([md5('https://old.example.edu/u/zeta.pdf')], $job['queue'][1]['hashes']);
        $this->assertSame('sideload', UAMS_MRA_Repoint::get_job()['phase']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function imageExtensions(): array
    {
        return array_combine(
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'JPG'],
            array_map(static fn (string $ext): array => [$ext], ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'JPG'])
        );
    }

    /**
     * @dataProvider imageExtensions
     */
    public function test_each_image_type_is_queued_after_a_document(string $ext): void
    {
        $this->row(['url' => 'https://old.example.edu/u/aaa.'.$ext, 'verdict' => 'nolocal']);
        $this->row(['url' => 'https://old.example.edu/u/zzz.pdf', 'verdict' => 'nolocal']);

        $urls = array_column(UAMS_MRA_Repoint::start_sideload()['queue'], 'url');

        $this->assertSame(['https://old.example.edu/u/zzz.pdf', 'https://old.example.edu/u/aaa.'.$ext], $urls);
    }

    public function test_files_of_one_kind_are_queued_in_name_order(): void
    {
        foreach (['b.pdf', 'c.pdf', 'a.pdf', 'b.jpg', 'a.jpg'] as $name) {
            $this->row(['url' => 'https://old.example.edu/u/'.$name, 'verdict' => 'nolocal']);
        }

        $urls = array_map(basename(...), array_column(UAMS_MRA_Repoint::start_sideload()['queue'], 'url'));

        $this->assertSame(['a.pdf', 'b.pdf', 'c.pdf', 'a.jpg', 'b.jpg'], $urls);
    }

    public function test_pulling_over_with_nothing_to_pull_queues_nothing(): void
    {
        $job = UAMS_MRA_Repoint::start_sideload();

        $this->assertSame([], $job['queue']);
        $this->assertSame(0, $job['total']);
    }

    // ----------------------------------------------------------------- step

    public function test_a_step_with_no_job_or_a_finished_job_changes_nothing(): void
    {
        $this->assertSame([], UAMS_MRA_Repoint::step());
        $this->assertFalse(get_option(UAMS_MRA_Repoint::STATE_OPTION));

        $this->putJob(['phase' => 'done', 'updated' => 5]);
        $this->assertSame(['phase' => 'done', 'updated' => 5], UAMS_MRA_Repoint::step());
        $this->assertSame(5, UAMS_MRA_Repoint::get_job()['updated']);

        $this->putJob(['updated' => 5]);
        $this->assertSame(['updated' => 5], UAMS_MRA_Repoint::step());
        $this->assertSame(5, UAMS_MRA_Repoint::get_job()['updated']);
    }

    public function test_a_step_runs_the_phase_the_job_is_in_and_stamps_the_result(): void
    {
        // Plan: nothing left to compare, so it finishes.
        $this->putJob(['phase' => 'plan', 'total' => 0, 'done' => 0]);
        $job = UAMS_MRA_Repoint::step();
        $this->assertSame('done', $job['phase']);
        $this->assertSame('Comparison finished', $job['message']);
        $this->assertEqualsWithDelta(time(), UAMS_MRA_Repoint::get_job()['updated'], 5);

        // Side-load: an empty queue finishes.
        $this->putJob(['phase' => 'sideload', 'queue' => []]);
        $this->assertSame('Nothing left to pull over', UAMS_MRA_Repoint::step()['message']);

        // Apply, and any phase it does not know, nothing left to rewrite.
        $this->putJob(['phase' => 'apply']);
        $this->assertSame('Rewriting finished', UAMS_MRA_Repoint::step()['message']);

        $this->putJob(['phase' => 'mystery']);
        $this->assertSame('Rewriting finished', UAMS_MRA_Repoint::step()['message']);
    }

    // ----------------------------------------------------------------- plan

    public function test_a_plan_batch_gives_each_url_a_verdict_and_stops_at_the_batch_limit(): void
    {
        $this->settings(['batch_repoint' => 2]);

        $urls = [];

        foreach (['a', 'b', 'c'] as $name) {
            $url = 'https://old.example.edu/u/'.$name.'.pdf';
            $urls[$name] = md5($url);
            $this->row(['url' => $url, 'base_norm' => $name.'.pdf']);
            $this->row(['url' => $url, 'base_norm' => $name.'.pdf', 'source_type' => 'excerpt']);
        }

        $job = UAMS_MRA_Repoint::start_plan();
        $this->assertSame(3, $job['total']);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('plan', $job['phase']);
        $this->assertSame(2, $job['done']);

        global $wpdb;
        $planned = $wpdb->get_col('SELECT DISTINCT url_hash FROM '.UAMS_MRA_Store::offsite_table()." WHERE verdict = 'nolocal' ORDER BY url_hash ASC");
        $all = $urls;
        sort($all);

        // The two lowest hashes went first, and each verdict reached every row sharing that hash.
        $this->assertSame(array_slice($all, 0, 2), $planned);
        $this->assertSame(4, (int) $wpdb->get_var('SELECT COUNT(*) FROM '.UAMS_MRA_Store::offsite_table()." WHERE verdict = 'nolocal'"));

        $job = UAMS_MRA_Repoint::step();
        $this->assertSame('plan', $job['phase']);
        $this->assertSame(3, $job['done']);

        $job = UAMS_MRA_Repoint::step();
        $this->assertSame('done', $job['phase']);
        $this->assertSame('Comparison finished', $job['message']);
        $this->assertSame(3, $job['done']);
    }

    public function test_a_plan_batch_leaves_applied_rows_and_rows_with_a_verdict_alone(): void
    {
        $applied = $this->row(['verdict' => '', 'applied' => 1]);
        $judged = $this->row(['url' => 'https://old.example.edu/u/j.pdf', 'base_norm' => 'j.pdf', 'verdict' => 'differs', 'local_id' => 3]);

        $this->putJob(['phase' => 'plan', 'total' => 0, 'done' => 0]);
        $this->assertSame('differs', $this->stored($judged)['verdict']);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('done', $job['phase']);
        $this->assertSame('', $this->stored($applied)['verdict']);
        $this->assertSame('differs', $this->stored($judged)['verdict']);
        $this->assertSame('3', $this->stored($judged)['local_id']);
    }

    public function test_a_plan_batch_stores_every_field_of_the_verdict(): void
    {
        $bytes = str_repeat('x', 40);
        $id = $this->attachment('2024/05/doc.pdf', $bytes);
        $url = 'https://old.example.edu/u/doc.pdf';
        $row = $this->row(['url' => $url, 'base_norm' => 'doc.pdf']);
        $this->http['HEAD'][$url] = [200, 40];

        UAMS_MRA_Repoint::start_plan();
        $this->putJob(['phase' => 'plan', 'total' => 1, 'done' => 0]);
        UAMS_MRA_Repoint::step();

        $stored = $this->stored($row);

        $this->assertSame('identical', $stored['verdict']);
        $this->assertSame((string) $id, $stored['local_id']);
        $this->assertSame('40', $stored['remote_bytes']);
        $this->assertSame('40', $stored['local_bytes']);
        $this->assertSame(wp_get_attachment_url($id), $stored['target_url']);
        $this->assertNotSame('0000-00-00 00:00:00', $stored['checked_at']);
        $this->assertEqualsWithDelta(strtotime(current_time('mysql')), strtotime($stored['checked_at']), 30);
    }

    public function test_a_plan_batch_tells_each_verdict_apart(): void
    {
        $this->attachment('2024/05/same.pdf', str_repeat('a', 10));
        $this->attachment('2024/05/diff.pdf', str_repeat('a', 10));
        $this->attachment('2024/05/dup.pdf', 'x');
        $this->attachment('2024/06/dup.pdf', 'y');
        $this->attachment('2024/05/gone.pdf', 'z');

        $rows = [];

        foreach (['same' => [200, 10], 'diff' => [200, 99], 'dup' => [200, 1], 'gone' => [404, null], 'none' => [200, 1]] as $name => $head) {
            $url = 'https://old.example.edu/u/'.$name.'.pdf';
            $rows[$name] = $this->row(['url' => $url, 'base_norm' => $name.'.pdf']);
            $this->http['HEAD'][$url] = $head;
        }

        $this->settings(['batch_repoint' => 25]);
        UAMS_MRA_Repoint::start_plan();
        UAMS_MRA_Repoint::step();

        $this->assertSame('identical', $this->stored($rows['same'])['verdict']);
        $this->assertSame('differs', $this->stored($rows['diff'])['verdict']);
        $this->assertSame('ambiguous', $this->stored($rows['dup'])['verdict']);
        $this->assertSame('unreachable', $this->stored($rows['gone'])['verdict']);
        $this->assertSame('nolocal', $this->stored($rows['none'])['verdict']);
    }

    // ----------------------------------------------------------------- apply

    public function test_an_apply_batch_rewrites_the_rows_in_order_up_to_the_limit_and_records_each_outcome(): void
    {
        $this->settings(['batch_repoint' => 3]);

        $ok = self::factory()->post->create(['post_content' => 'See '.self::OLD]);
        $gone = self::factory()->post->create(['post_content' => 'Nothing here']);

        $applied = $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'source_type' => 'content', 'source_pk' => $ok, 'source_id' => $ok]);
        $skipped = $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'source_type' => 'content', 'source_pk' => $gone, 'source_id' => $gone]);
        $failed = $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'source_type' => 'content', 'source_pk' => 999999, 'source_id' => 999999]);
        $later = $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'source_type' => 'content', 'source_pk' => $ok + 500, 'source_id' => 1]);
        $other = $this->row(['verdict' => 'differs', 'target_url' => self::NEW, 'source_type' => 'content', 'source_pk' => $ok + 601]);
        $blank = $this->row(['verdict' => 'identical', 'target_url' => '', 'source_type' => 'content', 'source_pk' => $ok + 602]);
        $closed = $this->row(['verdict' => 'identical', 'target_url' => self::NEW, 'source_type' => 'content', 'source_pk' => $ok + 603, 'applied' => 1]);

        $job = UAMS_MRA_Repoint::start_apply();
        $this->assertSame(4, $job['total']);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('apply', $job['phase']);
        $this->assertSame(3, $job['done']);
        $this->assertSame(1, $job['applied']);
        $this->assertSame(1, $job['failed']);
        $this->assertSame(1, $job['skipped']);

        $this->assertSame('1', $this->stored($applied)['applied']);
        $this->assertSame('2', $this->stored($skipped)['applied']);
        $this->assertSame('3', $this->stored($failed)['applied']);
        $this->assertSame('0', $this->stored($later)['applied']);
        $this->assertSame('0', $this->stored($other)['applied']);
        $this->assertSame('0', $this->stored($blank)['applied']);
        $this->assertSame('1', $this->stored($closed)['applied']);
        $this->assertSame('See '.self::NEW, get_post($ok)->post_content);

        $job = UAMS_MRA_Repoint::step();
        $this->assertSame('apply', $job['phase']);
        $this->assertSame(4, $job['done']);
        $this->assertSame(2, $job['failed']);

        $job = UAMS_MRA_Repoint::step();
        $this->assertSame('done', $job['phase']);
        $this->assertSame('Rewriting finished', $job['message']);
    }

    public function test_an_apply_batch_takes_resized_rows_only_when_the_job_says_so(): void
    {
        $post = self::factory()->post->create(['post_content' => 'See '.self::OLD]);
        $row = $this->row(['verdict' => 'resized', 'target_url' => self::NEW, 'source_pk' => $post, 'source_id' => $post]);

        $this->putJob(['phase' => 'apply', 'resized' => 0, 'done' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0]);
        $this->assertSame('done', UAMS_MRA_Repoint::step()['phase']);
        $this->assertSame('0', $this->stored($row)['applied']);

        $this->putJob(['phase' => 'apply', 'resized' => 1, 'done' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0]);
        $this->assertSame(1, UAMS_MRA_Repoint::step()['applied']);
        $this->assertSame('1', $this->stored($row)['applied']);
    }

    public function test_an_apply_batch_takes_side_loaded_rows_whatever_the_resized_setting(): void
    {
        $post = self::factory()->post->create(['post_content' => 'See '.self::OLD]);
        $row = $this->row(['verdict' => 'sideloaded', 'target_url' => self::NEW, 'source_pk' => $post, 'source_id' => $post]);

        $this->putJob(['phase' => 'apply', 'done' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0]);
        UAMS_MRA_Repoint::step();

        $this->assertSame('1', $this->stored($row)['applied']);
    }

    // ----------------------------------------------------------------- rewrite_row

    public function test_a_revision_and_its_meta_are_skipped_and_never_touched(): void
    {
        foreach (['revision', 'revision_meta'] as $type) {
            $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => $type, 'source_pk' => 1]));
        }
    }

    public function test_a_row_with_no_target_or_the_same_target_is_skipped(): void
    {
        $post = self::factory()->post->create(['post_content' => 'See '.self::OLD]);

        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => 'content', 'source_pk' => $post, 'target_url' => '']));
        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => 'content', 'source_pk' => $post, 'target_url' => self::OLD]));
        $this->assertSame('See '.self::OLD, get_post($post)->post_content);
    }

    public function test_a_row_of_a_type_it_does_not_know_is_skipped(): void
    {
        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => 'mystery', 'source_pk' => 1]));
        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => '', 'source_pk' => 1]));
    }

    public function test_a_post_is_rewritten_in_its_content_and_its_excerpt(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Body '.self::OLD, 'post_excerpt' => 'Short '.self::OLD]);

        $this->assertSame(['status' => 'applied', 'applied' => 1], $this->rewrite(['source_type' => 'content', 'source_pk' => $post]));

        $this->assertSame('Body '.self::NEW, get_post($post)->post_content);
        $this->assertSame('Short '.self::NEW, get_post($post)->post_excerpt);
    }

    public function test_a_post_with_the_url_only_in_its_excerpt_or_only_in_its_content_is_still_rewritten(): void
    {
        $excerpt = self::factory()->post->create(['post_content' => 'Plain', 'post_excerpt' => 'Short '.self::OLD]);
        $content = self::factory()->post->create(['post_content' => 'Body '.self::OLD, 'post_excerpt' => 'Plain']);

        $this->assertSame('applied', $this->rewrite(['source_type' => 'content', 'source_pk' => $excerpt])['status']);
        $this->assertSame('applied', $this->rewrite(['source_type' => 'content', 'source_pk' => $content])['status']);

        $this->assertSame('Short '.self::NEW, get_post($excerpt)->post_excerpt);
        $this->assertSame('Plain', get_post($excerpt)->post_content);
        $this->assertSame('Body '.self::NEW, get_post($content)->post_content);
        $this->assertSame('Plain', get_post($content)->post_excerpt);
    }

    public function test_a_post_that_no_longer_holds_the_url_is_skipped_and_left_as_it_is(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Edited since the scan', 'post_excerpt' => 'Plain']);
        $before = get_post($post)->post_modified;

        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => 'content', 'source_pk' => $post]));
        $this->assertSame($before, get_post($post)->post_modified);
        $this->assertSame([], wp_get_post_revisions($post));
    }

    public function test_a_post_that_does_not_exist_fails(): void
    {
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'content', 'source_pk' => 987654]));
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'content', 'source_pk' => 0]));
    }

    public function test_a_page_is_refused_when_the_user_may_not_save_unfiltered_html(): void
    {
        $post = self::factory()->post->create(['post_content' => 'See '.self::OLD]);
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'content', 'source_pk' => $post]));
        $this->assertSame('See '.self::OLD, get_post($post)->post_content);
    }

    public function test_a_post_whose_save_core_refuses_fails(): void
    {
        $post = self::factory()->post->create(['post_content' => 'See '.self::OLD]);

        add_filter('wp_insert_post_empty_content', '__return_true');

        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'content', 'source_pk' => $post]));
        $this->assertSame('See '.self::OLD, get_post($post)->post_content);
    }

    public function test_an_option_is_rewritten_unserialized_and_saved_once(): void
    {
        update_option('uams_mra_jobs_option', ['logo' => self::OLD, 'n' => 3]);

        $this->assertSame(['status' => 'applied', 'applied' => 1], $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_option']));
        $this->assertSame(['logo' => self::NEW, 'n' => 3], get_option('uams_mra_jobs_option'));

        update_option('uams_mra_jobs_text', 'Link '.self::OLD);
        $this->assertSame('applied', $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_text'])['status']);
        $this->assertSame('Link '.self::NEW, get_option('uams_mra_jobs_text'));
    }

    public function test_an_option_with_no_name_fails(): void
    {
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'option', 'source_key' => '']));
    }

    public function test_an_option_that_does_not_hold_the_url_is_skipped_and_one_that_hides_it_fails(): void
    {
        update_option('uams_mra_jobs_plain', ['logo' => 'https://elsewhere.example/a.jpg']);
        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_plain']));

        update_option('uams_mra_jobs_hidden', new UamsMraJobsPrivateHolder(self::OLD));
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_hidden']));
        $this->assertSame(self::OLD, get_option('uams_mra_jobs_hidden')->url());

        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_missing']));
    }

    public function test_an_option_with_a_readable_and_an_unreadable_copy_is_not_half_written(): void
    {
        $holder = new \stdClass;
        $holder->one = self::OLD;
        $holder->two = new UamsMraJobsPrivateHolder(self::OLD);
        update_option('uams_mra_jobs_half', $holder);

        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_half']));
        $this->assertSame(self::OLD, get_option('uams_mra_jobs_half')->two->url());
    }

    public function test_an_option_that_core_will_not_save_fails(): void
    {
        update_option('uams_mra_jobs_locked', 'Link '.self::OLD);

        add_filter('pre_update_option_uams_mra_jobs_locked', static fn ($value, $old) => $old, 10, 2);

        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'option', 'source_key' => 'uams_mra_jobs_locked']));
        $this->assertSame('Link '.self::OLD, get_option('uams_mra_jobs_locked'));
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function metaTypes(): array
    {
        global $wpdb;

        return [
            'postmeta' => ['postmeta', $wpdb->postmeta, 'meta_id', 'post_id', 'post_meta'],
            'termmeta' => ['termmeta', $wpdb->termmeta, 'meta_id', 'term_id', 'term_meta'],
            'usermeta' => ['usermeta', $wpdb->usermeta, 'umeta_id', 'user_id', 'user_meta'],
            'commentmeta' => ['commentmeta', $wpdb->commentmeta, 'meta_id', 'comment_id', 'comment_meta'],
        ];
    }

    /**
     * @dataProvider metaTypes
     */
    public function test_a_meta_row_is_rewritten_in_place_and_its_cache_dropped(string $type, string $table, string $pk, string $owner, string $group): void
    {
        $id = 4242;
        $key = (string) $id;
        $meta_id = $this->metaRow($table, $owner, $id, serialize(['url' => self::OLD, 'id' => 7]));
        $other = $this->metaRow($table, $owner, $id + 1, 'untouched '.self::OLD);

        wp_cache_set($key, ['stale' => ['x']], $group);

        $this->assertSame(['status' => 'applied', 'applied' => 1], $this->rewrite(['source_type' => $type, 'source_pk' => $meta_id, 'source_id' => $id]));

        $this->assertSame(['url' => self::NEW, 'id' => 7], unserialize((string) $this->metaValue($table, $pk, $meta_id)));
        $this->assertSame('untouched '.self::OLD, $this->metaValue($table, $pk, $other));
        $this->assertFalse(wp_cache_get($key, $group));
    }

    /**
     * @dataProvider metaTypes
     */
    public function test_a_meta_row_that_is_gone_fails(string $type): void
    {
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => $type, 'source_pk' => 987654321, 'source_id' => 1]));
    }

    /**
     * @dataProvider metaTypes
     */
    public function test_a_meta_row_with_no_url_in_it_is_skipped(string $type, string $table, string $pk, string $owner): void
    {
        $meta_id = $this->metaRow($table, $owner, 4243, 'nothing to see');

        $this->assertSame(['status' => 'skipped', 'applied' => 2], $this->rewrite(['source_type' => $type, 'source_pk' => $meta_id, 'source_id' => 4243]));
        $this->assertSame('nothing to see', $this->metaValue($table, $pk, $meta_id));
    }

    public function test_a_meta_row_that_will_not_save_fails_and_keeps_its_cache(): void
    {
        global $wpdb;

        $meta_id = $this->metaRow($wpdb->postmeta, 'post_id', 4244, 'Link '.self::OLD);
        wp_cache_set('4244', ['stale' => ['x']], 'post_meta');
        $wpdb->suppress_errors(true);

        add_filter('query', static fn (string $sql): string => str_starts_with($sql, 'UPDATE') ? 'SELECT * FROM table_that_is_not_there_uams_mra' : $sql);

        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => 4244]));
        $this->assertSame(['stale' => ['x']], wp_cache_get('4244', 'post_meta'));
        $this->assertSame('Link '.self::OLD, $this->metaValue($wpdb->postmeta, 'meta_id', $meta_id));
    }

    public function test_a_serialized_meta_value_with_a_hidden_copy_is_not_half_written(): void
    {
        global $wpdb;

        $private = serialize(['one' => self::OLD, 'two' => new UamsMraJobsPrivateHolder(self::OLD)]);
        $meta_id = $this->metaRow($wpdb->postmeta, 'post_id', 4245, $private);

        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => 4245]));
        $this->assertSame($private, $this->metaValue($wpdb->postmeta, 'meta_id', $meta_id));
    }

    public function test_a_meta_value_that_cannot_be_read_and_one_that_hides_the_url_fail(): void
    {
        global $wpdb;

        $corrupt = 'a:1:{s:3:"url";s:5:"'.self::OLD.'";}';
        $broken = $this->metaRow($wpdb->postmeta, 'post_id', 4246, $corrupt);
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $broken, 'source_id' => 4246]));
        $this->assertSame($corrupt, $this->metaValue($wpdb->postmeta, 'meta_id', $broken));

        $hidden = serialize(new UamsMraJobsPrivateHolder(self::OLD));
        $private = $this->metaRow($wpdb->postmeta, 'post_id', 4247, $hidden);
        $this->assertSame(['status' => 'failed', 'applied' => 3], $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $private, 'source_id' => 4247]));
        $this->assertSame($hidden, $this->metaValue($wpdb->postmeta, 'meta_id', $private));
    }

    public function test_the_meta_tables_are_found_by_type_and_unknown_types_have_none(): void
    {
        global $wpdb;

        $this->assertSame(['table' => $wpdb->postmeta, 'pk' => 'meta_id', 'cache' => 'post_meta'], $this->call('meta_target', 'postmeta'));
        $this->assertSame(['table' => $wpdb->termmeta, 'pk' => 'meta_id', 'cache' => 'term_meta'], $this->call('meta_target', 'termmeta'));
        $this->assertSame(['table' => $wpdb->usermeta, 'pk' => 'umeta_id', 'cache' => 'user_meta'], $this->call('meta_target', 'usermeta'));
        $this->assertSame(['table' => $wpdb->commentmeta, 'pk' => 'meta_id', 'cache' => 'comment_meta'], $this->call('meta_target', 'commentmeta'));

        foreach (['', 'content', 'option', 'POSTMETA', 'post_meta'] as $type) {
            $this->assertNull($this->call('meta_target', $type), $type);
        }
    }

    // ----------------------------------------------------------------- mark and point

    public function test_marking_hashes_writes_the_verdict_on_every_unapplied_row_sharing_them(): void
    {
        $a = $this->row(['url' => 'https://old.example.edu/u/a.pdf']);
        $a2 = $this->row(['url' => 'https://old.example.edu/u/a.pdf', 'source_type' => 'excerpt']);
        $b = $this->row(['url' => 'https://old.example.edu/u/b.pdf']);
        $c = $this->row(['url' => 'https://old.example.edu/u/c.pdf']);
        $done = $this->row(['url' => 'https://old.example.edu/u/a.pdf', 'source_type' => 'option', 'applied' => 1]);

        $this->call('mark_hashes', [md5('https://old.example.edu/u/a.pdf'), md5('https://old.example.edu/u/b.pdf')], 'sideloaded', '77', 'https://new.example.edu/a.pdf');

        foreach ([$a, $a2, $b] as $id) {
            $row = $this->stored($id);
            $this->assertSame('sideloaded', $row['verdict']);
            $this->assertSame('77', $row['local_id']);
            $this->assertSame('https://new.example.edu/a.pdf', $row['target_url']);
            $this->assertNotSame('0000-00-00 00:00:00', $row['checked_at']);
        }

        $this->assertSame('', $this->stored($c)['verdict']);
        $this->assertSame('', $this->stored($done)['verdict']);
    }

    public function test_marking_no_hashes_changes_nothing(): void
    {
        $row = $this->row(['verdict' => 'differs']);

        $this->call('mark_hashes', [], 'crashed', 0, '');

        $this->assertSame('differs', $this->stored($row)['verdict']);
    }

    public function test_marking_more_than_a_hundred_hashes_reaches_them_all(): void
    {
        $ids = [];
        $hashes = [];

        for ($i = 0; $i < 101; $i++) {
            $url = 'https://old.example.edu/u/many-'.$i.'.pdf';
            $ids[] = $this->row(['url' => $url]);
            $hashes[] = md5($url);
        }

        $this->call('mark_hashes', $hashes, 'toobig', 0, '');

        foreach ([$ids[0], $ids[99], $ids[100]] as $id) {
            $this->assertSame('toobig', $this->stored($id)['verdict']);
        }
    }

    public function test_a_group_is_pointed_at_the_attachment_and_a_sized_url_at_its_matching_size(): void
    {
        $id = self::factory()->attachment->create_upload_object($this->writeJpeg('2024/05/grp.jpg', 800, 600));
        $sizes = wp_get_attachment_metadata($id)['sizes'];

        $full = 'https://old.example.edu/u/grp.jpg';
        $thumb = 'https://old.example.edu/u/grp-150x150.jpg';
        $odd = 'https://old.example.edu/u/grp-77x55.jpg';
        $medium = 'https://old.example.edu/u/grp-300x225.jpg';
        $d = $this->row(['url' => $medium]);
        $a = $this->row(['url' => $full]);
        $b = $this->row(['url' => $thumb]);
        $c = $this->row(['url' => $odd]);

        $this->call('point_group_at', [md5($full), md5($thumb), md5($odd), md5($medium)], $id);

        $this->assertSame('sideloaded', $this->stored($a)['verdict']);
        $this->assertSame((string) $id, $this->stored($a)['local_id']);
        $this->assertSame(wp_get_attachment_url($id), $this->stored($a)['target_url']);
        $this->assertSame(dirname((string) wp_get_attachment_url($id)).'/'.$sizes['thumbnail']['file'], $this->stored($b)['target_url']);
        $this->assertSame(wp_get_attachment_url($id), $this->stored($c)['target_url']);
        $this->assertSame(dirname((string) wp_get_attachment_url($id)).'/'.$sizes['medium']['file'], $this->stored($d)['target_url']);
    }

    public function test_a_hash_with_no_row_is_pointed_at_the_full_size(): void
    {
        $id = self::factory()->attachment->create_upload_object($this->writeJpeg('2024/05/solo.jpg', 200, 100));

        $this->call('point_group_at', [md5('https://old.example.edu/u/nothing.jpg')], $id);

        global $wpdb;
        $this->assertSame('0', (string) $wpdb->get_var('SELECT COUNT(*) FROM '.UAMS_MRA_Store::offsite_table()));
    }

    /**
     * Write a JPEG to a scratch file and return its path.
     */
    private function writeJpeg(string $name, int $width, int $height): string
    {
        $path = sys_get_temp_dir().'/uams-mra-'.bin2hex(random_bytes(3)).'-'.basename($name);
        file_put_contents($path, $this->jpeg($width, $height));
        $this->files[] = $path;

        return $path;
    }

    public function test_the_parent_of_a_pulled_file_is_the_first_post_that_shows_it(): void
    {
        $url = 'https://old.example.edu/u/lonely-300x200.jpg';
        $found = [$this->call('sideload_parent', $url)];

        $this->row(['url' => $url, 'base_norm' => 'lonely.jpg', 'source_type' => 'option', 'source_id' => 3]);
        $found[] = $this->call('sideload_parent', $url);

        $this->row(['url' => $url, 'base_norm' => 'other.jpg', 'source_type' => 'content', 'source_id' => 2]);
        $found[] = $this->call('sideload_parent', $url);

        $this->row(['url' => $url, 'base_norm' => 'lonely.jpg', 'source_type' => 'content', 'source_id' => 40]);
        $this->row(['url' => $url, 'base_norm' => 'lonely.jpg', 'source_type' => 'content', 'source_id' => 12]);
        $found[] = $this->call('sideload_parent', $url);

        $this->assertSame([0, 0, 0, 12], $found);
    }

    // ----------------------------------------------------------------- side-load

    /**
     * A queued group for one URL, with its rows stored.
     *
     * @return array{url: string, hashes: list<string>}
     */
    private function group(string $url, array $over = []): array
    {
        $this->row(array_merge(['url' => $url, 'base_norm' => basename($url), 'verdict' => 'nolocal'], $over));

        return ['url' => $url, 'hashes' => [md5($url)]];
    }

    /**
     * Store a side-load job over the groups.
     *
     * @param  list<array{url: string, hashes: list<string>}>  $queue
     */
    private function sideloadJob(array $queue): void
    {
        $this->putJob(['phase' => 'sideload', 'queue' => $queue, 'total' => count($queue), 'done' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0]);
    }

    public function test_a_pulled_file_becomes_an_attachment_and_its_rows_are_pointed_at_it(): void
    {
        $url = 'https://old.example.edu/u/pulled.jpg';
        $group = $this->group($url, ['source_type' => 'content', 'source_id' => 21]);
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, $this->jpeg()];
        $this->sideloadJob([$group]);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('done', $job['phase']);
        $this->assertSame('Finished pulling files over', $job['message']);
        $this->assertSame(1, $job['done']);
        $this->assertSame(1, $job['applied']);
        $this->assertSame(0, $job['failed']);

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM '.UAMS_MRA_Store::offsite_table()." WHERE url_hash = '".md5($url)."'", ARRAY_A);
        $attachment = (int) $row['local_id'];

        $this->assertSame('sideloaded', $row['verdict']);
        $this->assertGreaterThan(0, $attachment);
        $this->assertSame((string) wp_get_attachment_url($attachment), $row['target_url']);
        $this->assertSame('inherit', get_post($attachment)->post_status);
        $this->assertSame('image/jpeg', get_post($attachment)->post_mime_type);
        $this->assertSame('pulled', get_post($attachment)->post_title);
        $this->assertSame(21, (int) get_post($attachment)->post_parent);
        $this->assertSame(400, wp_get_attachment_metadata($attachment)['width']);
        $this->assertNotSame([], wp_get_attachment_metadata($attachment)['sizes']);
        $this->assertFileExists((string) get_attached_file($attachment));
        $this->files[] = (string) get_attached_file($attachment);
    }

    public function test_the_file_is_claimed_before_it_is_fetched(): void
    {
        $url = 'https://old.example.edu/u/claimed.jpg';
        $second = 'https://old.example.edu/u/second.jpg';
        $group = $this->group($url);
        $this->group($second);
        $seen = [];

        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = function () use ($url, &$seen): array {
            global $wpdb;
            $seen['verdict'] = $wpdb->get_var('SELECT verdict FROM '.UAMS_MRA_Store::offsite_table()." WHERE url_hash = '".md5($url)."'");
            $seen['local'] = $wpdb->get_var('SELECT local_id FROM '.UAMS_MRA_Store::offsite_table()." WHERE url_hash = '".md5($url)."'");
            $seen['target'] = (string) $wpdb->get_var('SELECT target_url FROM '.UAMS_MRA_Store::offsite_table()." WHERE url_hash = '".md5($url)."'");
            $seen['queue'] = array_column(UAMS_MRA_Repoint::get_job()['queue'], 'url');

            return [200, $this->jpeg(50, 50)];
        };
        $this->sideloadJob([$group, ['url' => $second, 'hashes' => [md5($second)]]]);

        UAMS_MRA_Repoint::step();

        $this->assertSame('crashed', $seen['verdict']);
        $this->assertSame('0', $seen['local']);
        $this->assertSame('', $seen['target']);
        $this->assertSame([$second], $seen['queue']);
    }

    /**
     * @return array<string, array{string, mixed, mixed, string}>
     */
    public static function failedDownloads(): array
    {
        return [
            'a server error' => ['failing.jpg', [200, null], [500, 'no'], 'fetchfailed'],
            'a missing file' => ['missing.jpg', [200, null], [404, 'no'], 'fetchfailed'],
            'an unreachable host' => ['unreachable.jpg', [200, null], 'error', 'fetchfailed'],
            'a size the head reports over the limit' => ['huge.jpg', [200, 3 * 1048576], [200, 'never fetched'], 'toobig'],
            'a body over the limit with no length given' => ['stuffed.jpg', [200, null], [200, 'over'], 'toobig'],
            'a type this site does not allow' => ['script.exe', [200, null], [200, 'MZ'], 'rejected'],
        ];
    }

    /**
     * @dataProvider failedDownloads
     */
    public function test_a_file_that_cannot_be_pulled_is_marked_with_why_and_leaves_nothing_behind(string $name, array $head, mixed $get, string $verdict): void
    {
        $url = 'https://old.example.edu/u/'.$name;
        $group = $this->group($url);
        $this->settings(['sideload_max_mb' => 1]);
        $this->http['HEAD'][$url] = $head;
        $this->http['GET'][$url] = $get === 'error' ? new WP_Error('http_request_failed', 'no route') : ($get[1] === 'over' ? [200, str_repeat('a', 1048577)] : $get);
        $this->sideloadJob([$group]);

        $tmp_before = glob(sys_get_temp_dir().'/*.tmp') ?: [];
        $job = UAMS_MRA_Repoint::step();

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM '.UAMS_MRA_Store::offsite_table()." WHERE url_hash = '".md5($url)."'", ARRAY_A);

        $this->assertSame($verdict, $row['verdict']);
        $this->assertSame('0', $row['local_id']);
        $this->assertSame('', $row['target_url']);
        $this->assertSame(1, $job['failed']);
        $this->assertSame(0, $job['applied']);
        $this->assertSame(1, $job['done']);
        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir().'/*.tmp') ?: [], $tmp_before)));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = '".pathinfo($name, PATHINFO_FILENAME)."'"));
    }

    public function test_a_file_over_the_limit_by_its_head_is_never_downloaded(): void
    {
        $url = 'https://old.example.edu/u/big.jpg';
        $group = $this->group($url);
        $this->settings(['sideload_max_mb' => 2]);
        $this->http['HEAD'][$url] = [200, 2 * 1048576 + 1];
        $this->sideloadJob([$group]);

        UAMS_MRA_Repoint::step();

        $this->assertSame([['HEAD', $url]], array_map(static fn (array $r): array => [$r[0], $r[1]], $this->requests));
    }

    public function test_a_file_exactly_at_the_limit_is_accepted(): void
    {
        $url = 'https://old.example.edu/u/exact.jpg';
        $group = $this->group($url);
        $body = $this->jpeg(60, 60);
        $this->settings(['sideload_max_mb' => 1]);
        $this->http['HEAD'][$url] = [200, 1048576];
        $this->http['GET'][$url] = [200, $body];
        $this->sideloadJob([$group]);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame(1, $job['applied']);
        $get = array_values(array_filter($this->requests, static fn (array $r): bool => $r[0] === 'GET'))[0][2];
        $this->assertTrue($get['stream']);
        $this->assertSame(1048577, $get['limit_response_size']);
        $this->assertNotEmpty($get['filename']);
        $this->assertGreaterThanOrEqual(10, $get['timeout']);
        $this->files[] = (string) get_attached_file((int) $this->stored($this->firstRowId($url))['local_id']);
    }

    private function firstRowId(string $url): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT offsite_id FROM '.UAMS_MRA_Store::offsite_table()." WHERE url_hash = '".md5($url)."' LIMIT 1");
    }

    public function test_the_limit_comes_from_the_setting_and_is_at_least_one_megabyte(): void
    {
        $url = 'https://old.example.edu/u/two-meg.jpg';
        $group = $this->group($url);
        $this->settings(['sideload_max_mb' => 0]);
        $this->http['HEAD'][$url] = [200, 1048577];
        $this->sideloadJob([$group]);

        UAMS_MRA_Repoint::step();

        $this->assertSame('toobig', $this->stored($this->firstRowId($url))['verdict']);
    }

    public function test_a_download_that_throws_is_marked_as_failed_and_the_rest_of_the_queue_goes_on(): void
    {
        $bad = 'https://old.example.edu/u/aa-bad.pdf';
        $good = 'https://old.example.edu/u/bb-good.pdf';
        $this->settings(['batch_sideload' => 2]);
        $first = $this->group($bad);
        $second = $this->group($good);
        $this->http['HEAD'][$bad] = [200, null];
        $this->http['GET'][$bad] = static function (): never {
            throw new RuntimeException('exploded');
        };
        $this->http['HEAD'][$good] = [200, null];
        $this->http['GET'][$good] = [200, '%PDF-1.4 small'];
        $this->sideloadJob([$first, $second]);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('fetchfailed', $this->stored($this->firstRowId($bad))['verdict']);
        $this->assertSame(1, $job['failed']);
        $this->assertSame(1, $job['applied']);
        $this->assertSame(2, $job['done']);
        $this->assertSame('sideloaded', $this->stored($this->firstRowId($good))['verdict']);
        $this->files[] = (string) get_attached_file((int) $this->stored($this->firstRowId($good))['local_id']);
    }

    public function test_a_side_load_batch_pulls_the_number_of_files_the_setting_allows(): void
    {
        $queue = [];

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $url = 'https://old.example.edu/u/'.$name.'.pdf';
            $queue[] = $this->group($url);
            $this->http['HEAD'][$url] = [200, null];
            $this->http['GET'][$url] = [404, 'no'];
        }

        foreach ([[null, 1], [0, 1], [2, 2], [3, 3], [50, 4]] as [$setting, $handled]) {
            UAMS_MRA_Store::reset();
            $queue = array_map(fn (array $g): array => $this->group($g['url']), $queue);
            $this->settings($setting === null ? [] : ['batch_sideload' => $setting]);
            $this->sideloadJob($queue);

            $job = UAMS_MRA_Repoint::step();

            $this->assertSame($handled, $job['done'], 'setting '.var_export($setting, true));
            $this->assertCount(4 - $handled, $job['queue']);
            $this->assertSame($handled === 4 ? 'done' : 'sideload', $job['phase']);
        }
    }

    public function test_a_side_load_batch_stops_early_when_its_time_budget_is_spent(): void
    {
        $queue = [];

        foreach (['a', 'b', 'c'] as $name) {
            $url = 'https://old.example.edu/u/'.$name.'.pdf';
            $queue[] = $this->group($url);
            $this->http['HEAD'][$url] = [200, null];
            $this->http['GET'][$url] = [404, 'no'];
        }

        $this->settings(['batch_sideload' => 10]);
        add_filter('uams_mra_repoint_time_budget', static fn (): int => -1);
        $this->sideloadJob($queue);

        $job = UAMS_MRA_Repoint::step();

        // The first file is always taken; with no time left the rest wait for the next batch.
        $this->assertSame(1, $job['done']);
        $this->assertCount(2, $job['queue']);
        $this->assertSame('sideload', $job['phase']);
        $this->assertSame('sideload: https://old.example.edu/u/b.pdf', UAMS_MRA_Repoint::next_target());
    }

    public function test_the_download_timeout_is_what_is_left_of_the_budget_and_never_under_ten_seconds(): void
    {
        $url = 'https://old.example.edu/u/timed.pdf';
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [404, 'no'];

        add_filter('uams_mra_repoint_time_budget', static fn (): int => 100);
        $group = $this->group($url);
        $this->sideloadJob([$group]);
        UAMS_MRA_Repoint::step();
        $timeout = array_values(array_filter($this->requests, static fn (array $r): bool => $r[0] === 'GET'))[0][2]['timeout'];
        $this->assertGreaterThanOrEqual(90, $timeout);
        $this->assertLessThan(100, $timeout);

        remove_all_filters('uams_mra_repoint_time_budget');
        add_filter('uams_mra_repoint_time_budget', static fn (): int => 5);
        $this->requests = [];
        $this->sideloadJob([$group]);
        UAMS_MRA_Repoint::step();
        $this->assertSame(10, array_values(array_filter($this->requests, static fn (array $r): bool => $r[0] === 'GET'))[0][2]['timeout']);
    }

    public function test_an_empty_queue_finishes_the_job(): void
    {
        $this->sideloadJob([]);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('done', $job['phase']);
        $this->assertSame('Nothing left to pull over', $job['message']);
    }

    public function test_a_pulled_file_that_is_not_an_image_gets_generated_metadata(): void
    {
        $url = 'https://old.example.edu/u/notes.pdf';
        $group = $this->group($url, ['source_type' => 'option']);
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, '%PDF-1.4 small'];
        $this->sideloadJob([$group]);

        $job = UAMS_MRA_Repoint::step();
        $attachment = (int) $this->stored($this->firstRowId($url))['local_id'];
        $this->files[] = (string) get_attached_file($attachment);

        $this->assertSame(1, $job['applied']);
        $this->assertSame('application/pdf', get_post($attachment)->post_mime_type);
        $this->assertSame('notes', get_post($attachment)->post_title);
        $this->assertSame(0, (int) get_post($attachment)->post_parent);
    }

    /**
     * The last GET the stand-in saw.
     *
     * @return array{string, string, array<string, mixed>}
     */
    private function lastGet(): array
    {
        $gets = array_values(array_filter($this->requests, static fn (array $r): bool => $r[0] === 'GET'));

        return $gets[count($gets) - 1];
    }

    /**
     * A JPEG whose header claims a very large picture, so there is no memory to resize it in.
     */
    private function hugeJpeg(): string
    {
        $bytes = $this->jpeg(40, 30);
        $at = strpos($bytes, "\xFF\xC0");
        $this->assertIsInt($at);

        return substr($bytes, 0, $at + 5).pack('nn', 20000, 30000).substr($bytes, $at + 9);
    }

    public function test_a_fatal_is_kept_only_for_the_error_types_that_end_a_request(): void
    {
        foreach ([E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR] as $type) {
            $record = UAMS_MRA_Repoint::fatal_record(['type' => $type, 'message' => 'boom', 'file' => '/a.php', 'line' => '12'], 'while testing');

            $this->assertIsArray($record, 'type '.$type);
            $this->assertSame('boom', $record['message']);
            $this->assertSame('/a.php', $record['file']);
            $this->assertSame(12, $record['line']);
            $this->assertSame('while testing', $record['context']);
            $this->assertEqualsWithDelta(time(), $record['when'], 5);
        }

        foreach ([E_WARNING, E_NOTICE, E_DEPRECATED, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED] as $type) {
            $this->assertNull(UAMS_MRA_Repoint::fatal_record(['type' => $type, 'message' => 'm', 'file' => 'f', 'line' => 1], ''), 'type '.$type);
        }

        $this->assertNull(UAMS_MRA_Repoint::fatal_record(null, ''));
        $this->assertNull(UAMS_MRA_Repoint::fatal_record([], ''));
    }

    public function test_the_next_target_skips_a_head_entry_with_no_url_rather_than_reading_the_next(): void
    {
        $this->putJob(['phase' => 'sideload', 'queue' => [['hashes' => []], ['url' => 'https://o.example.edu/b.pdf']]]);

        $this->assertSame('sideload', UAMS_MRA_Repoint::next_target());
    }

    public function test_a_job_with_no_usable_timestamp_is_not_running_however_long_the_timeout(): void
    {
        add_filter('uams_mra_repoint_stall_timeout', static fn (): int => PHP_INT_MAX);

        $this->putJob(['phase' => 'apply']);
        $missing = UAMS_MRA_Repoint::is_running();

        $this->putJob(['phase' => 'apply', 'updated' => 0]);
        $zero = UAMS_MRA_Repoint::is_running();

        $this->assertSame([false, false], [$missing, $zero]);
    }

    public function test_a_side_load_batch_never_takes_more_than_ten_files(): void
    {
        $queue = [];

        for ($i = 0; $i < 11; $i++) {
            $url = 'https://old.example.edu/u/f'.$i.'.pdf';
            $queue[] = $this->group($url);
            $this->http['HEAD'][$url] = [200, null];
            $this->http['GET'][$url] = [404, 'no'];
        }

        $this->settings(['batch_sideload' => 50]);
        $this->sideloadJob($queue);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame(10, $job['done']);
        $this->assertCount(1, $job['queue']);
    }

    public function test_the_size_limit_is_never_under_one_megabyte_and_otherwise_follows_the_setting(): void
    {
        $small = $this->jpeg(40, 40);
        $exact = 'https://old.example.edu/u/exactly-one.jpg';
        $big = 'https://old.example.edu/u/three-meg.jpg';

        $this->settings(['sideload_max_mb' => 0]);
        $this->http['HEAD'][$exact] = [200, 1048576];
        $this->http['GET'][$exact] = [200, $small];
        $this->sideloadJob([$this->group($exact)]);
        $this->assertSame(1, UAMS_MRA_Repoint::step()['applied']);
        $this->files[] = (string) get_attached_file((int) $this->stored($this->firstRowId($exact))['local_id']);

        $this->settings(['sideload_max_mb' => 5]);
        $this->http['HEAD'][$big] = [200, 3 * 1048576];
        $this->http['GET'][$big] = [200, $small];
        $this->sideloadJob([$this->group($big)]);
        $this->assertSame(1, UAMS_MRA_Repoint::step()['applied']);
        $this->files[] = (string) get_attached_file((int) $this->stored($this->firstRowId($big))['local_id']);
    }

    public function test_core_refusing_the_attachment_marks_the_file_rejected_and_removes_the_copy(): void
    {
        $url = 'https://old.example.edu/u/refused.pdf';
        $group = $this->group($url);
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, '%PDF-1.4 small'];
        $this->sideloadJob([$group]);

        $stored = [];
        add_filter('wp_handle_upload', static function (array $upload) use (&$stored): array {
            $stored[] = $upload['file'];

            return $upload;
        });
        add_filter('wp_insert_post_empty_content', '__return_true');

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame('rejected', $this->stored($this->firstRowId($url))['verdict']);
        $this->assertSame(1, $job['failed']);
        $this->assertCount(1, $stored);
        $this->assertFileDoesNotExist($stored[0]);
    }

    public function test_a_picture_too_large_to_resize_is_kept_at_full_size_with_its_dimensions(): void
    {
        $url = 'https://old.example.edu/u/vast.jpg';
        $group = $this->group($url);
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, $this->hugeJpeg()];
        $this->sideloadJob([$group]);

        $job = UAMS_MRA_Repoint::step();

        $this->assertSame(1, $job['applied']);
        $id = (int) $this->stored($this->firstRowId($url))['local_id'];
        $this->files[] = (string) get_attached_file($id);
        $meta = wp_get_attachment_metadata($id);

        $this->assertSame(30000, $meta['width']);
        $this->assertSame(20000, $meta['height']);
        $this->assertSame([], $meta['sizes']);
        $this->assertSame(_wp_relative_upload_path((string) get_attached_file($id)), $meta['file']);
    }

    public function test_a_pulled_file_that_is_not_an_image_has_its_metadata_generated(): void
    {
        $url = 'https://old.example.edu/u/meta.pdf';
        // Nothing else may generate the metadata, so only the call under test can.
        remove_all_actions('add_attachment');
        remove_all_actions('edit_attachment');
        $group = $this->group($url, ['source_type' => 'option']);
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, '%PDF-1.4 small'];
        $this->sideloadJob([$group]);

        UAMS_MRA_Repoint::step();

        $id = (int) $this->stored($this->firstRowId($url))['local_id'];
        $this->files[] = (string) get_attached_file($id);

        $this->assertArrayHasKey('filesize', wp_get_attachment_metadata($id));
    }

    public function test_the_memory_limit_is_raised_before_a_file_is_pulled(): void
    {
        $url = 'https://old.example.edu/u/memory.pdf';
        $group = $this->group($url);
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, '%PDF-1.4 small'];
        $this->sideloadJob([$group]);

        $asked = 0;
        add_filter('image_memory_limit', static function ($limit) use (&$asked) {
            $asked++;

            return $limit;
        });

        $before = ini_get('memory_limit');
        ini_set('memory_limit', '100M');

        try {
            UAMS_MRA_Repoint::step();
            $after = ini_get('memory_limit');
        } finally {
            ini_set('memory_limit', (string) $before);
        }

        $this->files[] = (string) get_attached_file((int) $this->stored($this->firstRowId($url))['local_id']);
        $this->assertGreaterThan(0, $asked);
        $this->assertNotSame('100M', $after);
    }

    public function test_pulling_a_file_directly_returns_its_attachment_or_the_reason_it_could_not(): void
    {
        $url = 'https://old.example.edu/u/direct.pdf';
        $this->http['HEAD'][$url] = [200, null];
        $this->http['GET'][$url] = [200, '%PDF-1.4 small'];

        $id = $this->call('sideload_one', $url);
        $this->assertIsInt($id);
        $this->assertSame('direct', get_post($id)->post_title);
        $this->assertSame('', get_post($id)->post_content);
        $this->files[] = (string) get_attached_file($id);
        $this->assertSame(60, $this->lastGet()[2]['timeout']);

        $this->http['GET'][$url] = [503, 'down'];
        $error = $this->call('sideload_one', $url, 30);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('fetchfailed', $error->get_error_code());
        $this->assertSame('The server answered 503.', $error->get_error_message());

        $this->http['GET'][$url] = new WP_Error('http_request_failed', 'no route to host');
        $error = $this->call('sideload_one', $url);
        $this->assertSame('fetchfailed', $error->get_error_code());
        $this->assertSame('no route to host', $error->get_error_message());
    }
}

/**
 * An object whose URL sits in a private property, out of reach of the rewrite.
 */
final class UamsMraJobsPrivateHolder
{
    public function __construct(private string $url) {}

    public function url(): string
    {
        return $this->url;
    }
}

// cspell:ignore unfiltered unserialized unserializes
