<?php

/**
 * The request-handling half of UAMS_MRA_Admin against a real WordPress and database.
 *
 * Every handler is called directly with the superglobals it would see. WordPress ends
 * a request with `die`, so the test turns each of its exits into an exception: a
 * JSON answer or a failed nonce check ends through the ajax die handler, a failed
 * admin-referer check through the plain one, and a redirect is cut short by the
 * `wp_redirect` filter before the handler's own `exit` is reached. What each
 * request did is then read back from where it was stored.
 *
 * @see includes/class-admin.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use ReflectionMethod;
use RuntimeException;
use Throwable;
use UAMS_MRA_Admin;
use UAMS_MRA_Archive;
use UAMS_MRA_Repoint;
use UAMS_MRA_Scanner;
use UAMS_MRA_Selection;
use UAMS_MRA_Store;
use UAMS_MRA_Triage;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;
use WP_User;

/**
 * How a request ended: a wp_die(), with what it said, or a redirect.
 */
final class AdminEnd extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly mixed $detail, public readonly mixed $response = null)
    {
        parent::__construct($kind);
    }
}

/**
 * The admin, noting the HTTP status each error answer asked for.
 *
 * WordPress sends a status only while no output has gone out, and the test runner has printed before the first test, so the status never reaches a header or a filter here.
 */
final class AdminSpy extends UAMS_MRA_Admin
{
    /**
     * @var list<int>
     */
    public array $statuses = [];

    /**
     * A file that collects the headers a download is asked to send, or null to send none.
     */
    public ?string $headerLog = null;

    public function forgetStatuses(): void
    {
        $this->statuses = [];
    }

    protected function send_no_cache_headers(): void
    {
        $this->note('nocache');
    }

    protected function send_header(string $line): void
    {
        $this->note($line);
    }

    private function note(string $line): void
    {
        if ($this->headerLog !== null) {
            file_put_contents($this->headerLog, $line."\n", FILE_APPEND);
        }
    }

    protected function send_json_error(array $data, int $status): void
    {
        $this->statuses[] = $status;

        parent::send_json_error($data, $status);
    }
}

final class AdminRequestsTest extends IntegrationTestCase
{
    private AdminSpy $admin;

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
        delete_option('uams_mra_settings');
        delete_option(UAMS_MRA_Repoint::STATE_OPTION);
        delete_option(UAMS_MRA_Repoint::FATAL_OPTION);
        $this->wipe_archive();

        $this->admin = new AdminSpy;
        $this->login('administrator');

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        $GLOBALS['wp_settings_errors'] = [];
    }

    public function tear_down(): void
    {
        $this->wipe_archive();

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $GLOBALS['wp_settings_errors'] = [];

        parent::tear_down();
    }

    /**
     * Remove the archive folder and everything in it, whatever an earlier run left there.
     */
    private function wipe_archive(): void
    {
        $base = UAMS_MRA_Archive::base_dir();

        if (! is_dir($base)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($base);
    }

    /**
     * Sign in as a user of a role; an administrator may rewrite pages, as on a real site.
     */
    private function login(string $role): WP_User
    {
        $id = self::factory()->user->create(['role' => $role]);

        if ($role === 'administrator' && is_multisite()) {
            grant_super_admin($id);
        }

        wp_set_current_user($id);

        return wp_get_current_user();
    }

    /**
     * Run a request the way WordPress would, returning how it ended.
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $get
     * @return array{end: ?AdminEnd, out: string, status: ?int}
     */
    private function request(callable $request, array $post = [], array $get = [], bool $ajax = false): array
    {
        $_POST = $post;
        $_GET = $get;
        $_REQUEST = array_merge($get, $post);

        $die = (static fn (): callable => static function ($message, $title = '', $args = []): never {
            $args = is_array($args) ? $args : [];

            throw new AdminEnd('die', $message, $args['response'] ?? null);
        });
        $redirect = [$this, 'endRedirect'];

        $this->admin->forgetStatuses();

        add_filter('wp_die_ajax_handler', $die);
        add_filter('wp_die_handler', $die);
        add_filter('wp_redirect', $redirect);

        if ($ajax) {
            add_filter('wp_doing_ajax', '__return_true');
        }

        ob_start();
        $end = null;

        try {
            $request();
        } catch (AdminEnd $adminEnd) {
            $end = $adminEnd;
        } finally {
            $out = (string) ob_get_clean();
            remove_filter('wp_die_ajax_handler', $die);
            remove_filter('wp_die_handler', $die);
            remove_filter('wp_redirect', $redirect);
            remove_filter('wp_doing_ajax', '__return_true');
        }

        return ['end' => $end, 'out' => $out, 'status' => $this->admin->statuses[0] ?? null];
    }

    /**
     * Cut a redirect short before the handler's own exit, whatever the location.
     */
    public function endRedirect(mixed $location): string
    {
        throw new AdminEnd('redirect', $location);
    }

    /**
     * Call a handler with a valid nonce under an action name and read its JSON answer.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function ajax(string $method, string $action, array $post = [], bool $nonce = true): array
    {
        if ($nonce) {
            $post['nonce'] = wp_create_nonce($action);
        }

        $result = $this->request(fn () => $this->admin->{$method}(), $post, [], true);

        $this->assertInstanceOf(AdminEnd::class, $result['end'], 'the handler never ended the request');

        if ($result['out'] === '') {
            return ['success' => false, 'data' => ['died' => $result['end']->detail, 'response' => $result['end']->response], 'status' => $result['status']];
        }

        // A throwable raised by the die stand-in can be caught by the handler's own try block, which answers a second time; the first answer is the one a browser would get.
        $first = $this->firstAnswer($result['out']);
        $json = json_decode($first, true);
        $this->assertIsArray($json, 'the handler answered something that is not JSON: '.$result['out']);

        // The status of the first answer, which is the one a browser would get.
        $json['status'] = $result['status'];

        return $json;
    }

    /**
     * Run the admin-init handler on the plugin screen.
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $get
     */
    private function handle(array $post = [], ?array $get = null): ?AdminEnd
    {
        return $this->request(fn () => $this->admin->handle_requests(), $post, $get ?? ['page' => UAMS_MRA_Admin::SLUG])['end'];
    }

    /**
     * Messages the last request queued through add_settings_error().
     *
     * @return list<array{code: string, message: string, type: string}>
     */
    private function notices(): array
    {
        return array_map(
            static fn (array $e): array => ['code' => $e['code'], 'message' => $e['message'], 'type' => $e['type']],
            array_values(get_settings_errors('uams-mra'))
        );
    }

    /**
     * Make an attachment whose file really exists in uploads.
     */
    private function attachment(string $name, string $content = 'x'): int
    {
        $upload = wp_upload_bits($name, null, $content);
        $this->assertSame(false, $upload['error'], 'the fixture file could not be written');
        $this->files[] = $upload['file'];

        $type = wp_check_filetype($name);

        return (int) wp_insert_attachment(
            ['post_title' => $name, 'post_mime_type' => $type['type'] ?: 'application/octet-stream', 'post_status' => 'inherit'],
            $upload['file']
        );
    }

    /**
     * Put a row for an attachment in the scan results.
     *
     * @param  array<string, mixed>  $row
     */
    private function seedResult(int $id, array $row = []): void
    {
        global $wpdb;

        $wpdb->insert(UAMS_MRA_Store::results_table(), array_merge(['attachment_id' => $id, 'file' => '2024/05/'.$id.'.pdf', 'bytes' => 100, 'ref_count' => 0], $row));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resultRow(int $id): ?array
    {
        global $wpdb;

        $table = UAMS_MRA_Store::results_table();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE attachment_id = %d", $id), ARRAY_A);
    }

    /* ---------------------------------------------------------------------
     * handle_requests
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{string, string}>
     */
    public static function hookProvider(): array
    {
        return [
            'menu' => ['admin_menu', 'add_menu'],
            'assets' => ['admin_enqueue_scripts', 'enqueue'],
            'requests' => ['admin_init', 'handle_requests'],
            'results export' => ['admin_post_uams_mra_export', 'export_csv'],
            'offsite export' => ['admin_post_uams_mra_offsite_export', 'export_offsite_csv'],
            'scan start' => ['wp_ajax_uams_mra_scan_start', 'ajax_start'],
            'scan step' => ['wp_ajax_uams_mra_scan_step', 'ajax_step'],
            'triage' => ['wp_ajax_uams_mra_triage', 'ajax_triage'],
            'archive start' => ['wp_ajax_uams_mra_archive_start', 'ajax_archive_start'],
            'archive step' => ['wp_ajax_uams_mra_archive_step', 'ajax_archive_step'],
            'archive download' => ['admin_post_uams_mra_archive_download', 'download_archive'],
            'repoint plan' => ['wp_ajax_uams_mra_repoint_plan', 'ajax_repoint_plan'],
            'repoint apply' => ['wp_ajax_uams_mra_repoint_apply', 'ajax_repoint_apply'],
            'repoint step' => ['wp_ajax_uams_mra_repoint_step', 'ajax_repoint_step'],
            'repoint sideload' => ['wp_ajax_uams_mra_repoint_sideload', 'ajax_repoint_sideload'],
        ];
    }

    /**
     * @dataProvider hookProvider
     */
    public function test_the_constructor_hooks_each_handler_to_its_action(string $hook, string $method): void
    {
        $admin = new UAMS_MRA_Admin;

        $this->assertSame(10, has_action($hook, [$admin, $method]));
    }

    public function test_the_screen_url_is_returned_and_never_printed(): void
    {
        $this->admin->add_menu();

        ob_start();
        $url = UAMS_MRA_Admin::url(['action' => 'review']);
        $printed = (string) ob_get_clean();

        $this->assertSame('', $printed);
        $this->assertSame(admin_url('upload.php?page=uams-media-audit&action=review'), html_entity_decode($url));
    }

    public function test_a_parked_notice_is_shown_once_and_forgotten(): void
    {
        $this->notice(['message' => 'Archive discarded.', 'type' => 'success']);

        (new ReflectionMethod($this->admin, 'flash_notice'))->invoke($this->admin);

        $this->assertSame([['code' => 'uams_mra_flash', 'message' => 'Archive discarded.', 'type' => 'success']], $this->notices());
        $this->assertSame('', get_user_meta(get_current_user_id(), '_uams_mra_notice', true));

        $GLOBALS['wp_settings_errors'] = [];
        (new ReflectionMethod($this->admin, 'flash_notice'))->invoke($this->admin);
        $this->assertSame([], $this->notices());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableNoticeProvider(): array
    {
        return [
            'nothing parked' => [''],
            'not a list' => ['just text'],
            'no message' => [['type' => 'warning']],
            'an empty message' => [['message' => '', 'type' => 'warning']],
        ];
    }

    /**
     * @dataProvider unusableNoticeProvider
     */
    public function test_a_parked_value_that_is_not_a_notice_shows_nothing(mixed $parked): void
    {
        if ($parked !== '') {
            update_user_meta(get_current_user_id(), '_uams_mra_notice', $parked);
        }

        (new ReflectionMethod($this->admin, 'flash_notice'))->invoke($this->admin);

        $this->assertSame([], $this->notices());
    }

    /**
     * Park a notice the way the handlers do.
     *
     * @param  array<string, string>  $notice
     */
    private function notice(array $notice): void
    {
        update_user_meta(get_current_user_id(), '_uams_mra_notice', $notice);
    }

    public function test_nothing_runs_off_the_plugin_screen(): void
    {
        $id = $this->attachment('keep.pdf');
        $this->seedResult($id);

        $post = ['uams_mra_delete_marked' => '1', 'uams_mra_save_settings' => '1', 'action' => 'uams_mra_delete', 'ids' => [$id], '_wpnonce' => wp_create_nonce('bulk-media_items')];

        foreach ([[], ['page' => 'something-else'], ['page' => UAMS_MRA_Admin::SLUG.'-offsite']] as $get) {
            $this->assertNull($this->handle($post, $get));
        }

        $this->assertSame('attachment', get_post_type($id));
        $this->assertFalse(get_option('uams_mra_settings'));
        $this->assertSame([], $this->notices());
    }

    public function test_a_user_without_the_capability_runs_nothing_on_the_plugin_screen(): void
    {
        $id = $this->attachment('keep.pdf');
        $this->seedResult($id);
        $this->login('subscriber');

        $this->assertNull($this->handle(['action' => 'uams_mra_delete', 'ids' => [$id], '_wpnonce' => wp_create_nonce('bulk-media_items')]));
        $this->assertNull($this->handle(['uams_mra_save_settings' => '1', '_wpnonce' => wp_create_nonce('uams_mra_settings')]));

        $this->assertSame('attachment', get_post_type($id));
        $this->assertFalse(get_option('uams_mra_settings'));
    }

    public function test_a_request_with_no_form_field_does_nothing(): void
    {
        $this->assertNull($this->handle());
        $this->assertSame([], $this->notices());
        $this->assertFalse(get_option('uams_mra_settings'));
    }

    /* ---------------------------------------------------------------------
     * maybe_save_settings
     * ------------------------------------------------------------------ */

    public function test_saving_settings_needs_the_nonce(): void
    {
        $end = $this->handle(['uams_mra_save_settings' => '1']);

        $this->assertSame('die', $end?->kind);
        $this->assertSame(403, $end->response);
        $this->assertFalse(get_option('uams_mra_settings'));

        $end = $this->handle(['uams_mra_save_settings' => '1', '_wpnonce' => wp_create_nonce('some_other_action')]);

        $this->assertSame('die', $end?->kind);
        $this->assertFalse(get_option('uams_mra_settings'));
    }

    public function test_saving_with_nothing_ticked_stores_every_default_and_zeroes_the_switches(): void
    {
        $this->assertNull($this->handle(['uams_mra_save_settings' => '1', '_wpnonce' => wp_create_nonce('uams_mra_settings')]));

        $defaults = uams_mra_default_settings();
        $saved = get_option('uams_mra_settings');

        foreach (['include_revisions', 'include_numeric_meta', 'include_usermeta', 'include_termmeta', 'include_comments', 'include_network', 'include_render', 'include_offsite'] as $key) {
            $this->assertSame(0, $saved[$key], $key);
        }

        $this->assertSame('', $saved['extra_paths']);

        foreach (['batch_posts', 'batch_meta', 'batch_index', 'batch_finalize', 'batch_render', 'render_max_pages', 'batch_repoint', 'batch_sideload', 'sideload_max_mb'] as $key) {
            $this->assertSame($defaults[$key], $saved[$key], $key);
        }

        $this->assertCount(count($defaults), $saved);
        $this->assertSame(
            [['code' => 'uams_mra_saved', 'message' => 'Settings saved.', 'type' => 'success']],
            $this->notices()
        );
    }

    public function test_saving_stores_what_was_ticked_and_typed(): void
    {
        $this->handle([
            'uams_mra_save_settings' => '1',
            '_wpnonce' => wp_create_nonce('uams_mra_settings'),
            'include_revisions' => '1',
            'include_network' => 'on',
            'include_offsite' => '',
            'extra_paths' => "  /cdn/one\n<b>/cdn/two</b>  ",
            'batch_posts' => '300',
            'batch_meta' => '3000',
            'batch_index' => '600',
            'batch_finalize' => '250',
            'batch_render' => '4',
            'render_max_pages' => '400',
            'batch_repoint' => '6',
            'batch_sideload' => '2',
            'sideload_max_mb' => '128',
        ]);

        $saved = get_option('uams_mra_settings');

        $this->assertSame(1, $saved['include_revisions']);
        $this->assertSame(1, $saved['include_network']);
        $this->assertSame(1, $saved['include_offsite']);
        $this->assertSame(0, $saved['include_usermeta']);
        $this->assertSame("/cdn/one\n/cdn/two", $saved['extra_paths']);
        $this->assertSame(
            ['batch_posts' => 300, 'batch_meta' => 3000, 'batch_index' => 600, 'batch_finalize' => 250, 'batch_render' => 4, 'render_max_pages' => 400, 'batch_repoint' => 6, 'batch_sideload' => 2, 'sideload_max_mb' => 128],
            array_intersect_key($saved, array_flip(['batch_posts', 'batch_meta', 'batch_index', 'batch_finalize', 'batch_render', 'render_max_pages', 'batch_repoint', 'batch_sideload', 'sideload_max_mb']))
        );
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function clampProvider(): array
    {
        return [
            'shared range, below' => ['batch_posts', '9', 10],
            'shared range, lowest' => ['batch_posts', '10', 10],
            'shared range, highest' => ['batch_posts', '10000', 10000],
            'shared range, above' => ['batch_posts', '10001', 10000],
            'meta below' => ['batch_meta', '0', 10],
            'meta above' => ['batch_meta', '99999', 10000],
            'index below' => ['batch_index', '1', 10],
            'index above' => ['batch_index', '20000', 10000],
            'finalize below' => ['batch_finalize', '5', 10],
            'finalize above' => ['batch_finalize', '20000', 10000],
            'render below' => ['batch_render', '0', 1],
            'render lowest' => ['batch_render', '1', 1],
            'render highest' => ['batch_render', '25', 25],
            'render above' => ['batch_render', '26', 25],
            'render pages below' => ['render_max_pages', '0', 1],
            'render pages lowest' => ['render_max_pages', '1', 1],
            'render pages highest' => ['render_max_pages', '5000', 5000],
            'render pages above' => ['render_max_pages', '5001', 5000],
            'repoint below' => ['batch_repoint', '0', 1],
            'repoint lowest' => ['batch_repoint', '1', 1],
            'repoint highest' => ['batch_repoint', '25', 25],
            'repoint above' => ['batch_repoint', '26', 25],
            'sideload below' => ['batch_sideload', '0', 1],
            'sideload lowest' => ['batch_sideload', '1', 1],
            'sideload highest' => ['batch_sideload', '10', 10],
            'sideload above' => ['batch_sideload', '11', 10],
            'megabytes below' => ['sideload_max_mb', '0', 1],
            'megabytes lowest' => ['sideload_max_mb', '1', 1],
            'megabytes highest' => ['sideload_max_mb', '2048', 2048],
            'megabytes above' => ['sideload_max_mb', '2049', 2048],
            'negative becomes its absolute value' => ['batch_posts', '-50', 50],
            'text becomes zero then the floor' => ['batch_posts', 'many', 10],
        ];
    }

    /**
     * @dataProvider clampProvider
     */
    public function test_each_number_is_held_to_its_range(string $key, string $submitted, int $expected): void
    {
        $this->handle(['uams_mra_save_settings' => '1', '_wpnonce' => wp_create_nonce('uams_mra_settings'), $key => $submitted]);

        $this->assertSame($expected, get_option('uams_mra_settings')[$key]);
    }

    /* ---------------------------------------------------------------------
     * maybe_delete, maybe_delete_marked, delete_ids, report_delete
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function deleteActionProvider(): array
    {
        return [
            'the top action' => [['action' => 'uams_mra_delete']],
            'the bottom action' => [['action' => '-1', 'action2' => 'uams_mra_delete']],
            'a mixed-case action key is cleaned' => [['action' => 'UAMS_MRA_Delete']],
        ];
    }

    /**
     * @dataProvider deleteActionProvider
     *
     * @param  array<string, string>  $action
     */
    public function test_a_bulk_delete_runs_from_either_action_menu(array $action): void
    {
        $id = $this->attachment('gone.pdf');
        $this->seedResult($id, ['bytes' => 2048]);

        $this->assertNull($this->handle($action + ['ids' => [(string) $id], '_wpnonce' => wp_create_nonce('bulk-media_items')]));

        $this->assertNull(get_post($id));
        $this->assertNull($this->resultRow($id));
        $this->assertSame([['code' => 'uams_mra_deleted', 'message' => 'Deleted 1 files and freed 2.0 KB.', 'type' => 'success']], $this->notices());
    }

    public function test_another_action_deletes_nothing(): void
    {
        $id = $this->attachment('stay.pdf');
        $this->seedResult($id);

        foreach ([['action' => 'trash'], ['action' => 'trash', 'action2' => 'trash'], ['action2' => 'uams_mra_deletes'], []] as $action) {
            $this->assertNull($this->handle($action + ['ids' => [$id], '_wpnonce' => wp_create_nonce('bulk-media_items')]));
        }

        $this->assertSame('attachment', get_post_type($id));
        $this->assertSame([], $this->notices());
    }

    public function test_a_bulk_delete_needs_the_nonce(): void
    {
        $id = $this->attachment('stay.pdf');

        $end = $this->handle(['action' => 'uams_mra_delete', 'ids' => [$id]]);

        $this->assertSame('die', $end?->kind);
        $this->assertSame(403, $end->response);
        $this->assertSame('attachment', get_post_type($id));
    }

    public function test_a_bulk_delete_with_no_usable_ids_says_so(): void
    {
        $nonce = wp_create_nonce('bulk-media_items');

        foreach ([[], ['ids' => []], ['ids' => ['0', 'abc', '']]] as $extra) {
            $GLOBALS['wp_settings_errors'] = [];
            $this->assertNull($this->handle(['action' => 'uams_mra_delete', '_wpnonce' => $nonce] + $extra));
            $this->assertSame(
                [['code' => 'uams_mra_none', 'message' => 'No files were selected, so nothing was deleted.', 'type' => 'warning']],
                $this->notices()
            );
        }
    }

    public function test_a_bulk_delete_accepts_a_single_value_as_the_list(): void
    {
        $id = $this->attachment('single.pdf');
        $this->seedResult($id);

        $this->handle(['action' => 'uams_mra_delete', 'ids' => (string) $id, '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertNull(get_post($id));
    }

    public function test_a_referenced_file_is_kept_unless_the_override_is_ticked(): void
    {
        $kept = $this->attachment('kept.pdf');
        $free = $this->attachment('free.pdf');
        $this->seedResult($kept, ['ref_count' => 2, 'bytes' => 1000]);
        $this->seedResult($free, ['ref_count' => 0, 'bytes' => 3000]);

        $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $kept, (string) $free], '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertSame('attachment', get_post_type($kept));
        $this->assertNotNull($this->resultRow($kept));
        $this->assertNull(get_post($free));
        $this->assertSame(
            [['code' => 'uams_mra_deleted', 'message' => 'Deleted 1 files and freed 2.9 KB. Kept 1 files that still have references. Tick the override box to delete those.', 'type' => 'warning']],
            $this->notices()
        );

        $GLOBALS['wp_settings_errors'] = [];
        $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $kept], 'uams_mra_force' => '1', '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertNull(get_post($kept));
        $this->assertNull($this->resultRow($kept));
        $this->assertSame([['code' => 'uams_mra_deleted', 'message' => 'Deleted 1 files and freed 1,000.0 B.', 'type' => 'success']], $this->notices());
    }

    public function test_a_file_missing_from_the_results_is_deleted_and_adds_no_bytes(): void
    {
        $id = $this->attachment('unscanned.pdf');

        $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $id], '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertNull(get_post($id));
        $this->assertSame([['code' => 'uams_mra_deleted', 'message' => 'Deleted 1 files and freed 0.0 B.', 'type' => 'success']], $this->notices());
    }

    public function test_an_id_that_is_not_an_attachment_is_left_alone(): void
    {
        $post = self::factory()->post->create();

        $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $post, '99999999'], '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertSame('post', get_post_type($post));
        $this->assertSame([['code' => 'uams_mra_deleted', 'message' => 'Deleted 0 files and freed 0.0 B.', 'type' => 'success']], $this->notices());
    }

    public function test_an_id_that_is_not_an_attachment_does_not_stop_the_ones_after_it(): void
    {
        $post = self::factory()->post->create();
        $id = $this->attachment('after.pdf');
        $this->seedResult($id);

        $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $post, (string) $id], '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertSame('post', get_post_type($post));
        $this->assertNull(get_post($id));
    }

    public function test_a_file_is_deleted_for_good_not_trashed(): void
    {
        $id = $this->attachment('forced.pdf');
        $this->seedResult($id);
        $forced = [];
        $watch = static function ($delete, $post, $force) use (&$forced) {
            $forced[] = $force;

            return $delete;
        };
        add_filter('pre_delete_attachment', $watch, 10, 3);

        try {
            $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $id], '_wpnonce' => wp_create_nonce('bulk-media_items')]);
        } finally {
            remove_filter('pre_delete_attachment', $watch, 10);
        }

        $this->assertSame([true], $forced);
    }

    public function test_a_file_core_will_not_delete_is_not_counted_and_keeps_its_row(): void
    {
        $id = $this->attachment('refused.pdf');
        $this->seedResult($id, ['bytes' => 500]);

        $refuse = static fn (): bool => false;
        add_filter('pre_delete_attachment', $refuse);

        try {
            $this->handle(['action' => 'uams_mra_delete', 'ids' => [(string) $id], '_wpnonce' => wp_create_nonce('bulk-media_items')]);
        } finally {
            remove_filter('pre_delete_attachment', $refuse);
        }

        $this->assertSame('attachment', get_post_type($id));
        $this->assertNotNull($this->resultRow($id));
        $this->assertSame([['code' => 'uams_mra_deleted', 'message' => 'Deleted 0 files and freed 0.0 B.', 'type' => 'success']], $this->notices());
    }

    public function test_deleting_the_marked_files_needs_the_nonce(): void
    {
        $id = $this->attachment('marked.pdf');
        $this->seedResult($id);
        UAMS_MRA_Triage::set($id, 'delete', '');

        $end = $this->handle(['uams_mra_delete_marked' => '1']);

        $this->assertSame('die', $end?->kind);
        $this->assertSame('attachment', get_post_type($id));
    }

    public function test_deleting_the_marked_files_with_nothing_marked_says_so(): void
    {
        $id = $this->attachment('unmarked.pdf');
        $this->seedResult($id);
        UAMS_MRA_Triage::set($id, 'keep', '');

        $this->assertNull($this->handle(['uams_mra_delete_marked' => '1', '_wpnonce' => wp_create_nonce('bulk-media_items')]));

        $this->assertSame('attachment', get_post_type($id));
        $this->assertSame(
            [['code' => 'uams_mra_none', 'message' => 'Nothing is marked Delete, so nothing was deleted.', 'type' => 'warning']],
            $this->notices()
        );
    }

    public function test_deleting_the_marked_files_deletes_only_those_and_honours_the_guard(): void
    {
        $marked = $this->attachment('marked.pdf');
        $guarded = $this->attachment('guarded.pdf');
        $other = $this->attachment('other.pdf');
        $this->seedResult($marked, ['bytes' => 1024]);
        $this->seedResult($guarded, ['ref_count' => 1, 'bytes' => 1024]);
        $this->seedResult($other, ['bytes' => 1024]);
        UAMS_MRA_Triage::set($marked, 'delete', '');
        UAMS_MRA_Triage::set($guarded, 'delete', '');
        UAMS_MRA_Triage::set($other, 'ask', '');

        $this->handle(['uams_mra_delete_marked' => '1', '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertNull(get_post($marked));
        $this->assertSame('attachment', get_post_type($guarded));
        $this->assertSame('attachment', get_post_type($other));
        $this->assertSame(
            [['code' => 'uams_mra_deleted', 'message' => 'Deleted 1 files and freed 1.0 KB. Kept 1 files that still have references. Tick the override box to delete those.', 'type' => 'warning']],
            $this->notices()
        );

        $GLOBALS['wp_settings_errors'] = [];
        $this->handle(['uams_mra_delete_marked' => '1', 'uams_mra_force' => '1', '_wpnonce' => wp_create_nonce('bulk-media_items')]);

        $this->assertNull(get_post($guarded));
        $this->assertSame('attachment', get_post_type($other));
    }

    /* ---------------------------------------------------------------------
     * maybe_build_selection
     * ------------------------------------------------------------------ */

    public function test_building_a_selection_needs_the_nonce(): void
    {
        foreach (['uams_mra_select_filter', 'uams_mra_select_text'] as $field) {
            $end = $this->handle([$field => '1']);

            $this->assertSame('die', $end?->kind, $field);
        }

        $this->assertSame([], UAMS_MRA_Selection::get());
    }

    public function test_a_filter_that_matches_nothing_redirects_back_with_a_warning(): void
    {
        $end = $this->handle(['uams_mra_select_filter' => '1', '_wpnonce' => wp_create_nonce('uams_mra_select')]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame(UAMS_MRA_Admin::url(), $end->detail);
        $this->assertSame([], UAMS_MRA_Selection::get());
        $this->assertSame(['message' => 'That filter matches no files.', 'type' => 'warning'], get_user_meta(get_current_user_id(), '_uams_mra_notice', true));
    }

    public function test_an_empty_filter_goes_back_to_the_page_it_came_from(): void
    {
        $_SERVER['HTTP_REFERER'] = admin_url('upload.php?page=uams-media-audit&view=weak');

        try {
            $end = $this->handle(['uams_mra_select_filter' => '1', '_wpnonce' => wp_create_nonce('uams_mra_select'), '_wp_http_referer' => '/wp-admin/upload.php?page=uams-media-audit&view=weak']);
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }

        $this->assertSame('redirect', $end?->kind);
        $this->assertStringContainsString('view=weak', (string) $end->detail);
    }

    public function test_a_filter_stores_the_files_it_matches_and_opens_the_review(): void
    {
        $one = $this->attachment('one.pdf');
        $two = $this->attachment('two.pdf');
        $this->seedResult($one, ['bytes' => 10]);
        $this->seedResult($two, ['bytes' => 20]);

        $end = $this->handle(['uams_mra_select_filter' => '1', '_wpnonce' => wp_create_nonce('uams_mra_select')]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame(UAMS_MRA_Admin::url(['action' => 'review']), $end->detail);
        $this->assertSame([$two, $one], UAMS_MRA_Selection::get());
    }

    public function test_pasted_text_that_matches_nothing_redirects_back_with_a_warning(): void
    {
        foreach ([[], ['pasted' => ''], ['pasted' => "nothing.pdf\n99999999"]] as $extra) {
            delete_user_meta(get_current_user_id(), '_uams_mra_notice');

            $end = $this->handle(['uams_mra_select_text' => '1', '_wpnonce' => wp_create_nonce('uams_mra_select')] + $extra);

            $this->assertSame('redirect', $end?->kind);
            $this->assertSame(UAMS_MRA_Admin::url(), $end->detail);
            $this->assertSame([], UAMS_MRA_Selection::get());
            $this->assertSame(
                ['message' => 'None of that matched a file in the last scan. Paste attachment IDs, filenames, or URLs.', 'type' => 'warning'],
                get_user_meta(get_current_user_id(), '_uams_mra_notice', true)
            );
        }
    }

    public function test_pasted_text_that_matches_nothing_goes_back_to_the_page_it_came_from(): void
    {
        $referer = admin_url('upload.php?page=uams-media-audit&view=weak');

        $end = $this->handle(['uams_mra_select_text' => '1', 'pasted' => 'nothing.pdf', '_wpnonce' => wp_create_nonce('uams_mra_select'), '_wp_http_referer' => $referer]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame($referer, $end->detail);
    }

    public function test_pasted_text_that_all_matches_stores_the_set_without_a_notice(): void
    {
        $one = $this->attachment('one.pdf');
        $two = $this->attachment('two.pdf');

        $end = $this->handle(['uams_mra_select_text' => '1', 'pasted' => $one."\n".$two, '_wpnonce' => wp_create_nonce('uams_mra_select')]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame(UAMS_MRA_Admin::url(['action' => 'review']), $end->detail);
        $this->assertSame([$one, $two], UAMS_MRA_Selection::get());
        $this->assertSame('', get_user_meta(get_current_user_id(), '_uams_mra_notice', true));
    }

    public function test_pasted_text_that_partly_matches_stores_the_matches_and_names_the_rest(): void
    {
        $one = $this->attachment('one.pdf');
        $unmatched = [];

        for ($i = 1; $i <= 22; $i++) {
            $unmatched[] = 'missing'.$i.'.pdf';
        }

        $end = $this->handle(['uams_mra_select_text' => '1', 'pasted' => $one.','.implode(',', $unmatched), '_wpnonce' => wp_create_nonce('uams_mra_select')]);

        $this->assertSame(UAMS_MRA_Admin::url(['action' => 'review']), $end?->detail);
        $this->assertSame([$one], UAMS_MRA_Selection::get());

        $notice = get_user_meta(get_current_user_id(), '_uams_mra_notice', true);
        $this->assertSame('warning', $notice['type']);
        $this->assertSame(
            'Matched 1 files. 22 entries matched nothing and were left out: '.implode(', ', array_slice($unmatched, 0, 20)),
            $notice['message']
        );
    }

    public function test_the_filter_button_wins_when_both_are_posted(): void
    {
        $one = $this->attachment('one.pdf');
        $two = $this->attachment('two.pdf');
        $this->seedResult($one);

        $this->handle(['uams_mra_select_filter' => '1', 'uams_mra_select_text' => '1', 'pasted' => (string) $two, '_wpnonce' => wp_create_nonce('uams_mra_select')]);

        $this->assertSame([$one], UAMS_MRA_Selection::get());
    }

    /* ---------------------------------------------------------------------
     * maybe_delete_selection
     * ------------------------------------------------------------------ */

    public function test_deleting_the_selection_needs_the_nonce(): void
    {
        $id = $this->attachment('set.pdf');
        UAMS_MRA_Selection::store([$id]);

        $end = $this->handle(['uams_mra_delete_selection' => '1']);

        $this->assertSame('die', $end?->kind);
        $this->assertSame('attachment', get_post_type($id));
        $this->assertSame([$id], UAMS_MRA_Selection::get());
    }

    public function test_deleting_an_empty_selection_redirects_with_a_warning(): void
    {
        $end = $this->handle(['uams_mra_delete_selection' => '1', '_wpnonce' => wp_create_nonce('uams_mra_review')]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame(UAMS_MRA_Admin::url(), $end->detail);
        $this->assertSame(['message' => 'Nothing is selected, so nothing was deleted.', 'type' => 'warning'], get_user_meta(get_current_user_id(), '_uams_mra_notice', true));
    }

    public function test_deleting_the_selection_deletes_clears_and_reports(): void
    {
        $one = $this->attachment('one.pdf');
        $two = $this->attachment('two.pdf');
        $this->seedResult($one, ['bytes' => 1024]);
        $this->seedResult($two, ['bytes' => 1024]);
        UAMS_MRA_Selection::store([$one, $two]);

        $end = $this->handle(['uams_mra_delete_selection' => '1', '_wpnonce' => wp_create_nonce('uams_mra_review')]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame(UAMS_MRA_Admin::url(), $end->detail);
        $this->assertNull(get_post($one));
        $this->assertNull(get_post($two));
        $this->assertSame([], UAMS_MRA_Selection::get());
        $this->assertSame(['message' => 'Deleted 2 files and freed 2.0 KB.', 'type' => 'success'], get_user_meta(get_current_user_id(), '_uams_mra_notice', true));
    }

    public function test_deleting_the_selection_keeps_referenced_files_unless_overridden(): void
    {
        $kept = $this->attachment('kept.pdf');
        $free = $this->attachment('free.pdf');
        $this->seedResult($kept, ['ref_count' => 3, 'bytes' => 1024]);
        $this->seedResult($free, ['bytes' => 1024]);
        UAMS_MRA_Selection::store([$kept, $free]);

        $this->handle(['uams_mra_delete_selection' => '1', '_wpnonce' => wp_create_nonce('uams_mra_review')]);

        $this->assertSame('attachment', get_post_type($kept));
        $this->assertNull(get_post($free));
        $this->assertSame(
            ['message' => 'Deleted 1 files and freed 1.0 KB. Kept 1 that still have references. Tick the override box to delete those too.', 'type' => 'warning'],
            get_user_meta(get_current_user_id(), '_uams_mra_notice', true)
        );
        $this->assertSame([], UAMS_MRA_Selection::get());

        UAMS_MRA_Selection::store([$kept]);
        $this->handle(['uams_mra_delete_selection' => '1', 'uams_mra_force' => '1', '_wpnonce' => wp_create_nonce('uams_mra_review')]);

        $this->assertNull(get_post($kept));
        $this->assertSame('success', get_user_meta(get_current_user_id(), '_uams_mra_notice', true)['type']);
    }

    /* ---------------------------------------------------------------------
     * maybe_discard_archive
     * ------------------------------------------------------------------ */

    public function test_discarding_the_archive_needs_the_nonce(): void
    {
        $id = $this->attachment('zip-me.pdf');
        $this->assertIsArray(UAMS_MRA_Archive::start([$id]));

        $end = $this->handle(['uams_mra_discard_archive' => '1']);

        $this->assertSame('die', $end?->kind);
        $this->assertIsArray(UAMS_MRA_Archive::get_job());
    }

    public function test_discarding_the_archive_removes_the_job_and_returns_to_the_review(): void
    {
        $id = $this->attachment('zip-me.pdf');
        $job = UAMS_MRA_Archive::start([$id]);
        $this->assertIsArray($job);
        $this->assertDirectoryExists($job['dir']);

        $end = $this->handle(['uams_mra_discard_archive' => '1', '_wpnonce' => wp_create_nonce('uams_mra_review')]);

        $this->assertSame('redirect', $end?->kind);
        $this->assertSame(UAMS_MRA_Admin::url(['action' => 'review']), $end->detail);
        $this->assertNull(UAMS_MRA_Archive::get_job());
        $this->assertDirectoryDoesNotExist($job['dir']);
        $this->assertSame(['message' => 'Archive discarded.', 'type' => 'success'], get_user_meta(get_current_user_id(), '_uams_mra_notice', true));
    }

    /* ---------------------------------------------------------------------
     * Archive requests
     * ------------------------------------------------------------------ */

    public function test_every_ajax_handler_refuses_a_request_with_no_nonce(): void
    {
        $actions = [
            'ajax_archive_start' => 'uams_mra_archive',
            'ajax_archive_step' => 'uams_mra_archive',
            'ajax_triage' => 'uams_mra_triage',
            'ajax_start' => 'uams_mra_scan',
            'ajax_step' => 'uams_mra_scan',
            'ajax_repoint_plan' => 'uams_mra_repoint',
            'ajax_repoint_apply' => 'uams_mra_repoint',
            'ajax_repoint_sideload' => 'uams_mra_repoint',
            'ajax_repoint_step' => 'uams_mra_repoint',
        ];

        foreach ($actions as $method => $action) {
            $answer = $this->ajax($method, $action, [], false);

            $this->assertFalse($answer['success'], $method);
            $this->assertSame(['died' => -1, 'response' => 403], $answer['data'], $method);

            $answer = $this->ajax($method, 'a_different_action', [], true);
            $this->assertSame(['died' => -1, 'response' => 403], $answer['data'], $method." with another action's nonce");
        }

        $this->assertNull(UAMS_MRA_Archive::get_job());
        $this->assertFalse(get_option(UAMS_MRA_Repoint::STATE_OPTION));
    }

    public function test_every_ajax_handler_refuses_a_user_without_the_capability(): void
    {
        $this->login('subscriber');

        $cases = [
            'ajax_archive_start' => ['uams_mra_archive', 'You do not have permission to do this.'],
            'ajax_archive_step' => ['uams_mra_archive', 'You do not have permission to do this.'],
            'ajax_triage' => ['uams_mra_triage', 'You do not have permission to change this.'],
            'ajax_start' => ['uams_mra_scan', 'You do not have permission to run a scan.'],
            'ajax_step' => ['uams_mra_scan', 'You do not have permission to run a scan.'],
            'ajax_repoint_plan' => ['uams_mra_repoint', 'You do not have permission to do this.'],
            'ajax_repoint_apply' => ['uams_mra_repoint', 'You do not have permission to do this.'],
            'ajax_repoint_sideload' => ['uams_mra_repoint', 'You do not have permission to do this.'],
            'ajax_repoint_step' => ['uams_mra_repoint', 'You do not have permission to do this.'],
        ];

        foreach ($cases as $method => [$action, $message]) {
            $answer = $this->ajax($method, $action);

            $this->assertFalse($answer['success'], $method);
            $this->assertSame(['message' => $message], $answer['data'], $method);
            $this->assertSame(403, $answer['status'], $method);
        }

        $this->assertNull(UAMS_MRA_Archive::get_job());
        $this->assertFalse(get_option(UAMS_MRA_Repoint::STATE_OPTION));
        $this->assertFalse(get_option('uams_mra_scan_state'));
    }

    public function test_archive_start_refuses_an_empty_selection(): void
    {
        $answer = $this->ajax('ajax_archive_start', 'uams_mra_archive');

        $this->assertFalse($answer['success']);
        $this->assertSame(['message' => 'Nothing is selected, so there is nothing to archive.'], $answer['data']);
        $this->assertSame(400, $answer['status']);
        $this->assertNull(UAMS_MRA_Archive::get_job());
    }

    public function test_archive_start_and_step_build_the_archive_and_shape_the_job(): void
    {
        $one = $this->attachment('one.pdf', str_repeat('a', 2048));
        $two = $this->attachment('two.pdf', str_repeat('b', 1024));
        UAMS_MRA_Selection::store([$one, $two]);

        $started = $this->ajax('ajax_archive_start', 'uams_mra_archive');

        $this->assertTrue($started['success']);
        $this->assertSame(
            ['done' => false, 'percent' => 0, 'files' => 0, 'bytes' => '0.0 B', 'skipped' => 0, 'parts' => [], 'total' => 2, 'progress' => '0 of 2 files · 0.0 B'],
            $started['data']
        );

        $stepped = $this->ajax('ajax_archive_step', 'uams_mra_archive');

        $this->assertTrue($stepped['success']);
        $data = $stepped['data'];
        $this->assertTrue($data['done']);
        $this->assertSame(100, $data['percent']);
        $this->assertSame(2, $data['files']);
        $this->assertSame('3.0 KB', $data['bytes']);
        $this->assertSame(0, $data['skipped']);
        $this->assertSame(2, $data['total']);
        $this->assertSame('2 of 2 files · 3.0 KB', $data['progress']);
        $this->assertCount(1, $data['parts']);
        $this->assertSame(1, $data['parts'][0]['part']);
        $this->assertSame(UAMS_MRA_Archive::part_name(1), $data['parts'][0]['name']);
        $this->assertSame(2, $data['parts'][0]['files']);
        /** @var array<string, mixed> $job */
        $job = UAMS_MRA_Archive::get_job();
        $this->assertSame(size_format($this->bytesOf($job['dir'].'/'.UAMS_MRA_Archive::part_name(1)), 1), $data['parts'][0]['size']);
        $url = html_entity_decode((string) $data['parts'][0]['url']);
        $this->assertStringStartsWith(admin_url('admin-post.php?action=uams_mra_archive_download&part=1&_wpnonce='), $url);
        $this->assertSame(1, wp_verify_nonce($this->queryArg($url, '_wpnonce'), 'uams_mra_archive_download'));
    }

    public function test_archive_step_without_a_job_reports_the_failure(): void
    {
        $answer = $this->ajax('ajax_archive_step', 'uams_mra_archive');

        $this->assertFalse($answer['success']);
        $this->assertIsString($answer['data']['message']);
        $this->assertNotSame('', $answer['data']['message']);
        $this->assertSame(400, $answer['status']);
    }

    public function test_archive_progress_is_a_percentage_of_the_files_handled(): void
    {
        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->attachment('f'.$i.'.pdf', 'x');
        }

        UAMS_MRA_Selection::store($ids);
        $this->ajax('ajax_archive_start', 'uams_mra_archive');

        $job = UAMS_MRA_Archive::get_job();
        $this->assertIsArray($job);
        $job['cursor'] = 1;
        $job['files'] = 1;
        $job['bytes'] = 1536;
        $job['skipped'] = ['a', 'b'];
        $job['parts'] = [1 => ['part' => 1, 'name' => 'p1.zip', 'bytes' => 1536, 'files' => 1], 2 => ['part' => 2, 'name' => 'p2.zip', 'bytes' => 0, 'files' => 0]];

        $method = new ReflectionMethod($this->admin, 'archive_payload');
        $payload = $method->invoke($this->admin, $job);

        $this->assertSame(33, $payload['percent']);
        $this->assertSame(2, $payload['skipped']);
        $this->assertSame('1.5 KB', $payload['bytes']);
        $this->assertSame('1 of 3 files · 1.5 KB', $payload['progress']);
        $this->assertCount(1, $payload['parts']);
        $this->assertSame('1.5 KB', $payload['parts'][0]['size']);

        $job['cursor'] = 99;
        $this->assertSame(100, $method->invoke($this->admin, $job)['percent']);
    }

    public function test_archive_payload_handles_odd_jobs(): void
    {
        $method = new ReflectionMethod($this->admin, 'archive_payload');
        $base = ['ids' => [1], 'cursor' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => [], 'parts' => [], 'done' => false];

        // No ids at all must not divide by zero, and one id is one whole file.
        $empty = $method->invoke($this->admin, ['ids' => []] + $base);
        $this->assertSame(0, $empty['percent']);
        $this->assertSame(0, $empty['total']);

        $one = $method->invoke($this->admin, ['cursor' => 1, 'ids' => [7]] + $base);
        $this->assertSame(100, $one['percent']);

        // Rounded, not floored: two of three is 67.
        $this->assertSame(67, $method->invoke($this->admin, ['cursor' => 2, 'ids' => [1, 2, 3]] + $base)['percent']);

        // A job with no parts or no skipped list still shapes.
        $bare = $method->invoke($this->admin, ['parts' => null, 'skipped' => null] + $base);
        $this->assertSame([], $bare['parts']);
        $this->assertSame(0, $bare['skipped']);

        // Stored numbers may come back as strings; the browser is given numbers, and a part with no files is left out wherever it sits.
        $mixed = $method->invoke($this->admin, [
            'files' => '4',
            'parts' => [
                1 => ['part' => '1', 'name' => 'empty.zip', 'bytes' => '0', 'files' => '0'],
                2 => ['part' => '2', 'name' => 'full.zip', 'bytes' => '2048', 'files' => '3'],
            ],
        ] + $base);
        $this->assertSame(4, $mixed['files']);
        $this->assertCount(1, $mixed['parts']);
        $this->assertSame(2, $mixed['parts'][0]['part']);
        $this->assertSame(3, $mixed['parts'][0]['files']);
        $this->assertSame('2.0 KB', $mixed['parts'][0]['size']);
        $this->assertSame('full.zip', $mixed['parts'][0]['name']);
        $this->assertSame('2', $this->queryArg((string) $mixed['parts'][0]['url'], 'part'));
    }

    /**
     * Read one query argument out of a URL the page built, as the browser would.
     */
    /**
     * The first answer of a handler that may answer twice, or the test stops.
     */
    private function firstAnswer(string $out): string
    {
        $parts = preg_split('/(?<=\})(?=\{"success")/', $out);

        if ($parts === false) {
            throw new RuntimeException('The handler output could not be split into answers.');
        }

        return $parts[0];
    }

    /**
     * The size of a file on disk, or the test stops.
     */
    private function bytesOf(string $path): int
    {
        $bytes = filesize($path);

        if ($bytes === false) {
            throw new RuntimeException('The size of '.$path.' could not be read.');
        }

        return $bytes;
    }

    private function queryArg(string $url, string $name): string
    {
        parse_str((string) parse_url(html_entity_decode($url), PHP_URL_QUERY), $args);

        $value = $args[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /* ---------------------------------------------------------------------
     * download_archive
     * ------------------------------------------------------------------ */

    private function download(int $part, bool $nonce = true): ?AdminEnd
    {
        $get = ['action' => 'uams_mra_archive_download', 'part' => (string) $part];

        if ($nonce) {
            $get['_wpnonce'] = wp_create_nonce('uams_mra_archive_download');
        }

        return $this->request(fn () => $this->admin->download_archive(), [], $get)['end'];
    }

    public function test_a_download_refuses_a_user_without_the_capability(): void
    {
        $this->login('subscriber');

        $end = $this->download(1);

        $this->assertSame('die', $end?->kind);
        $this->assertSame('You do not have permission to download this.', $end->detail);
    }

    public function test_a_download_needs_the_nonce(): void
    {
        $end = $this->download(1, false);

        $this->assertSame('die', $end?->kind);
        $this->assertSame(403, $end->response);
    }

    public function test_a_download_with_no_job_or_no_such_part_says_the_archive_is_gone(): void
    {
        $this->assertSame('That archive is no longer available. Build it again.', $this->download(1)?->detail);

        $id = $this->attachment('zip-me.pdf');
        UAMS_MRA_Selection::store([$id]);
        $this->ajax('ajax_archive_start', 'uams_mra_archive');
        $this->ajax('ajax_archive_step', 'uams_mra_archive');

        foreach ([0, 2, 99] as $part) {
            $this->assertSame('That archive is no longer available. Build it again.', $this->download($part)?->detail, 'part '.$part);
        }

        $_GET = ['part' => 'abc', '_wpnonce' => wp_create_nonce('uams_mra_archive_download')];
        $this->assertSame('That archive is no longer available. Build it again.', $this->request(fn () => $this->admin->download_archive(), [], $_GET)['end']?->detail);
    }

    /**
     * Run the download in a forked process, because it ends in exit() and would end this one.
     *
     * The child shares this process' database connection, so it is stopped by a kill signal in
     * a shutdown function, before any destructor can close that connection under the parent.
     * Headers cannot be read back: output was already sent when the suite started.
     */
    public function test_a_download_streams_the_part_whole_and_then_ends_the_request(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Forking a process needs the pcntl and posix extensions.');
        }

        $id = $this->attachment('stream-me.pdf', str_repeat('stream-me ', 500));
        UAMS_MRA_Selection::store([$id]);
        $this->ajax('ajax_archive_start', 'uams_mra_archive');
        $this->ajax('ajax_archive_step', 'uams_mra_archive');

        $job = UAMS_MRA_Archive::get_job();
        $this->assertIsArray($job);
        $path = $job['dir'].'/'.$job['parts'][1]['name'];
        $this->assertFileExists($path);

        $captured = (string) tempnam(sys_get_temp_dir(), 'mra-download-');
        $headers = (string) tempnam(sys_get_temp_dir(), 'mra-headers-');
        $this->admin->headerLog = $headers;
        $_GET = ['part' => '1', '_wpnonce' => wp_create_nonce('uams_mra_archive_download')];
        $_REQUEST = $_GET;

        $pid = pcntl_fork();

        if ($pid === 0) {
            // WordPress flushes every buffer in its own shutdown hook, so the bytes are taken as they are flushed.
            ob_start(static function (string $chunk) use ($captured): string {
                file_put_contents($captured, $chunk, FILE_APPEND);

                return '';
            });
            register_shutdown_function(static function (): void {
                posix_kill(posix_getpid(), SIGKILL);
            });
            set_error_handler(static fn (): bool => true);
            $this->admin->download_archive();
            posix_kill(posix_getpid(), SIGKILL);
        }

        $this->assertGreaterThan(0, $pid);
        pcntl_waitpid($pid, $status);

        $streamed = (string) file_get_contents($captured);
        unlink($captured);

        $this->assertSame(
            ['nocache', 'Content-Type: application/zip', 'Content-Disposition: attachment; filename='.basename($path), 'Content-Length: '.filesize($path)],
            array_values(array_filter(explode("\n", (string) file_get_contents($headers))))
        );
        unlink($headers);

        $this->assertSame((string) file_get_contents($path), $streamed);
        $this->assertStringStartsWith('PK', $streamed);
    }

    public function test_a_download_that_names_no_part_does_not_pick_one(): void
    {
        $id = $this->attachment('zip-me.pdf');
        UAMS_MRA_Selection::store([$id]);
        $this->ajax('ajax_archive_start', 'uams_mra_archive');
        $this->ajax('ajax_archive_step', 'uams_mra_archive');
        /** @var array<string, mixed> $job */
        $job = UAMS_MRA_Archive::get_job();
        $this->assertNotEmpty($job['parts'][1]);

        $end = $this->request(fn () => $this->admin->download_archive(), [], ['_wpnonce' => wp_create_nonce('uams_mra_archive_download')])['end'];

        $this->assertSame('That archive is no longer available. Build it again.', $end?->detail);
    }

    public function test_a_download_whose_file_is_gone_says_it_is_missing(): void
    {
        $id = $this->attachment('zip-me.pdf');
        UAMS_MRA_Selection::store([$id]);
        $this->ajax('ajax_archive_start', 'uams_mra_archive');
        $job = UAMS_MRA_Archive::get_job();
        $this->assertIsArray($job);
        $this->ajax('ajax_archive_step', 'uams_mra_archive');
        /** @var array<string, mixed> $job */
        $job = UAMS_MRA_Archive::get_job();
        $path = $job['dir'].'/'.$job['parts'][1]['name'];
        $this->assertFileExists($path);
        unlink($path);

        $this->assertSame('That archive file is missing. Build it again.', $this->download(1)?->detail);
    }

    public function test_a_download_never_reads_outside_the_archive_folder(): void
    {
        $id = $this->attachment('zip-me.pdf');
        UAMS_MRA_Selection::store([$id]);
        $this->ajax('ajax_archive_start', 'uams_mra_archive');
        $this->ajax('ajax_archive_step', 'uams_mra_archive');

        $outside = wp_upload_bits('outside.zip', null, 'not part of the archive');
        $this->files[] = $outside['file'];

        $job = UAMS_MRA_Archive::get_job();
        $job['dir'] = dirname($outside['file']);
        $job['parts'][1]['name'] = 'outside.zip';
        update_user_meta(get_current_user_id(), $this->jobKey(), $job);

        $this->assertSame('That archive file is missing. Build it again.', $this->download(1)?->detail);
    }

    /**
     * The user meta key the archive job is kept under.
     */
    private function jobKey(): string
    {
        $constant = new \ReflectionClassConstant(UAMS_MRA_Archive::class, 'META_KEY');

        return (string) $constant->getValue();
    }

    /* ---------------------------------------------------------------------
     * ajax_triage
     * ------------------------------------------------------------------ */

    public function test_triage_refuses_a_missing_or_unknown_file(): void
    {
        $post = self::factory()->post->create();

        foreach ([[], ['id' => '0'], ['id' => '99999999'], ['id' => (string) $post], ['id' => 'abc']] as $extra) {
            $answer = $this->ajax('ajax_triage', 'uams_mra_triage', $extra + ['d' => 'delete']);

            $this->assertFalse($answer['success']);
            $this->assertSame(['message' => 'That file no longer exists.'], $answer['data']);
            $this->assertSame(404, $answer['status']);
        }

        $this->assertSame('', (string) get_post_meta($post, UAMS_MRA_Triage::META_DISPOSITION, true));
    }

    public function test_triage_saves_the_mark_and_the_note_and_answers_with_the_counts(): void
    {
        $one = $this->attachment('one.pdf');
        $two = $this->attachment('two.pdf');
        $this->seedResult($one, ['bytes' => 2048]);
        $this->seedResult($two, ['bytes' => 1024]);
        UAMS_MRA_Triage::set($two, 'delete', '');

        $answer = $this->ajax('ajax_triage', 'uams_mra_triage', ['id' => (string) $one, 'd' => 'DELETE', 'note' => '  <b>owner left</b>  ']);

        $this->assertTrue($answer['success']);
        $this->assertSame(['d' => 'delete', 'n' => 'owner left'], $answer['data']['mark']);
        $this->assertSame(2, $answer['data']['counts']['disp']['delete']);
        $this->assertSame(3072, $answer['data']['counts']['delete_bytes']);
        $this->assertSame('Delete the 2 files marked Delete (3.0 KB)', $answer['data']['deleteLabel']);
        $this->assertSame(['d' => 'delete', 'n' => 'owner left'], UAMS_MRA_Triage::get($one));
    }

    public function test_triage_with_no_mark_or_note_clears_what_was_stored(): void
    {
        $id = $this->attachment('one.pdf');
        $this->seedResult($id);
        UAMS_MRA_Triage::set($id, 'keep', 'old note');

        $answer = $this->ajax('ajax_triage', 'uams_mra_triage', ['id' => (string) $id]);

        $this->assertTrue($answer['success']);
        $this->assertSame(['d' => '', 'n' => ''], $answer['data']['mark']);
        $this->assertSame(['d' => '', 'n' => ''], UAMS_MRA_Triage::get($id));
        $this->assertSame('Delete the 0 files marked Delete (0.0 B)', $answer['data']['deleteLabel']);
    }

    /* ---------------------------------------------------------------------
     * ajax_start, ajax_step, progress
     * ------------------------------------------------------------------ */

    public function test_a_scan_starts_and_steps_and_reports_progress(): void
    {
        $one = $this->attachment('scan-me.pdf');
        $this->assertGreaterThan(0, $one);

        $started = $this->ajax('ajax_start', 'uams_mra_scan');

        $this->assertTrue($started['success']);
        $this->assertSame(['step', 'message', 'processed', 'detail', 'done', 'summary'], array_keys($started['data']));
        $this->assertFalse($started['data']['done']);
        $this->assertNull($started['data']['summary']);
        $this->assertSame('', $started['data']['detail']);

        $state = null;

        for ($i = 0; $i < 60; $i++) {
            $state = $this->ajax('ajax_step', 'uams_mra_scan');
            $this->assertTrue($state['success']);

            if ($state['data']['done']) {
                break;
            }
        }

        $this->assertTrue($state['data']['done']);
        $this->assertSame('done', $state['data']['step']);
        $this->assertIsArray($state['data']['summary']);
        $this->assertSame(UAMS_MRA_Store::summary(), $state['data']['summary']);
        $this->assertNotNull($this->resultRow($one));
    }

    public function test_a_scan_is_refused_with_a_409_while_a_repoint_job_is_running(): void
    {
        $this->assertTrue($this->ajax('ajax_repoint_plan', 'uams_mra_repoint')['success']);
        $this->assertTrue(UAMS_MRA_Repoint::is_running());

        $result = $this->request(fn () => $this->admin->ajax_start(), ['nonce' => wp_create_nonce('uams_mra_scan')], [], true);
        $json = json_decode($this->firstAnswer($result['out']), true);

        $this->assertSame(409, $result['status']);
        $this->assertFalse($json['success']);
        $this->assertSame(['message' => 'A repoint or download is still running on the Offsite Media screen. Let it finish, then scan.'], $json['data']);
        $this->assertFalse(get_option(UAMS_MRA_Scanner::STATE_OPTION));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function progressProvider(): array
    {
        return [
            'no state at all is done' => [[], ['step' => 'done', 'message' => '', 'processed' => 0, 'detail' => '', 'done' => true]],
            'a step in progress' => [['step' => 'posts', 'message' => 'Reading posts', 'processed' => '40'], ['step' => 'posts', 'message' => 'Reading posts', 'processed' => 40, 'detail' => '', 'done' => false]],
            'the render step names its pages' => [
                ['step' => 'render', 'message' => 'Fetching', 'processed' => 5, 'render_total' => 10, 'render_queue' => ['a', 'b', 'c']],
                ['step' => 'render', 'message' => 'Fetching', 'processed' => 5, 'detail' => '7 of 10 pages read', 'done' => false],
            ],
            'a queue longer than the total never goes negative' => [
                ['step' => 'render', 'render_total' => 1, 'render_queue' => ['a', 'b', 'c']],
                ['step' => 'render', 'message' => '', 'processed' => 0, 'detail' => '0 of 1 pages read', 'done' => false],
            ],
            'the render step with no queue' => [
                ['step' => 'render', 'render_total' => '4'],
                ['step' => 'render', 'message' => '', 'processed' => 0, 'detail' => '4 of 4 pages read', 'done' => false],
            ],
            'the render step with no total' => [
                ['step' => 'render'],
                ['step' => 'render', 'message' => '', 'processed' => 0, 'detail' => '0 of 0 pages read', 'done' => false],
            ],
            'a queue that is not a list counts as one entry' => [
                ['step' => 'render', 'render_total' => 5, 'render_queue' => 'queued'],
                ['step' => 'render', 'message' => '', 'processed' => 0, 'detail' => '4 of 5 pages read', 'done' => false],
            ],
            'done carries the summary' => [['step' => 'done'], ['step' => 'done', 'message' => '', 'processed' => 0, 'detail' => '', 'done' => true]],
        ];
    }

    /**
     * @dataProvider progressProvider
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $expected
     */
    public function test_progress_shapes_the_scan_state(array $state, array $expected): void
    {
        $method = new ReflectionMethod($this->admin, 'progress');
        $shaped = $method->invoke($this->admin, $state);

        $summary = array_key_exists('summary', $shaped) ? $shaped['summary'] : 'absent';
        unset($shaped['summary']);

        $this->assertSame($expected, $shaped);
        $this->assertEquals($expected['done'] ? UAMS_MRA_Store::summary() : null, $summary);
    }

    /* ---------------------------------------------------------------------
     * Repoint requests
     * ------------------------------------------------------------------ */

    public function test_repoint_plan_runs_the_read_only_pass_and_shapes_the_job(): void
    {
        $answer = $this->ajax('ajax_repoint_plan', 'uams_mra_repoint');

        $this->assertTrue($answer['success']);
        $this->assertSame(['phase', 'message', 'total', 'progress', 'applied', 'skipped', 'failed', 'done'], array_keys($answer['data']));
        $this->assertSame($this->payloadOf(get_option(UAMS_MRA_Repoint::STATE_OPTION)), $answer['data']);
    }

    public function test_repoint_sideload_and_step_answer_with_the_job_they_ran(): void
    {
        $sideload = $this->ajax('ajax_repoint_sideload', 'uams_mra_repoint');

        $this->assertTrue($sideload['success']);
        $this->assertSame($this->payloadOf(get_option(UAMS_MRA_Repoint::STATE_OPTION)), $sideload['data']);

        $step = $this->ajax('ajax_repoint_step', 'uams_mra_repoint');

        $this->assertTrue($step['success']);
        $this->assertSame($this->payloadOf(get_option(UAMS_MRA_Repoint::STATE_OPTION)), $step['data']);
    }

    public function test_repoint_apply_runs_for_a_user_who_may_edit_unfiltered_html(): void
    {
        $answer = $this->ajax('ajax_repoint_apply', 'uams_mra_repoint', ['resized' => '1']);

        $this->assertTrue($answer['success']);
        $this->assertSame($this->payloadOf(get_option(UAMS_MRA_Repoint::STATE_OPTION)), $answer['data']);
    }

    public function test_a_repoint_request_clears_the_last_fatal_record_before_it_works(): void
    {
        update_option(UAMS_MRA_Repoint::FATAL_OPTION, ['message' => 'an earlier fatal']);

        $this->ajax('ajax_repoint_plan', 'uams_mra_repoint');

        $this->assertFalse(get_option(UAMS_MRA_Repoint::FATAL_OPTION));
    }

    public function test_repoint_apply_passes_the_resized_choice_on(): void
    {
        $this->ajax('ajax_repoint_apply', 'uams_mra_repoint', ['resized' => '1']);
        $this->assertSame(1, get_option(UAMS_MRA_Repoint::STATE_OPTION)['resized']);

        delete_option(UAMS_MRA_Repoint::STATE_OPTION);
        $this->ajax('ajax_repoint_apply', 'uams_mra_repoint');
        $this->assertSame(0, get_option(UAMS_MRA_Repoint::STATE_OPTION)['resized']);
    }

    public function test_repoint_apply_refuses_a_user_without_unfiltered_html_and_starts_nothing(): void
    {
        // Mapped away rather than removed from the user, so a single site and a super administrator on a network are refused the same way.
        $deny = static fn (array $caps, string $cap): array => $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps;
        add_filter('map_meta_cap', $deny, 10, 2);

        try {
            $answer = $this->ajax('ajax_repoint_apply', 'uams_mra_repoint', ['resized' => '1']);
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
        }

        $this->assertFalse($answer['success']);
        $this->assertStringStartsWith('Rewriting pages needs the unfiltered_html capability', $answer['data']['message']);
        $this->assertSame(403, $answer['status']);
        $this->assertFalse(get_option(UAMS_MRA_Repoint::STATE_OPTION));
    }

    public function test_a_repoint_failure_is_reported_with_where_it_happened(): void
    {
        $method = new ReflectionMethod($this->admin, 'repoint_respond');

        $_POST = ['nonce' => wp_create_nonce('uams_mra_repoint')];
        $_REQUEST = $_POST;

        $result = $this->request(
            function () use ($method): void {
                $method->invoke($this->admin, static function (): array {
                    throw new RuntimeException('boom');
                });
            },
            $_POST,
            [],
            true
        );

        $json = json_decode($result['out'], true);

        $this->assertSame(500, $result['status']);
        $this->assertFalse($json['success']);
        $this->assertMatchesRegularExpression('/^Stopped: boom \(AdminRequestsTest\.php line \d+\)$/', $json['data']['message']);
    }

    public function test_a_repoint_failure_of_any_throwable_is_caught(): void
    {
        $method = new ReflectionMethod($this->admin, 'repoint_respond');

        $result = $this->request(
            function () use ($method): void {
                $method->invoke($this->admin, static function (): array {
                    throw new \TypeError('wrong type');
                });
            },
            ['nonce' => wp_create_nonce('uams_mra_repoint')],
            [],
            true
        );

        $this->assertStringContainsString('Stopped: wrong type', (string) json_decode($result['out'], true)['data']['message']);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function repointPayloadProvider(): array
    {
        return [
            'no job is done' => [[], ['phase' => 'done', 'message' => '', 'total' => 0, 'progress' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'done' => true]],
            'a job in progress' => [
                ['phase' => 'apply', 'message' => 'Rewriting', 'total' => '9', 'done' => '4', 'applied' => '3', 'skipped' => '1', 'failed' => '2'],
                ['phase' => 'apply', 'message' => 'Rewriting', 'total' => 9, 'progress' => 4, 'applied' => 3, 'skipped' => 1, 'failed' => 2, 'done' => false],
            ],
            'a finished job' => [
                ['phase' => 'done', 'total' => 5, 'done' => 5],
                ['phase' => 'done', 'message' => '', 'total' => 5, 'progress' => 5, 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'done' => true],
            ],
        ];
    }

    /**
     * @dataProvider repointPayloadProvider
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $expected
     */
    public function test_repoint_payload_shapes_a_job(array $job, array $expected): void
    {
        $this->assertSame($expected, (new ReflectionMethod($this->admin, 'repoint_payload'))->invoke($this->admin, $job));
    }

    /**
     * What the browser is told for a stored job.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(mixed $job): array
    {
        return (new ReflectionMethod($this->admin, 'repoint_payload'))->invoke($this->admin, $job);
    }
}
