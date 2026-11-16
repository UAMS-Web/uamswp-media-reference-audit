<?php

/**
 * The rendering and export half of UAMS_MRA_Admin against a real WordPress and database.
 *
 * Each screen is rendered into a buffer from real rows in the real tables, and what
 * a visitor would read is asserted on the markup: which cards, buttons, links and
 * notices appear for an empty, a partial and a full state, and that every stored
 * value is escaped on the way out. The two CSV exports end in `exit`, so they run
 * in a forked process and their bytes are compared with the rows behind them.
 *
 * @see includes/class-admin.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use ReflectionMethod;
use RuntimeException;
use UAMS_MRA_Admin;
use UAMS_MRA_Scanner;
use UAMS_MRA_Selection;
use UAMS_MRA_Store;
use UAMS_MRA_Triage;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class AdminRenderTest extends IntegrationTestCase
{
    private UAMS_MRA_Admin $admin;

    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
        UAMS_MRA_Store::reset();
        delete_option('uams_mra_settings');
        delete_option(UAMS_MRA_Scanner::STATE_OPTION);
        delete_option('uams_mra_repoint_state');
        delete_option('uams_mra_repoint_fatal');

        $this->admin = new UAMS_MRA_Admin;
        $this->login('administrator');

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        $GLOBALS['wp_settings_errors'] = [];
    }

    /**
     * The groups of a pattern that must match the page, or stop the test naming the pattern.
     *
     * @return array<int, string>
     */
    private function match(string $pattern, string $subject): array
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            throw new RuntimeException('The page did not match '.$pattern);
        }

        return $matches;
    }

    /**
     * The query arguments of a URL, each a plain string or the test stops.
     *
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $plain = [];

        foreach ($query as $name => $value) {
            if (! is_string($value)) {
                throw new RuntimeException('The query argument '.$name.' is not a plain string.');
            }

            $plain[(string) $name] = $value;
        }

        return $plain;
    }

    private function login(string $role): void
    {
        $id = self::factory()->user->create(['role' => $role]);

        if ($role === 'administrator' && is_multisite()) {
            grant_super_admin($id);
        }

        wp_set_current_user($id);
    }

    /**
     * Whatever a method printed.
     */
    private function printed(callable $method): string
    {
        ob_start();

        try {
            $method();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    /**
     * Put the request parameters a screen reads in place.
     *
     * @param  array<string, string>  $get
     */
    private function get(array $get): void
    {
        $_GET = $get;
        $_REQUEST = $get;
    }

    private function main(): string
    {
        return $this->printed(fn () => $this->admin->render());
    }

    private function review(): string
    {
        return $this->printed(fn (): mixed => (new ReflectionMethod($this->admin, 'render_review'))->invoke($this->admin));
    }

    private function offsite(): string
    {
        return $this->printed(fn () => $this->admin->render_offsite());
    }

    /**
     * An attachment post, with a title, so a link and a triage mark can sit on it.
     */
    private function attachment(string $title = 'A file'): int
    {
        return self::factory()->attachment->create(['post_title' => $title, 'post_mime_type' => 'application/pdf']);
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
     * Store one offsite row.
     *
     * @param  array<string, mixed>  $over
     */
    private function seedOffsite(array $over = []): void
    {
        global $wpdb;

        static $pk = 5000;
        $pk++;

        $row = array_merge([
            'url' => 'https://old.example.edu/wp-content/uploads/2020/report.pdf',
            'host' => 'old.example.edu',
            'base_norm' => 'report.pdf',
            'source_type' => 'content',
            'source_id' => $pk,
            'source_pk' => $pk,
            'source_label' => 'A page',
        ], $over);

        $row['url_hash'] = md5((string) $row['url']);

        $wpdb->insert(UAMS_MRA_Store::offsite_table(), $row);
    }

    /**
     * Mark the scan as finished a while ago.
     */
    private function scanned(): void
    {
        UAMS_MRA_Scanner::set_state(['step' => 'done', 'finished' => time() - 2 * HOUR_IN_SECONDS]);
    }

    /**
     * The stat cards of a screen, as one line of text each.
     *
     * @return list<string>
     */
    private function cards(string $html, string $class = 'uams-mra-stat'): array
    {
        preg_match_all('/<div class="'.$class.'">(.*?)<\/div>/s', $html, $m);

        return array_map($this->text(...), $m[1]);
    }

    /**
     * Text of a fragment of markup, tags dropped and whitespace squeezed.
     */
    private function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<br />', ' ', $html)))));
    }

    /**
     * The rows of the first table body, as lists of cell text.
     *
     * @return list<list<string>>
     */
    private function tableRows(string $html): array
    {
        $body = $this->match('/<tbody>(.*?)<\/tbody>/s', $html);
        preg_match_all('/<tr>(.*?)<\/tr>/s', $body[1] ?? '', $rows);

        return array_map(function (string $row): array {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return array_map($this->text(...), $cells[1]);
        }, $rows[1]);
    }

    /**
     * Seed the three-row library the main screen tests read.
     *
     * One orphaned image, one unreferenced attached document, one referenced attached file.
     *
     * @return array{int, int, int} The three attachment ids.
     */
    private function library(): array
    {
        $a = $this->attachment('Orphan');
        $b = $this->attachment('Unreferenced');
        $c = $this->attachment('Referenced');

        $this->seedResult($a, ['bytes' => 1000, 'is_attached' => 0, 'mime' => 'image/jpeg', 'title' => 'Orphan']);
        $this->seedResult($b, ['bytes' => 2000, 'is_attached' => 1, 'parent_id' => 7, 'mime' => 'application/pdf', 'title' => 'Unreferenced']);
        $this->seedResult($c, ['bytes' => 4000, 'is_attached' => 1, 'ref_count' => 2, 'ref_count_strict' => 1, 'mime' => 'image/png', 'title' => 'Referenced']);

        $this->scanned();

        return [$a, $b, $c];
    }

    /* ---------------------------------------------------------------------
     * add_menu, is_screen, enqueue
     * ------------------------------------------------------------------ */

    public function test_the_menu_holds_both_screens_under_media_for_the_capability(): void
    {
        $GLOBALS['submenu'] = [];
        $this->admin->add_menu();

        $entries = array_map(
            static fn (array $e): array => [$e[0], $e[1], $e[2]],
            array_values($GLOBALS['submenu']['upload.php'])
        );

        $this->assertSame(
            [
                ['Media Audit', UAMS_MRA_CAP, UAMS_MRA_Admin::SLUG],
                ['Offsite Media', UAMS_MRA_CAP, UAMS_MRA_Admin::SLUG_OFFSITE],
            ],
            $entries
        );
    }

    public function test_the_menu_entries_render_their_own_screen(): void
    {
        global $wp_filter;

        $this->admin->add_menu();

        $this->assertNotNull(get_plugin_page_hook(UAMS_MRA_Admin::SLUG, 'upload.php'));
        $this->assertNotNull(get_plugin_page_hook(UAMS_MRA_Admin::SLUG_OFFSITE, 'upload.php'));

        $main = $wp_filter[get_plugin_page_hookname(UAMS_MRA_Admin::SLUG, 'upload.php')]->callbacks[10];
        $offsite = $wp_filter[get_plugin_page_hookname(UAMS_MRA_Admin::SLUG_OFFSITE, 'upload.php')]->callbacks[10];

        $this->assertSame('render', array_values($main)[0]['function'][1]);
        $this->assertSame('render_offsite', array_values($offsite)[0]['function'][1]);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function screenProvider(): array
    {
        return [
            'main screen' => ['media_page_uams-media-audit', true],
            'offsite screen' => ['media_page_uams-media-audit-offsite', true],
            'another screen' => ['upload.php', false],
            'a prefix of ours' => ['media_page_uams-media-audit-other', false],
            'empty' => ['', false],
        ];
    }

    /**
     * @dataProvider screenProvider
     */
    public function test_a_screen_is_ours_only_when_its_hook_is_one_of_the_two(string $hook, bool $ours): void
    {
        $this->assertSame($ours, (new ReflectionMethod($this->admin, 'is_screen'))->invoke($this->admin, $hook));
    }

    public function test_assets_load_on_our_screens_with_the_script_data(): void
    {
        wp_dequeue_style('uams-mra-admin');
        wp_dequeue_script('uams-mra-admin');
        wp_deregister_style('uams-mra-admin');
        wp_deregister_script('uams-mra-admin');

        $this->admin->enqueue('media_page_uams-media-audit');

        $style = wp_styles()->registered['uams-mra-admin'];
        $script = wp_scripts()->registered['uams-mra-admin'];

        $this->assertSame(UAMS_MRA_URL.'assets/css/admin.css', $style->src);
        $this->assertSame(UAMS_MRA_VERSION, $style->ver);
        $this->assertSame([], $style->deps);
        $this->assertSame(UAMS_MRA_URL.'assets/js/admin.js', $script->src);
        $this->assertSame(UAMS_MRA_VERSION, $script->ver);
        $this->assertSame([], $script->deps);
        $this->assertSame(1, $script->extra['group']);
        $this->assertTrue(wp_style_is('uams-mra-admin', 'enqueued'));
        $this->assertTrue(wp_script_is('uams-mra-admin', 'enqueued'));

        $data = $script->extra['data'];
        $this->assertStringContainsString('var UAMSMRA = ', $data);
        $json = json_decode($this->match('/\{.*\}/s', $data)[0], true);

        $this->assertSame(admin_url('admin-ajax.php'), $json['ajaxUrl']);
        $this->assertSame(1, wp_verify_nonce($json['nonce'], 'uams_mra_scan'));
        $this->assertSame(1, wp_verify_nonce($json['triageNonce'], 'uams_mra_triage'));
        $this->assertSame(1, wp_verify_nonce($json['archiveNonce'], 'uams_mra_archive'));
        $this->assertSame(1, wp_verify_nonce($json['repointNonce'], 'uams_mra_repoint'));
        $this->assertSame(
            ['confirmDelete', 'confirmScan', 'failed', 'noSelection', 'saved', 'saveFailed', 'confirmMarked', 'archiving', 'archiveFailed', 'archiveDone', 'confirmRepoint', 'repointFailed', 'repointResuming', 'repointFatal', 'confirmSideload'],
            array_keys($json['i18n'])
        );
        $this->assertSame('Saved', $json['i18n']['saved']);
        $this->assertSame('Not saved', $json['i18n']['saveFailed']);
        $this->assertSame('Select at least one file first.', $json['i18n']['noSelection']);
        $this->assertStringContainsString('%d', $json['i18n']['repointResuming']);
    }

    public function test_assets_do_not_load_on_another_screen(): void
    {
        wp_dequeue_style('uams-mra-admin');
        wp_dequeue_script('uams-mra-admin');

        $this->admin->enqueue('upload.php');

        $this->assertFalse(wp_style_is('uams-mra-admin', 'enqueued'));
        $this->assertFalse(wp_script_is('uams-mra-admin', 'enqueued'));
    }

    /* ---------------------------------------------------------------------
     * report_delete
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, int>  $result
     * @return list<array<string, mixed>>
     */
    private function reported(array $result): array
    {
        $GLOBALS['wp_settings_errors'] = [];
        (new ReflectionMethod($this->admin, 'report_delete'))->invoke($this->admin, $result);

        return array_values(get_settings_errors('uams-mra'));
    }

    public function test_a_delete_with_nothing_skipped_reports_a_success(): void
    {
        $errors = $this->reported(['deleted' => 1234, 'skipped' => 0, 'bytes' => 2048]);

        $this->assertCount(1, $errors);
        $this->assertSame('uams_mra_deleted', $errors[0]['code']);
        $this->assertSame('success', $errors[0]['type']);
        $this->assertSame('Deleted 1,234 files and freed 2.0 KB.', $errors[0]['message']);
    }

    public function test_a_delete_that_kept_files_warns_and_says_how_many(): void
    {
        $errors = $this->reported(['deleted' => 3, 'skipped' => 2500, 'bytes' => 0]);

        $this->assertSame('warning', $errors[0]['type']);
        $this->assertSame('Deleted 3 files and freed 0.0 B. Kept 2,500 files that still have references. Tick the override box to delete those.', $errors[0]['message']);
    }

    /* ---------------------------------------------------------------------
     * csv_cell
     * ------------------------------------------------------------------ */

    private function cell(mixed $value): string
    {
        return (string) (new ReflectionMethod(UAMS_MRA_Admin::class, 'csv_cell'))->invoke(null, $value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formulaProvider(): array
    {
        return [
            'equals' => ['=SUM(A1)'],
            'plus' => ['+1'],
            'minus' => ['-1'],
            'at' => ['@cmd'],
            'tab' => ["\tcell"],
            'carriage return' => ["\rcell"],
        ];
    }

    /**
     * @dataProvider formulaProvider
     */
    public function test_a_cell_that_a_spreadsheet_would_read_as_a_formula_is_quoted(string $value): void
    {
        $this->assertSame("'".$value, $this->cell($value));
    }

    /**
     * @return array<string, array{string|int|null, string}>
     */
    public static function plainCellProvider(): array
    {
        return [
            'a word' => ['report.pdf', 'report.pdf'],
            'a trigger past the first character' => ['a=b', 'a=b'],
            'a trailing sign' => ['a-', 'a-'],
            'a space first' => [' =x', ' =x'],
            'a digit' => ['1', '1'],
            'empty' => ['', ''],
            'null' => [null, ''],
            'an integer' => [42, '42'],
            'a negative integer' => [-5, "'-5"],
            'a quote first' => ["'x", "'x"],
        ];
    }

    /**
     * @dataProvider plainCellProvider
     */
    public function test_any_other_cell_is_left_as_it_was(mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->cell($value));
    }

    /* ---------------------------------------------------------------------
     * offsite_source_line
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceLine(array $source): string
    {
        return (string) (new ReflectionMethod($this->admin, 'offsite_source_line'))->invoke($this->admin, $source);
    }

    public function test_a_source_that_is_not_a_post_gets_its_label_alone_escaped_and_decoded(): void
    {
        $line = $this->sourceLine(['source_type' => 'option', 'source_id' => 5, 'source_label' => 'Widget &amp; <b>x</b>']);

        $this->assertSame('Widget &amp; &lt;b&gt;x&lt;/b&gt;', $line);
    }

    /**
     * @return list<array{string}>
     */
    public static function postTypeProvider(): array
    {
        return [['content'], ['postmeta'], ['revision'], ['revision_meta']];
    }

    /**
     * @dataProvider postTypeProvider
     */
    public function test_a_source_that_sits_on_a_post_links_to_the_page_and_the_editor(string $type): void
    {
        $post = self::factory()->post->create(['post_title' => 'Linked page', 'post_status' => 'publish']);

        $line = $this->sourceLine(['source_type' => $type, 'source_id' => $post, 'source_label' => 'Linked page']);

        $view = esc_url((string) get_permalink($post));
        $edit = esc_url((string) get_edit_post_link($post));

        $this->assertSame(
            'Linked page <span class="uams-mra-muted">(<a href="'.$view.'" target="_blank" rel="noopener">View</a> &middot; <a href="'.$edit.'" target="_blank" rel="noopener">Edit</a>)</span>',
            $line
        );
    }

    public function test_a_post_source_with_no_id_or_no_such_post_gets_the_label_alone(): void
    {
        $this->assertSame('Gone', $this->sourceLine(['source_type' => 'content', 'source_id' => 0, 'source_label' => 'Gone']));
        $this->assertSame('Gone', $this->sourceLine(['source_type' => 'content', 'source_id' => 999999, 'source_label' => 'Gone']));
    }

    public function test_a_source_the_user_cannot_edit_links_to_the_page_only(): void
    {
        $post = self::factory()->post->create(['post_status' => 'publish']);
        $this->login('subscriber');

        $line = $this->sourceLine(['source_type' => 'content', 'source_id' => $post, 'source_label' => 'L']);

        $this->assertStringContainsString('>View</a>', $line);
        $this->assertStringNotContainsString('Edit', $line);
        $this->assertStringNotContainsString(' &middot; ', $line);
    }

    public function test_a_source_with_neither_link_gets_the_label_alone(): void
    {
        $post = self::factory()->post->create(['post_status' => 'publish']);
        $this->login('subscriber');
        add_filter('post_link', '__return_empty_string');
        add_filter('pre_post_link', '__return_empty_string');
        add_filter('post_type_link', '__return_empty_string');
        add_filter('get_edit_post_link', '__return_empty_string');
        add_filter('page_link', '__return_empty_string');
        add_filter('the_permalink', '__return_empty_string');

        $line = $this->sourceLine(['source_type' => 'content', 'source_id' => $post, 'source_label' => 'Bare']);

        $this->assertSame('Bare', $line);
    }

    /* ---------------------------------------------------------------------
     * render: the main screen
     * ------------------------------------------------------------------ */

    public function test_a_library_never_scanned_shows_the_scan_prompt_and_the_settings_only(): void
    {
        $html = $this->main();

        $this->assertStringContainsString('<div class="wrap uams-mra-wrap">', $html);
        $this->assertStringContainsString('<h1>Media Audit</h1>', $html);
        $this->assertStringContainsString('No scan has finished yet.', $html);
        $this->assertStringNotContainsString('Last scanned', $html);
        $this->assertMatchesRegularExpression('/id="uams-mra-scan">\s*Start scan\s*<\/button>/', $html);
        $this->assertStringNotContainsString('Scan again', $html);
        $this->assertStringContainsString('id="uams-mra-progress" class="uams-mra-progress" hidden', $html);
        $this->assertSame([], $this->cards($html));
        $this->assertStringNotContainsString('uams-mra-tiers', $html);
        $this->assertStringNotContainsString('Archive and delete', $html);
        $this->assertStringNotContainsString('uams_mra_delete_marked', $html);
        $this->assertStringContainsString('id="uams-mra-results-form"', $html);
        $this->assertStringContainsString('Scan settings', $html);
    }

    public function test_a_finished_scan_with_nothing_in_the_results_shows_no_cards_and_no_triage(): void
    {
        $this->scanned();

        $html = $this->main();

        $this->assertStringContainsString('Last scanned 2 hours ago.', $html);
        $this->assertStringNotContainsString('No scan has finished yet.', $html);
        $this->assertMatchesRegularExpression('/id="uams-mra-scan">\s*Scan again\s*<\/button>/', $html);
        $this->assertSame([], $this->cards($html));
        $this->assertStringNotContainsString('Triage', $html);
        $this->assertStringNotContainsString('Archive and delete', $html);
    }

    public function test_results_with_no_finished_scan_show_no_cards_and_no_triage(): void
    {
        $this->library();
        delete_option(UAMS_MRA_Scanner::STATE_OPTION);

        $html = $this->main();

        $this->assertStringContainsString('No scan has finished yet.', $html);
        $this->assertSame([], $this->cards($html));
        $this->assertStringNotContainsString('<h2 class="uams-mra-settings-heading">Triage</h2>', $html);
        $this->assertStringNotContainsString('Archive and delete', $html);
        $this->assertStringNotContainsString('uams_mra_delete_marked', $html);
    }

    public function test_the_summary_cards_state_each_count_and_size(): void
    {
        $this->library();

        $this->assertSame(
            [
                'Media files 3 '.size_format(7000, 1),
                'Not attached 1',
                'Not referenced 2 '.size_format(3000, 1),
                'Safe to review first 1 '.size_format(1000, 1),
            ],
            $this->cards($this->main())
        );
    }

    public function test_the_triage_section_counts_each_tier_within_the_view_on_screen(): void
    {
        $this->library();

        $html = $this->main();

        $this->assertStringContainsString('<h2 class="uams-mra-settings-heading">Triage</h2>', $html);
        $this->assertStringContainsString('These counts cover the filter selected below, not the whole library.', $html);
        $this->assertSame(
            [
                'Verify 0 Attached to a post, and the parent page has not been read. The page may display it in a way a database scan cannot see.',
                'Ask team 0 A document, video, or audio file. These get handed out by email, print, and QR code, where no reference would ever reach the database.',
                'Low risk 1 An image that is either unattached, or attached to a page that was opened and does not display it. Check the page yourself if it varies by date or visitor.',
            ],
            array_map(
                $this->text(...),
                (static function (string $html): array {
                    preg_match_all('/<a class="uams-mra-tier-card[^"]*"\s+href="[^"]*">(.*?)<\/a>/s', $html, $m);

                    return $m[1];
                })($html)
            )
        );

        $this->get(['view' => 'unreferenced']);
        $other = $this->main();

        $this->assertStringContainsString('<strong class="uams-mra-stat-value">1</strong>', $other);
        preg_match_all('/<a class="uams-mra-tier-card uams-mra-tier-(\w+)[^"]*"/', $other, $keys);
        $this->assertSame(['verify', 'ask', 'clear'], $keys[1]);
    }

    public function test_each_tier_card_links_to_its_filter_and_the_current_one_links_back_out(): void
    {
        $this->library();
        $this->get(['view' => 'unreferenced', 'tier' => 'ask']);

        $html = $this->main();

        preg_match_all('/<a class="(uams-mra-tier-card[^"]*)"\s+href="([^"]*)">/', $html, $m);

        $this->assertSame(
            ['uams-mra-tier-card uams-mra-tier-verify', 'uams-mra-tier-card uams-mra-tier-ask is-current', 'uams-mra-tier-card uams-mra-tier-clear'],
            $m[1]
        );

        $base = menu_page_url(UAMS_MRA_Admin::SLUG, false);

        $this->assertSame(esc_url(add_query_arg(['view' => 'unreferenced', 'tier' => 'verify'], $base)), $m[2][0]);
        $this->assertSame(esc_url(add_query_arg(['view' => 'unreferenced', 'tier' => false], $base)), $m[2][1]);
        $this->assertSame(esc_url(add_query_arg(['view' => 'unreferenced', 'tier' => 'clear'], $base)), $m[2][2]);
        $this->assertStringNotContainsString('tier=ask', $m[2][1]);
    }

    public function test_the_filter_form_carries_the_view_and_selects_the_current_tier_and_decision(): void
    {
        [$a] = $this->library();
        UAMS_MRA_Triage::set($a, 'keep', '');
        $this->get(['view' => 'all', 'tier' => 'clear', 'disp' => 'keep']);

        $html = $this->main();

        $this->assertStringContainsString('<input type="hidden" name="page" value="'.UAMS_MRA_Admin::SLUG.'" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="view" value="all" />', $html);

        $tier = $this->match('/<select name="tier".*?<\/select>/s', $html);
        preg_match_all('/<option value="([^"]*)"\s*(selected=\'selected\')?[^>]*>/', $tier[0], $tierOptions, PREG_SET_ORDER);
        $this->assertSame(
            [['', false], ['verify', false], ['ask', false], ['clear', true]],
            array_map(static fn (array $o): array => [$o[1], isset($o[2])], $tierOptions)
        );

        $disp = $this->match('/<select name="disp".*?<\/select>/s', $html);
        preg_match_all('/<option value="([^"]*)"\s*(selected=\'selected\')?[^>]*>\s*(.*?)\s*<\/option>/s', $disp[0], $dispOptions, PREG_SET_ORDER);
        $this->assertSame(
            [['', false, 'Any decision'], ['none', false, 'Undecided (2)'], ['keep', true, 'Keep (1)'], ['ask', false, 'Ask (0)'], ['delete', false, 'Delete (0)']],
            array_map(static fn (array $o): array => [$o[1], $o[2] !== '', $o[3]], $dispOptions)
        );
    }

    public function test_the_clear_link_shows_only_while_a_tier_or_a_decision_is_chosen(): void
    {
        $this->library();

        $this->assertStringNotContainsString('Clear filters', $this->main());

        $this->get(['view' => 'all', 'tier' => 'clear']);
        $withTier = $this->main();
        $this->assertStringContainsString('Clear filters', $withTier);
        $this->assertStringContainsString(esc_url(add_query_arg('view', 'all', menu_page_url(UAMS_MRA_Admin::SLUG, false))), $withTier);

        $this->get(['view' => 'all', 'disp' => 'keep']);
        $this->assertStringContainsString('Clear filters', $this->main());

        $this->get(['view' => 'all', 'tier' => 'clear', 'disp' => 'keep']);
        $this->assertStringContainsString('Clear filters', $this->main());
    }

    public function test_the_marked_files_button_is_hidden_until_something_is_marked_delete(): void
    {
        [$a, $b] = $this->library();

        $none = $this->main();
        $this->assertMatchesRegularExpression('/<p class="uams-mra-marked" hidden>/', $none);
        $this->assertStringContainsString('Delete the 0 files marked Delete (0.0 B)', $this->text($none));

        UAMS_MRA_Triage::set($a, 'delete', '');
        UAMS_MRA_Triage::set($b, 'delete', '');
        $this->get(['view' => 'all']);

        $some = $this->main();
        $this->assertStringContainsString('<p class="uams-mra-marked">', $some);
        $this->assertStringNotContainsString('<p class="uams-mra-marked" hidden>', $some);
        $this->assertStringContainsString('Delete the 2 files marked Delete ('.size_format(3000, 1).')', $this->text($some));
        $this->assertStringContainsString('Acts on every file marked Delete, not on the checkboxes above.', $some);
    }

    public function test_the_marked_files_button_is_absent_when_there_are_no_triage_counts(): void
    {
        $html = $this->main();

        $this->assertStringNotContainsString('uams-mra-marked', $html);
        $this->assertStringNotContainsString('Acts on every file marked Delete', $html);
    }

    public function test_the_results_form_carries_the_nonce_the_view_and_the_table(): void
    {
        $this->library();
        $this->get(['view' => 'referenced']);

        $html = $this->main();

        $this->assertStringContainsString('name="_wpnonce"', $html);
        $this->assertStringContainsString('<input type="hidden" name="page" value="uams-media-audit" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="view" value="referenced" />', $html);
        $this->assertStringContainsString('subsubsub', $html);
        $this->assertStringContainsString('id="uams-mra-search-search-input"', $html);
        $this->assertStringContainsString('Search media', $html);
        $this->assertStringContainsString('wp-list-table', $html);
        $this->assertStringContainsString('<input type="checkbox" name="uams_mra_force" value="1" />', $html);
        $this->assertStringContainsString('Also delete files that still have references. Leave this off unless you have checked each one.', $html);
    }

    public function test_the_csv_link_names_the_view_and_carries_a_nonce(): void
    {
        $this->library();
        $this->get(['view' => 'weak']);

        $html = $this->main();

        $m = $this->match('/<a class="button" href="([^"]*uams_mra_export[^"]*)">\s*Download this list as CSV/', $html);

        $url = html_entity_decode($m[1]);
        $query = $this->queryOf($url);

        $this->assertStringStartsWith(admin_url('admin-post.php'), $url);
        $this->assertSame('uams_mra_export', $query['action']);
        $this->assertSame('weak', $query['view']);
        $this->assertSame(1, wp_verify_nonce($query['_wpnonce'], 'uams_mra_export'));
    }

    public function test_the_archive_section_offers_both_selection_forms_with_the_filter_carried_over(): void
    {
        $this->library();
        $this->get(['view' => 'all', 'tier' => 'clear', 'disp' => 'keep', 's' => 'a "quoted" b']);

        $html = $this->main();

        $this->assertStringContainsString('<h2 class="uams-mra-settings-heading">Archive and delete</h2>', $html);
        $this->assertStringContainsString('The zip is what makes the deletion reversible, so build it first.', $html);

        preg_match_all('/<form method="post" class="uams-mra-select-card">(.*?)<\/form>/s', $html, $forms);
        $this->assertCount(2, $forms[1]);

        [$filter, $paste] = $forms[1];

        foreach (['page' => 'uams-media-audit', 'view' => 'all', 'tier' => 'clear', 'disp' => 'keep', 's' => 'a &quot;quoted&quot; b'] as $name => $value) {
            $this->assertStringContainsString('<input type="hidden" name="'.$name.'" value="'.$value.'" />', $filter);
        }

        $this->assertStringContainsString('name="_wpnonce"', $filter);
        $this->assertStringContainsString('Everything matching this filter', $filter);
        $this->assertMatchesRegularExpression('/name="uams_mra_select_filter" value="1" class="button button-primary">\s*Review these files/', $filter);

        $this->assertStringContainsString('name="_wpnonce"', $paste);
        $this->assertStringContainsString('<input type="hidden" name="page" value="uams-media-audit" />', $paste);
        $this->assertStringNotContainsString('name="view"', $paste);
        $this->assertStringContainsString('A list from somewhere else', $paste);
        $this->assertStringContainsString('<textarea name="pasted"', $paste);
        $this->assertMatchesRegularExpression('/name="uams_mra_select_text" value="1" class="button">\s*Review this list/', $paste);
    }

    public function test_the_archive_form_counts_every_file_the_filter_matches_not_one_page(): void
    {
        $this->library();
        $this->get(['view' => 'all']);

        $m = $this->match('/<form method="post" class="uams-mra-select-card">(.*?)<\/form>/s', $this->main());

        $this->assertStringContainsString('All 3 files currently filtered, not just the ones on this page.', $this->text($m[1]));
    }

    public function test_the_search_term_in_the_archive_form_is_empty_when_there_is_no_search(): void
    {
        $this->library();

        $this->assertStringContainsString('<input type="hidden" name="s" value="" />', $this->main());
    }

    public function test_the_settings_form_shows_what_is_saved(): void
    {
        $this->library();
        update_option('uams_mra_settings', array_merge(uams_mra_default_settings(), [
            'include_numeric_meta' => 0,
            'include_revisions' => 0,
            'include_termmeta' => 1,
            'include_usermeta' => 0,
            'include_comments' => 1,
            'include_render' => 0,
            'include_offsite' => 0,
            'extra_paths' => "media/library\n<x>",
            'batch_render' => 7,
            'render_max_pages' => 321,
            'batch_repoint' => 9,
            'batch_sideload' => 4,
            'sideload_max_mb' => 99,
            'batch_posts' => 111,
            'batch_meta' => 222,
            'batch_index' => 333,
            'batch_finalize' => 444,
        ]));

        $html = $this->main();

        foreach (['include_numeric_meta' => false, 'include_revisions' => false, 'include_termmeta' => true, 'include_usermeta' => false, 'include_comments' => true, 'include_render' => false, 'include_offsite' => false] as $name => $checked) {
            $m = $this->match('/<input type="checkbox" name="'.$name.'" value="1"([^>]*)\/>/', $html);
            $this->assertSame($checked, str_contains($m[1], "checked='checked'"), $name);
        }

        foreach (['batch_render' => [7, 1, 25], 'render_max_pages' => [321, 1, 5000], 'batch_repoint' => [9, 1, 25], 'batch_sideload' => [4, 1, 10], 'sideload_max_mb' => [99, 1, 2048], 'batch_posts' => [111, 10, 10000], 'batch_meta' => [222, 10, 10000], 'batch_index' => [333, 10, 10000], 'batch_finalize' => [444, 10, 10000]] as $name => [$value, $min, $max]) {
            $this->assertStringContainsString('<input type="number" name="'.$name.'" value="'.$value.'" min="'.$min.'" max="'.$max.'" class="small-text" />', $html, $name);
        }

        $this->assertStringContainsString('<textarea id="extra_paths" name="extra_paths" rows="3" class="large-text code">media/library'."\n".'&lt;x&gt;</textarea>', $html);
        $this->assertStringContainsString('Save settings', $html);
        $this->assertStringContainsString('name="uams_mra_save_settings"', $html);
    }

    public function test_the_network_option_shows_on_a_network_only(): void
    {
        $html = $this->main();

        $this->assertSame(is_multisite(), str_contains($html, 'name="include_network"'));
        $this->assertSame(is_multisite(), str_contains($html, 'Other sites in this network.'));
    }

    public function test_the_offsite_setting_links_to_the_offsite_report(): void
    {
        $html = $this->main();

        $this->assertStringContainsString('<a href="'.esc_url(admin_url('upload.php?page='.UAMS_MRA_Admin::SLUG_OFFSITE)).'">Media &gt; Offsite Media</a>', $html);
    }

    public function test_a_parked_notice_is_shown_on_the_main_screen_and_then_forgotten(): void
    {
        update_user_meta(get_current_user_id(), '_uams_mra_notice', ['message' => 'Parked <b>words</b>', 'type' => 'warning']);

        $html = $this->main();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('Parked <b>words</b>', $html);
        $this->assertSame('', (string) get_user_meta(get_current_user_id(), '_uams_mra_notice', true));
    }

    public function test_the_review_action_shows_the_review_screen_instead(): void
    {
        $this->get(['action' => 'review']);

        $html = $this->main();

        $this->assertStringContainsString('<h1>Review selection</h1>', $html);
        $this->assertStringNotContainsString('Scan settings', $html);
        $this->assertStringNotContainsString('<h1>Media Audit</h1>', $html);
    }

    public function test_any_other_action_shows_the_main_screen(): void
    {
        $this->get(['action' => 'something']);

        $this->assertStringContainsString('<h1>Media Audit</h1>', $this->main());
    }

    public function test_a_review_action_in_odd_case_is_read_as_the_review_screen(): void
    {
        $this->get(['action' => 'REVIEW']);

        $this->assertStringContainsString('<h1>Review selection</h1>', $this->main());
    }

    /* ---------------------------------------------------------------------
     * render_review
     * ------------------------------------------------------------------ */

    public function test_an_empty_selection_says_so_and_stops(): void
    {
        $html = $this->review();

        $this->assertStringContainsString('<h1>Review selection</h1>', $html);
        $this->assertStringContainsString('Nothing is selected. Go back and choose a filter or paste a list.', $html);
        $this->assertStringContainsString('<a href="'.esc_url(UAMS_MRA_Admin::url()).'">&larr; Back to results</a>', $html);
        $this->assertStringNotContainsString('uams-mra-stats', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('uams_mra_delete_selection', $html);
        $this->assertSame(1, substr_count($html, '</div>'));
    }

    public function test_a_selection_of_ids_with_no_result_rows_counts_as_empty(): void
    {
        UAMS_MRA_Selection::store([999991, 999992]);

        $this->assertStringContainsString('Nothing is selected.', $this->review());
    }

    /**
     * Seed the selection the review tests read: a referenced attached file, an orphan with a missing file, and a marked one.
     *
     * @return array{int, int, int}
     */
    private function selection(): array
    {
        $a = $this->attachment('Big & <loud>');
        $b = $this->attachment('');
        $c = $this->attachment('Third');

        $this->seedResult($a, ['bytes' => 3000, 'is_attached' => 1, 'ref_count' => 4, 'file' => '2024/05/big.pdf', 'mime' => 'application/pdf', 'title' => 'Big & <loud>']);
        $this->seedResult($b, ['bytes' => 0, 'is_attached' => 0, 'ref_count' => 0, 'file' => '2024/05/gone.jpg', 'mime' => 'image/jpeg', 'title' => '']);
        $this->seedResult($c, ['bytes' => 1500, 'is_attached' => 0, 'ref_count' => 0, 'file' => '2024/05/third.png', 'mime' => 'image/png', 'title' => 'Third']);

        UAMS_MRA_Triage::set($c, 'delete', 'Dup & "x"');
        UAMS_MRA_Selection::store([$a, $b, $c]);

        return [$a, $b, $c];
    }

    public function test_the_review_totals_cover_the_whole_selection(): void
    {
        $this->selection();

        $this->assertSame(
            [
                'Files selected 3',
                'Disk space '.size_format(4500, 1),
                'Still referenced 1 skipped unless overridden',
                'Attached to a post 1',
            ],
            $this->cards($this->review())
        );
    }

    public function test_the_review_warns_of_files_missing_on_disk_only_when_there_are_some(): void
    {
        $this->selection();

        $this->assertStringContainsString('1 of these have no file on disk. They will be left out of the archive but can still be deleted, which clears the orphaned database row.', $this->text($this->review()));

        UAMS_MRA_Selection::store([(int) $GLOBALS['wpdb']->get_var('SELECT attachment_id FROM '.UAMS_MRA_Store::results_table().' WHERE bytes = 3000')]);

        $html = $this->review();
        $this->assertStringNotContainsString('have no file on disk', $html);
        $this->assertStringNotContainsString('notice-warning', $html);
    }

    public function test_the_review_offers_the_archive_build_with_the_part_size(): void
    {
        $this->selection();

        $html = $this->review();

        $this->assertStringNotContainsString('no ZipArchive support', $html);
        $this->assertStringNotContainsString('notice-error', $html);
        $this->assertStringContainsString('<h2>Archive</h2>', $html);
        $this->assertStringContainsString('Split into parts of about '.size_format(200 * MB_IN_BYTES), $this->text($html));
        $this->assertMatchesRegularExpression('/id="uams-mra-archive">\s*Build archive\s*<\/button>/', $html);
        $this->assertStringNotContainsString('Rebuild archive', $html);
        $this->assertStringNotContainsString('Discard this archive', $html);
        $this->assertStringContainsString('id="uams-mra-archive-progress" class="uams-mra-progress" hidden', $html);
        $this->assertStringNotContainsString('Download part', $html);
    }

    public function test_the_part_size_follows_the_filter(): void
    {
        $this->selection();
        add_filter('uams_mra_archive_part_bytes', static fn (): int => 5 * MB_IN_BYTES);

        $this->assertStringContainsString('Split into parts of about '.size_format(5 * MB_IN_BYTES).' so a failed', $this->text($this->review()));
    }

    public function test_a_finished_archive_lists_its_parts_and_offers_a_rebuild_and_a_discard(): void
    {
        $this->selection();
        update_user_meta(get_current_user_id(), '_uams_mra_archive', [
            'id' => 'job1',
            'parts' => [
                1 => ['part' => 1, 'files' => 12, 'bytes' => 2048, 'name' => 'a.zip'],
                2 => ['part' => 2, 'files' => 0, 'bytes' => 0, 'name' => 'b.zip'],
                3 => ['part' => 3, 'files' => 1500, 'bytes' => 3 * MB_IN_BYTES, 'name' => 'c.zip'],
            ],
        ]);

        $html = $this->review();

        preg_match_all('/<a class="button" href="([^"]*)">\s*(.*?)\s*<\/a>/s', $html, $m, PREG_SET_ORDER);

        $this->assertSame(['Download part 1 (12 files, 2.0 KB)', 'Download part 3 (1,500 files, 3.0 MB)'], array_map(fn (array $p): string => $this->text($p[2]), $m));

        foreach ([1, 3] as $index => $part) {
            $url = html_entity_decode($m[$index][1]);
            $query = $this->queryOf($url);

            $this->assertStringStartsWith(admin_url('admin-post.php'), $url);
            $this->assertSame('uams_mra_archive_download', $query['action']);
            $this->assertSame((string) $part, $query['part']);
            $this->assertSame(1, wp_verify_nonce($query['_wpnonce'], 'uams_mra_archive_download'));
        }

        $this->assertMatchesRegularExpression('/id="uams-mra-archive">\s*Rebuild archive\s*<\/button>/', $html);
        $this->assertStringNotContainsString('Build archive', $html);
        $this->assertStringContainsString('<form method="post" class="uams-mra-discard">', $html);
        $this->assertStringContainsString('name="uams_mra_discard_archive" value="1"', $html);
        $this->assertStringContainsString('Discard this archive', $html);
        $this->assertStringContainsString('Archives are deleted automatically after a day.', $html);
        $this->assertStringContainsString('<input type="hidden" name="action" value="review" />', $html);
    }

    public function test_a_job_with_no_parts_lists_none_but_still_offers_the_rebuild(): void
    {
        $this->selection();
        update_user_meta(get_current_user_id(), '_uams_mra_archive', ['id' => 'job1', 'parts' => []]);

        $html = $this->review();

        $this->assertStringNotContainsString('Download part', $html);
        $this->assertMatchesRegularExpression('/id="uams-mra-archive">\s*Rebuild archive/', $html);
        $this->assertStringContainsString('Discard this archive', $html);
    }

    public function test_the_review_table_describes_each_file_with_its_tier_decision_references_and_size(): void
    {
        [$a, $b, $c] = $this->selection();

        $html = $this->review();

        $this->assertSame(
            [
                ['Big & <loud> 2024/05/big.pdf ID '.$a.' · application/pdf', 'Verify', 'Undecided', '4', size_format(3000, 1)],
                ['Third 2024/05/third.png ID '.$c.' · image/png', 'Low risk', 'Delete Dup & "x"', 'None', size_format(1500, 1)],
                ['(no title) 2024/05/gone.jpg ID '.$b.' · image/jpeg', 'Low risk', 'Undecided', 'None', 'File missing'],
            ],
            array_map(
                static fn (array $row): array => $row,
                array_map(fn (array $row): array => $row, $this->tableRows($html))
            )
        );
    }

    public function test_the_review_table_escapes_titles_and_notes_and_links_each_file_to_its_editor(): void
    {
        [$a] = $this->selection();

        $html = $this->review();

        $this->assertStringContainsString('Big &amp; &lt;loud&gt;', $html);
        $this->assertStringNotContainsString('<loud>', $html);
        $this->assertStringContainsString('Dup &amp; &quot;x&quot;', $html);
        $this->assertStringContainsString('<a href="'.esc_url((string) get_edit_post_link($a)).'">Big', $html);
        $this->assertStringContainsString('<span class="uams-mra-tier uams-mra-tier-verify">Verify</span>', $html);
        $this->assertStringContainsString('<span class="uams-mra-badge uams-mra-badge-ok">4</span>', $html);
        $this->assertStringContainsString('<span class="uams-mra-muted">Undecided</span>', $html);
        $this->assertStringContainsString('<span class="uams-mra-muted">None</span>', $html);
        $this->assertStringContainsString('<div class="uams-mra-meta">Dup &amp; &quot;x&quot;</div>', $html);
    }

    public function test_a_decision_without_a_note_shows_no_note_line(): void
    {
        [, , $c] = $this->selection();
        UAMS_MRA_Triage::set($c, 'keep', '');

        $html = $this->review();

        $this->assertStringContainsString('<strong>Keep</strong>', $html);
        $this->assertStringNotContainsString('Dup &amp;', $html);
        $this->assertSame(3, substr_count($html, '<div class="uams-mra-meta">'));
    }

    public function test_the_review_ends_with_the_delete_form(): void
    {
        $this->selection();

        $html = $this->review();

        $this->assertStringContainsString('<form method="post" class="uams-mra-danger">', $html);
        $this->assertStringContainsString('<input type="hidden" name="page" value="uams-media-audit" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="action" value="review" />', $html);
        $this->assertStringContainsString('<input type="checkbox" name="uams_mra_force" value="1" />', $html);
        $this->assertStringContainsString('Also delete the files that still have references.', $html);
        $this->assertStringContainsString('name="uams_mra_delete_selection" value="1" class="button button-secondary" id="uams-mra-delete-selection"', $html);
        $this->assertStringContainsString('Delete these 3 files permanently ('.size_format(4500, 1).')', $this->text($html));
        $this->assertSame(1, substr_count($html, 'name="_wpnonce"'));
    }

    public function test_the_review_shows_parked_messages_and_the_back_link(): void
    {
        $this->selection();
        add_settings_error('uams-mra', 'x', 'A message', 'success');

        $html = $this->review();

        $this->assertStringContainsString('A message', $html);
        $this->assertStringContainsString('<a href="'.esc_url(UAMS_MRA_Admin::url()).'">&larr; Back to results</a>', $html);
    }

    /* ---------------------------------------------------------------------
     * render_offsite
     * ------------------------------------------------------------------ */

    /**
     * Add many offsite rows at once, one distinct file each.
     */
    private function bulkOffsite(int $count, string $host = 'bulk.example.edu'): void
    {
        global $wpdb;

        $table = UAMS_MRA_Store::offsite_table();
        $values = [];

        for ($i = 1; $i <= $count; $i++) {
            $url = 'https://'.$host.'/wp-content/uploads/f'.sprintf('%04d', $i).'.pdf';
            $values[] = $wpdb->prepare('(%s,%s,%s,%s,%s,%d,%d,%s)', $url, md5($url), $host, 'f'.sprintf('%04d', $i).'.pdf', 'content', $i, $i, 'Page '.$i);
        }

        $wpdb->query("INSERT INTO {$table} (url,url_hash,host,base_norm,source_type,source_id,source_pk,source_label) VALUES ".implode(',', $values));
    }

    public function test_a_user_without_the_capability_sees_nothing_on_the_offsite_screen(): void
    {
        $this->seedOffsite();
        $this->login('subscriber');

        $this->assertSame('', $this->offsite());
    }

    public function test_an_empty_offsite_report_says_to_run_a_scan_and_stops(): void
    {
        $html = $this->offsite();

        $this->assertStringContainsString('<h1>Offsite Media</h1>', $html);
        $this->assertStringContainsString('<p class="uams-mra-lede">', $html);
        $this->assertStringContainsString('notice notice-info inline', $html);
        $this->assertStringContainsString('No offsite media found. This report is filled in by the media scan -- run one from <a href="'.esc_url(admin_url('upload.php?page='.UAMS_MRA_Admin::SLUG)).'">Media Audit</a> if you have not yet.', $this->tidy($html));
        $this->assertStringNotContainsString('uams-mra-cards', $html);
        $this->assertStringNotContainsString('Export CSV', $html);
        $this->assertStringNotContainsString('What to do with this', $html);
        $this->assertSame(2, substr_count($html, '</div>'));
    }

    /**
     * Markup with its whitespace squeezed, tags kept.
     */
    private function tidy(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    private function localCopy(string $base, int $id): void
    {
        global $wpdb;

        $wpdb->insert(UAMS_MRA_Store::index_table(), ['attachment_id' => $id, 'path_norm' => '2024/05/'.$base, 'base_norm' => $base]);
    }

    public function test_the_offsite_cards_count_files_places_hosts_and_local_matches(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/x/one.pdf', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf']);
        $this->seedOffsite(['url' => 'https://a.example.edu/x/one.pdf', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf']);
        $this->seedOffsite(['url' => 'https://b.example.edu/x/two.pdf', 'host' => 'b.example.edu', 'base_norm' => 'two.pdf']);
        $this->seedOffsite(['url' => 'https://b.example.edu/x/three.pdf', 'host' => 'b.example.edu', 'base_norm' => 'three.pdf']);
        $this->localCopy('one.pdf', 41);

        $this->assertSame(
            [
                'Offsite files 3',
                'Places they are stored 4',
                'Hosts 2',
                'Already in this library by name 1 A local file shares the filename. It may or may not be the same document.',
            ],
            $this->cards($this->offsite(), 'uams-mra-card')
        );
    }

    public function test_each_host_gets_a_button_with_its_file_count_and_the_chosen_one_is_primary(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf', 'host' => 'a.example.edu']);
        $this->seedOffsite(['url' => 'https://a.example.edu/two.pdf', 'host' => 'a.example.edu']);
        $this->seedOffsite(['url' => 'https://b.example.edu/three.pdf', 'host' => 'b.example.edu']);
        $this->get(['host' => 'b.example.edu']);

        $html = $this->offsite();

        preg_match_all('/<a class="(button[^"]*)"\s+href="([^"]*)">\s*([^<]*?)\s*<span class="uams-mra-muted">\((\d+)\)<\/span>/s', $html, $m, PREG_SET_ORDER);

        $this->assertSame(
            [['button', 'a.example.edu', '2'], ['button button-primary', 'b.example.edu', '1']],
            array_map(static fn (array $b): array => [$b[1], $b[3], $b[4]], $m)
        );
        $this->assertSame(esc_url(add_query_arg(['page' => UAMS_MRA_Admin::SLUG_OFFSITE, 'host' => 'a.example.edu'], admin_url('upload.php'))), $m[0][2]);
        $this->assertSame(esc_url(add_query_arg(['page' => UAMS_MRA_Admin::SLUG_OFFSITE, 'host' => false], admin_url('upload.php'))), $m[1][2]);
    }

    public function test_the_export_link_carries_the_chosen_host_and_a_nonce(): void
    {
        $this->seedOffsite(['url' => 'https://a b.example.edu/one.pdf', 'host' => 'a b.example.edu']);
        $this->get(['host' => 'a b.example.edu']);

        $m = $this->match('/<a class="button" style="float:right"\s+href="([^"]*)">\s*Export CSV/', $this->offsite());

        $url = html_entity_decode($m[1]);
        $query = $this->queryOf($url);

        $this->assertStringStartsWith(admin_url('admin-post.php'), $url);
        $this->assertSame('uams_mra_offsite_export', $query['action']);
        $this->assertSame('a b.example.edu', $query['host']);
        $this->assertStringContainsString('host=a+b.example.edu', $url);
        $this->assertSame(1, wp_verify_nonce($query['_wpnonce'], 'uams_mra_offsite_export'));
    }

    public function test_a_host_that_holds_nothing_is_ignored_and_every_file_is_listed(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf', 'host' => 'a.example.edu']);
        $this->seedOffsite(['url' => 'https://b.example.edu/two.pdf', 'host' => 'b.example.edu']);
        $this->get(['host' => 'nowhere.example.edu']);

        $html = $this->offsite();

        $this->assertCount(2, $this->tableRows($html));
        $this->assertStringNotContainsString('button-primary', $html);

        $m = $this->match('/<a class="button" style="float:right"\s+href="([^"]*)">/', $html);
        $query = $this->queryOf(html_entity_decode($m[1]));
        $this->assertSame('', $query['host'] ?? '');
    }

    public function test_a_host_filter_lists_only_that_hosts_files(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf']);
        $this->seedOffsite(['url' => 'https://b.example.edu/two.pdf', 'host' => 'b.example.edu', 'base_norm' => 'two.pdf']);
        $this->get(['host' => 'b.example.edu']);

        $rows = $this->tableRows($this->offsite());

        $this->assertCount(1, $rows);
        $this->assertSame('two.pdf https://b.example.edu/two.pdf', $rows[0][0]);
        $this->assertSame('b.example.edu', $rows[0][1]);
    }

    public function test_the_file_table_names_each_file_its_host_its_local_copies_and_where_it_is_stored(): void
    {
        $post = self::factory()->post->create(['post_title' => 'The page', 'post_status' => 'publish']);
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf?x=1&y=2', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf', 'source_type' => 'content', 'source_id' => $post, 'source_pk' => $post, 'source_label' => 'The page']);
        $this->seedOffsite(['url' => 'https://a.example.edu/two.pdf', 'host' => 'a.example.edu', 'base_norm' => 'two.pdf', 'source_type' => 'option', 'source_id' => 0, 'source_pk' => 9001, 'source_label' => 'Option <x>']);
        $this->localCopy('one.pdf', 41);
        $this->localCopy('one.pdf', 42);

        $html = $this->offsite();
        $rows = $this->tableRows($html);

        $view = esc_url((string) get_permalink($post));

        $this->assertSame(
            [
                ['one.pdf https://a.example.edu/one.pdf?x=1&y=2', 'a.example.edu', 'Yes #41,42', 'The page (View · Edit)'],
                ['two.pdf https://a.example.edu/two.pdf', 'a.example.edu', 'No', 'Option <x>'],
            ],
            $rows
        );
        $this->assertStringContainsString('<a href="https://a.example.edu/one.pdf?x=1&#038;y=2" target="_blank" rel="noopener"><code>one.pdf</code></a>', $html);
        $this->assertStringContainsString('<span class="uams-mra-badge uams-mra-badge-info">Yes</span>', $html);
        $this->assertStringContainsString('<span class="uams-mra-badge uams-mra-badge-warn">No</span>', $html);
        $this->assertStringContainsString('<a href="'.$view.'" target="_blank" rel="noopener">View</a>', $html);
        $this->assertStringContainsString('Option &lt;x&gt;', $html);
    }

    public function test_a_file_stored_in_many_places_lists_six_and_counts_the_rest(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->seedOffsite(['source_type' => 'option', 'source_id' => $i, 'source_pk' => 8000 + $i, 'source_label' => 'Place '.$i]);
        }

        $html = $this->offsite();
        $rows = $this->tableRows($html);

        $this->assertSame('Place 1 Place 2 Place 3 Place 4 Place 5 Place 6 and 3 more', $rows[0][3]);
        $this->assertStringContainsString('<span class="uams-mra-muted">and 3 more</span>', $html);
    }

    public function test_a_file_stored_in_exactly_six_places_lists_them_all_with_no_more_line(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->seedOffsite(['source_type' => 'option', 'source_id' => $i, 'source_pk' => 8000 + $i, 'source_label' => 'Place '.$i]);
        }

        $rows = $this->tableRows($this->offsite());

        $this->assertSame('Place 1 Place 2 Place 3 Place 4 Place 5 Place 6', $rows[0][3]);
    }

    public function test_the_sources_of_a_file_are_separated_by_line_breaks(): void
    {
        $this->seedOffsite(['source_type' => 'option', 'source_id' => 1, 'source_pk' => 8001, 'source_label' => 'First']);
        $this->seedOffsite(['source_type' => 'option', 'source_id' => 2, 'source_pk' => 8002, 'source_label' => 'Second']);
        $this->seedOffsite(['source_type' => 'option', 'source_id' => 3, 'source_pk' => 8003, 'source_label' => 'Third']);

        $this->assertMatchesRegularExpression('/First<br \/>Second<br \/>Third\s*<\/td>/', $this->offsite());
    }

    public function test_more_than_a_page_of_files_is_paged_with_links(): void
    {
        $this->bulkOffsite(101);

        $html = $this->offsite();

        $this->assertCount(100, $this->tableRows($html));
        $this->assertStringContainsString('<div class="tablenav bottom">', $html);
        $this->assertStringContainsString('page-numbers', $html);
        $this->assertMatchesRegularExpression('/<span aria-current="page" class="page-numbers current">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<a class="page-numbers" href="[^"]*paged=2[^"]*">2<\/a>/', $html);
        $this->assertStringNotContainsString('paged=3', $html);
        $this->assertStringContainsString('&raquo;', $html);

        $this->get(['paged' => '2']);
        $second = $this->offsite();

        $this->assertCount(1, $this->tableRows($second));
        $this->assertMatchesRegularExpression('/<span aria-current="page" class="page-numbers current">2<\/span>/', $second);
        $this->assertStringContainsString('&laquo;', $second);
    }

    public function test_exactly_one_page_of_files_has_no_paging(): void
    {
        $this->bulkOffsite(100);

        $html = $this->offsite();

        $this->assertCount(100, $this->tableRows($html));
        $this->assertStringNotContainsString('tablenav', $html);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function pagedProvider(): array
    {
        return [
            'zero is the first page' => ['0', 1],
            'a negative number is read as its absolute value' => ['-2', 2],
            'text is the first page' => ['abc', 1],
        ];
    }

    /**
     * @dataProvider pagedProvider
     */
    public function test_a_page_number_is_held_to_one_or_more(string $paged, int $expected): void
    {
        $this->bulkOffsite(250);
        $this->get(['paged' => $paged]);

        $html = $this->offsite();

        $this->assertMatchesRegularExpression('/page-numbers current">'.$expected.'</', $html);
        $this->assertSame(sprintf('f%04d.pdf', ($expected - 1) * 100 + 1), explode(' ', $this->tableRows($html)[0][0])[0]);
    }

    public function test_the_closing_help_is_always_shown_under_the_table(): void
    {
        $this->seedOffsite();

        $html = $this->offsite();

        $this->assertStringContainsString('<h2>What to do with this</h2>', $html);
        $this->assertStringContainsString('Nothing on this screen changes anything', $html);
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'));
    }

    public function test_the_repoint_panel_names_the_plugin_version(): void
    {
        $this->seedOffsite();

        $html = $this->offsite();

        $this->assertStringContainsString('Repoint what this library already has', $html);
        $this->assertStringContainsString('plugin v'.UAMS_MRA_VERSION, $this->text($html));
    }

    public function test_a_report_with_nothing_compared_offers_the_first_comparison_and_no_actions(): void
    {
        $this->seedOffsite();

        $html = $this->offsite();

        $this->assertMatchesRegularExpression('/id="uams-mra-repoint-plan">Compare with this library<\/button>/', $html);
        $this->assertStringNotContainsString('Compare again', $html);
        $this->assertStringNotContainsString('uams-mra-verdicts', $html);
        $this->assertStringNotContainsString('uams-mra-repoint-sideload', $html);
        $this->assertStringNotContainsString('uams-mra-repoint-apply', $html);
        $this->assertStringNotContainsString('uams-mra-repoint-resized', $html);
        $this->assertStringNotContainsString('Already done:', $html);
        $this->assertStringNotContainsString('older version of the plugin', $html);
        $this->assertStringNotContainsString('The last attempt died', $html);
        $this->assertStringContainsString('id="uams-mra-repoint-status"', $html);
    }

    /**
     * Give a number of offsite files a verdict.
     */
    private function verdict(string $verdict, int $files, int $rowsEach = 1, int $applied = 0): void
    {
        static $n = 0;

        for ($f = 0; $f < $files; $f++) {
            $n++;

            for ($r = 0; $r < $rowsEach; $r++) {
                $this->seedOffsite(['url' => 'https://v.example.edu/'.$verdict.$n.'.pdf', 'host' => 'v.example.edu', 'base_norm' => $verdict.$n.'.pdf', 'verdict' => $verdict, 'applied' => $applied]);
            }
        }
    }

    public function test_a_compared_report_lists_each_verdict_that_has_files_with_its_meaning(): void
    {
        $this->verdict('identical', 3);
        $this->verdict('differs', 1);
        $this->verdict('nolocal', 2);

        $html = $this->offsite();

        $this->assertMatchesRegularExpression('/id="uams-mra-repoint-plan">Compare again<\/button>/', $html);
        $this->assertStringNotContainsString('Compare with this library', $html);

        $table = $this->match('/<table class="widefat striped uams-mra-verdicts">(.*?)<\/table>/s', $html);
        preg_match_all('/<tr>(.*?)<\/tr>/s', $table[1], $rows);

        $verdicts = \UAMS_MRA_Repoint::verdicts();

        $this->assertSame(
            [
                ['3', 'identical', $verdicts['identical']],
                ['1', 'differs', $verdicts['differs']],
                ['2', 'nolocal', $verdicts['nolocal']],
            ],
            array_map(function (string $row): array {
                preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

                return array_map($this->text(...), $cells[1]);
            }, $rows[1])
        );
    }

    public function test_the_sideload_button_counts_the_files_to_pull(): void
    {
        $this->verdict('nolocal', 3);

        $html = $this->offsite();

        $this->assertStringContainsString('id="uams-mra-repoint-sideload" data-count="3"', $html);
        $this->assertStringContainsString('Pull 3 missing files into this library', $this->text($html));
        $this->assertStringContainsString('Downloads the original behind each URL', $html);
    }

    public function test_no_sideload_button_when_no_file_is_missing(): void
    {
        $this->verdict('identical', 1);

        $this->assertStringNotContainsString('uams-mra-repoint-sideload', $this->offsite());
    }

    public function test_the_apply_button_counts_identical_and_sideloaded_files_and_their_stored_urls(): void
    {
        $this->verdict('identical', 2, 3);
        $this->verdict('sideloaded', 1, 4);
        $this->verdict('resized', 5, 2);

        $html = $this->offsite();

        $this->assertStringContainsString('id="uams-mra-repoint-apply"', $html);
        $this->assertStringContainsString('data-ready="3"', $html);
        $this->assertStringContainsString('Repoint 3 files (10 stored URLs)', $this->text($html));
        $this->assertStringContainsString('Pages are saved through the editor', $html);
    }

    public function test_resized_matches_get_their_own_checkbox_with_a_count(): void
    {
        $this->verdict('identical', 1);
        $this->verdict('resized', 4);

        $html = $this->offsite();

        $this->assertStringContainsString('<input type="checkbox" id="uams-mra-repoint-resized" />', $html);
        $this->assertStringContainsString('Also repoint the 4 resized matches', $this->text($html));
    }

    public function test_no_resized_checkbox_without_resized_matches(): void
    {
        $this->verdict('identical', 1);

        $this->assertStringNotContainsString('uams-mra-repoint-resized', $this->offsite());
    }

    public function test_resized_matches_alone_still_offer_the_apply_button_with_nothing_ready(): void
    {
        $this->verdict('resized', 2);

        $html = $this->offsite();

        $this->assertStringContainsString('uams-mra-repoint-resized', $html);
        $this->assertStringContainsString('id="uams-mra-repoint-apply"', $html);
        $this->assertStringContainsString('data-ready="0"', $html);
        $this->assertStringContainsString('Repoint 0 files (0 stored URLs)', $this->text($html));
    }

    public function test_verdicts_that_cannot_be_applied_offer_no_apply_button(): void
    {
        $this->verdict('differs', 2);
        $this->verdict('ambiguous', 1);

        $html = $this->offsite();

        $this->assertStringContainsString('uams-mra-verdicts', $html);
        $this->assertStringNotContainsString('uams-mra-repoint-apply', $html);
    }

    public function test_rows_already_rewritten_do_not_count_as_ready_or_planned(): void
    {
        $this->verdict('identical', 2, 1, 1);

        $html = $this->offsite();

        $this->assertStringNotContainsString('uams-mra-repoint-apply', $html);
        $this->assertStringNotContainsString('uams-mra-verdicts', $html);
        $this->assertStringContainsString('Compare with this library', $html);
    }

    public function test_the_applied_summary_counts_rewritten_skipped_and_failed_rows(): void
    {
        $this->verdict('identical', 1, 1, 1);
        $this->verdict('identical', 1, 1, 1);
        $this->verdict('identical', 1, 1, 2);
        $this->verdict('identical', 1, 1, 3);
        $this->verdict('identical', 1, 1, 3);
        $this->verdict('identical', 1, 1, 3);

        $this->assertStringContainsString('Already done: 2 rewritten, 1 left alone, 3 failed. Rescan to see the report rebuilt.', $this->text($this->offsite()));
    }

    public function test_the_applied_summary_shows_for_failures_alone_and_for_rewrites_alone(): void
    {
        $this->verdict('identical', 1, 1, 3);
        $this->assertStringContainsString('Already done: 0 rewritten, 0 left alone, 1 failed.', $this->text($this->offsite()));

        UAMS_MRA_Store::reset();
        $this->verdict('identical', 1, 1, 1);
        $this->assertStringContainsString('Already done: 1 rewritten, 0 left alone, 0 failed.', $this->text($this->offsite()));
    }

    public function test_skipped_rows_alone_do_not_trigger_the_applied_summary(): void
    {
        $this->verdict('identical', 1, 1, 2);

        $this->assertStringNotContainsString('Already done:', $this->offsite());
    }

    public function test_a_report_built_by_an_older_version_hides_every_action_and_says_to_rescan(): void
    {
        $this->seedOffsite(['source_pk' => 0, 'verdict' => 'identical']);
        $this->verdict('nolocal', 1);

        $html = $this->offsite();

        $this->assertStringContainsString('notice notice-warning inline', $html);
        $this->assertStringContainsString('This report was built by an older version of the plugin and does not record which database row each URL sits in, so nothing here can be rewritten yet. Run a scan from <a href="'.esc_url(admin_url('upload.php?page='.UAMS_MRA_Admin::SLUG)).'">Media Audit</a> and come back.', $this->tidy($html));
        $this->assertStringNotContainsString('uams-mra-repoint-plan', $html);
        $this->assertStringNotContainsString('uams-mra-verdicts', $html);
        $this->assertStringNotContainsString('uams-mra-repoint-sideload', $html);
        $this->assertStringNotContainsString('uams-mra-repoint-apply', $html);
        $this->assertStringNotContainsString('Where a file of the same name is already in this library', $html);
    }

    public function test_a_current_report_does_not_show_the_rescan_notice(): void
    {
        $this->seedOffsite();

        $html = $this->offsite();

        $this->assertStringNotContainsString('notice-warning', $html);
        $this->assertStringContainsString('Where a file of the same name is already in this library', $html);
    }

    public function test_the_last_fatal_is_shown_with_where_it_happened(): void
    {
        $this->seedOffsite();
        update_option('uams_mra_repoint_fatal', ['message' => 'Allowed memory <size> exhausted', 'file' => '/var/www/wp-content/plugins/x/class-repoint.php', 'line' => 123, 'context' => 'https://old.example.edu/a.pdf']);

        $html = $this->offsite();

        $this->assertStringContainsString('notice notice-error inline', $html);
        $this->assertStringContainsString('The last attempt died. This is what killed it:', $html);
        $this->assertStringContainsString('<code>Allowed memory &lt;size&gt; exhausted</code>', $html);
        $this->assertStringContainsString('class-repoint.php line 123', $this->text($html));
        $this->assertStringNotContainsString('/var/www', $html);
        $this->assertStringContainsString('While handling: <code>https://old.example.edu/a.pdf</code>', $this->tidy($html));
    }

    public function test_a_fatal_with_no_context_shows_no_handling_line(): void
    {
        $this->seedOffsite();
        update_option('uams_mra_repoint_fatal', ['message' => 'Boom', 'file' => 'x.php', 'line' => 1]);

        $html = $this->offsite();

        $this->assertStringContainsString('<code>Boom</code>', $html);
        $this->assertStringNotContainsString('While handling:', $html);
    }

    public function test_a_fatal_with_no_message_is_not_shown(): void
    {
        $this->seedOffsite();
        update_option('uams_mra_repoint_fatal', ['message' => '', 'file' => 'x.php', 'line' => 1]);

        $this->assertStringNotContainsString('The last attempt died', $this->offsite());
    }

    /* ---------------------------------------------------------------------
     * the exports
     * ------------------------------------------------------------------ */

    /**
     * Run an export in a forked process, because it ends in exit(), and return what it streamed.
     *
     * The child shares this process' database connection, so it is stopped by a kill signal in
     * a shutdown function, before any destructor can close that connection under the parent.
     */
    private function streamed(callable $export): string
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Forking a process needs the pcntl and posix extensions.');
        }

        $captured = (string) tempnam(sys_get_temp_dir(), 'mra-export-');
        $pid = pcntl_fork();

        if ($pid === 0) {
            ob_start(static function (string $chunk) use ($captured): string {
                file_put_contents($captured, $chunk, FILE_APPEND);

                return '';
            });
            register_shutdown_function(static function (): void {
                posix_kill(posix_getpid(), SIGKILL);
            });
            set_error_handler(static fn (): bool => true);
            $export();
            posix_kill(posix_getpid(), SIGKILL);
        }

        $this->assertGreaterThan(0, $pid);
        pcntl_waitpid($pid, $status);

        $out = (string) file_get_contents($captured);
        unlink($captured);

        return $out;
    }

    /**
     * Parse CSV the way the export wrote it: no escape character.
     *
     * @return list<list<string|null>>
     */
    private function csv(string $bytes): array
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            throw new RuntimeException('An in-memory stream could not be opened.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /**
     * What a refused export said.
     */
    private function refusal(callable $export): string
    {
        $die = static fn (): callable => static function ($message): never {
            throw new RuntimeException(is_string($message) ? $message : 'not a string');
        };

        add_filter('wp_die_handler', $die);

        try {
            $export();
        } catch (RuntimeException $runtimeException) {
            return $runtimeException->getMessage();
        } finally {
            remove_filter('wp_die_handler', $die);
        }

        return '';
    }

    public function test_the_exports_refuse_a_user_without_the_capability(): void
    {
        $this->login('subscriber');

        $this->assertSame('You do not have permission to export this report.', $this->refusal(fn () => $this->admin->export_csv()));
        $this->assertSame('You do not have permission to export this report.', $this->refusal(fn () => $this->admin->export_offsite_csv()));
    }

    public function test_the_exports_refuse_a_request_with_no_valid_nonce(): void
    {
        $_REQUEST = [];

        $this->assertStringContainsString('The link you followed has expired.', $this->refusal(fn () => $this->admin->export_csv()));
        $this->assertStringContainsString('The link you followed has expired.', $this->refusal(fn () => $this->admin->export_offsite_csv()));

        $_REQUEST['_wpnonce'] = wp_create_nonce('uams_mra_export');
        $this->assertStringContainsString('The link you followed has expired.', $this->refusal(fn () => $this->admin->export_offsite_csv()));
    }

    /**
     * @param  array<string, mixed>  $get
     * @return list<array<int, string|null>>
     */
    private function exportCsv(array $get): array
    {
        $get['_wpnonce'] = wp_create_nonce('uams_mra_export');
        $this->get($get);

        return $this->csv($this->streamed(fn () => $this->admin->export_csv()));
    }

    /**
     * @param  array<string, mixed>  $get
     * @return list<array<int, string|null>>
     */
    private function exportOffsite(array $get = []): array
    {
        $get['_wpnonce'] = wp_create_nonce('uams_mra_offsite_export');
        $this->get($get);

        return $this->csv($this->streamed(fn () => $this->admin->export_offsite_csv()));
    }

    private const RESULT_HEADER = ['attachment_id', 'title', 'file', 'url', 'mime', 'uploaded', 'bytes', 'human_size', 'attached_to', 'parent_status', 'reference_count', 'strong_reference_count', 'references', 'page_check', 'page_check_note', 'page_checked_url', 'page_checked_at', 'tier', 'decision', 'note'];

    public function test_the_results_export_starts_with_the_header_row(): void
    {
        $rows = $this->exportCsv(['view' => 'all']);

        $this->assertSame([self::RESULT_HEADER], $rows);
    }

    public function test_the_results_export_writes_one_row_per_file_with_every_column(): void
    {
        $parent = self::factory()->post->create(['post_title' => 'Parent page']);
        $a = $this->attachment('Attached file');
        $this->seedResult($a, [
            'title' => 'Attached file', 'file' => '2024/05/a.pdf', 'mime' => 'application/pdf', 'post_date' => '2024-05-06 07:08:09', 'bytes' => 2048,
            'parent_id' => $parent, 'parent_status' => 'publish', 'is_attached' => 1, 'ref_count' => 3, 'ref_count_strict' => 2,
            'render_state' => 'absent', 'render_note' => 'Read fine', 'render_url' => 'https://example.org/p/', 'render_checked_at' => '2024-06-07 08:09:10',
        ]);
        global $wpdb;
        $wpdb->insert(UAMS_MRA_Store::refs_table(), ['attachment_id' => $a, 'source_type' => 'content', 'source_id' => 11, 'source_label' => 'Home']);
        $wpdb->insert(UAMS_MRA_Store::refs_table(), ['attachment_id' => $a, 'source_type' => 'postmeta', 'source_id' => 12, 'source_label' => 'Hero']);
        UAMS_MRA_Triage::set($a, 'keep', 'Needed, "quoted"');

        $rows = $this->exportCsv(['view' => 'all']);

        $this->assertSame(
            [
                self::RESULT_HEADER,
                [
                    (string) $a, 'Attached file', '2024/05/a.pdf', (string) wp_get_attachment_url($a), 'application/pdf', '2024-05-06 07:08:09', '2048', '2.0 KB',
                    $parent.' Parent page', 'publish', '3', '2', 'content #11 Home | postmeta #12 Hero',
                    'absent', 'Read fine', 'https://example.org/p/', '2024-06-07 08:09:10', 'ask', 'keep', 'Needed, "quoted"',
                ],
            ],
            $rows
        );
    }

    public function test_an_unattached_unchecked_file_exports_blank_where_it_has_nothing_to_say(): void
    {
        $a = $this->attachment('Loose');
        $this->seedResult($a, ['title' => 'Loose', 'mime' => 'image/png', 'bytes' => 10]);

        $rows = $this->exportCsv(['view' => 'all']);

        $this->assertSame('', $rows[1][8], 'attached_to');
        $this->assertSame('', $rows[1][12], 'references');
        $this->assertSame('', $rows[1][13], 'page_check');
        $this->assertSame('', $rows[1][14]);
        $this->assertSame('', $rows[1][15]);
        $this->assertSame('', $rows[1][16], 'page_checked_at is blank for a file never checked');
        $this->assertSame('clear', $rows[1][17]);
        $this->assertSame('', $rows[1][18]);
        $this->assertSame('', $rows[1][19]);
        $this->assertCount(20, $rows[1]);
    }

    public function test_the_results_export_lists_files_largest_first(): void
    {
        foreach ([300, 900, 100] as $bytes) {
            $this->seedResult($this->attachment('f'.$bytes), ['bytes' => $bytes]);
        }

        $rows = $this->exportCsv(['view' => 'all']);

        $this->assertSame(['900', '300', '100'], array_column(array_slice($rows, 1), 6));
    }

    public function test_the_results_export_holds_to_the_view_asked_for(): void
    {
        $orphan = $this->attachment('o');
        $unref = $this->attachment('u');
        $weak = $this->attachment('w');
        $strong = $this->attachment('s');
        $this->seedResult($orphan, ['is_attached' => 0, 'ref_count' => 0]);
        $this->seedResult($unref, ['is_attached' => 1, 'ref_count' => 0]);
        $this->seedResult($weak, ['is_attached' => 1, 'ref_count' => 2, 'ref_count_strict' => 0]);
        $this->seedResult($strong, ['is_attached' => 1, 'ref_count' => 2, 'ref_count_strict' => 2]);

        $ids = fn (string $view): array => array_map(intval(...), array_column(array_slice($this->exportCsv(['view' => $view]), 1), 0));

        $this->assertEqualsCanonicalizing([$orphan], $ids('orphaned'));
        $this->assertEqualsCanonicalizing([$orphan, $unref], $ids('unreferenced'));
        $this->assertEqualsCanonicalizing([$orphan], $ids('unattached'));
        $this->assertEqualsCanonicalizing([$weak], $ids('weak'));
        $this->assertEqualsCanonicalizing([$weak, $strong], $ids('referenced'));
        $this->assertEqualsCanonicalizing([$orphan, $unref, $weak, $strong], $ids('all'));
    }

    public function test_an_unknown_view_exports_the_orphaned_files(): void
    {
        $orphan = $this->attachment('o');
        $other = $this->attachment('p');
        $this->seedResult($orphan, ['is_attached' => 0, 'ref_count' => 0]);
        $this->seedResult($other, ['is_attached' => 1, 'ref_count' => 5, 'ref_count_strict' => 5]);

        $rows = $this->exportCsv(['view' => 'nonsense']);

        $this->assertSame([(string) $orphan], array_column(array_slice($rows, 1), 0));
    }

    public function test_no_view_exports_the_default_view(): void
    {
        $orphan = $this->attachment('o');
        $other = $this->attachment('p');
        $this->seedResult($orphan, ['is_attached' => 0, 'ref_count' => 0]);
        $this->seedResult($other, ['is_attached' => 1, 'ref_count' => 5, 'ref_count_strict' => 5]);

        $rows = $this->exportCsv([]);

        $this->assertSame(\UAMS_MRA_List_Table::default_view(), 'orphaned');
        $this->assertSame([(string) $orphan], array_column(array_slice($rows, 1), 0));
    }

    public function test_the_results_export_runs_past_one_batch_of_five_hundred(): void
    {
        global $wpdb;

        $table = UAMS_MRA_Store::results_table();
        $values = [];

        for ($i = 1; $i <= 501; $i++) {
            $values[] = '('.(700000 + $i).', '.(1000 - ($i % 7)).')';
        }

        $wpdb->query("INSERT INTO {$table} (attachment_id, bytes) VALUES ".implode(',', $values));

        $rows = $this->exportCsv(['view' => 'all']);

        $this->assertCount(502, $rows);
        $this->assertCount(501, array_unique(array_column(array_slice($rows, 1), 0)));
    }

    public function test_the_results_export_of_exactly_five_hundred_stops_cleanly(): void
    {
        global $wpdb;

        $table = UAMS_MRA_Store::results_table();
        $values = [];

        for ($i = 1; $i <= 500; $i++) {
            $values[] = '('.(700000 + $i).', 10)';
        }

        $wpdb->query("INSERT INTO {$table} (attachment_id, bytes) VALUES ".implode(',', $values));

        $this->assertCount(501, $this->exportCsv(['view' => 'all']));
    }

    public function test_the_offsite_export_starts_with_the_header_row(): void
    {
        $this->assertSame([['file', 'url', 'host', 'occurrences', 'source_types', 'local_attachment_ids', 'stored_in']], $this->exportOffsite());
    }

    public function test_the_offsite_export_writes_one_row_per_file(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf', 'source_type' => 'content', 'source_id' => 4, 'source_pk' => 4, 'source_label' => 'Home']);
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf', 'source_type' => 'option', 'source_id' => 0, 'source_pk' => 9, 'source_label' => 'Widget']);
        $this->localCopy('one.pdf', 41);
        $this->localCopy('one.pdf', 42);

        $this->assertSame(
            [
                ['file', 'url', 'host', 'occurrences', 'source_types', 'local_attachment_ids', 'stored_in'],
                ['one.pdf', 'https://a.example.edu/one.pdf', 'a.example.edu', '2', 'content, option', '41,42', 'content #4 Home | option #0 Widget'],
            ],
            $this->exportOffsite()
        );
    }

    public function test_the_offsite_export_has_an_empty_local_column_when_nothing_matches(): void
    {
        $this->seedOffsite();

        $this->assertSame('', $this->exportOffsite()[1][5]);
    }

    public function test_the_offsite_export_neutralizes_cells_that_read_as_formulas(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/=cmd.pdf', 'host' => '@evil', 'base_norm' => '=cmd.pdf', 'source_label' => '+Label']);

        $row = $this->exportOffsite()[1];

        $this->assertSame("'=cmd.pdf", $row[0]);
        $this->assertSame('https://a.example.edu/=cmd.pdf', $row[1]);
        $this->assertSame("'@evil", $row[2]);
        $this->assertSame('content #'.$this->lastSourceId().' +Label', $row[6]);
    }

    private function lastSourceId(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT source_id FROM '.UAMS_MRA_Store::offsite_table().' ORDER BY offsite_id DESC LIMIT 1');
    }

    public function test_the_offsite_export_holds_to_the_chosen_host(): void
    {
        $this->seedOffsite(['url' => 'https://a.example.edu/one.pdf', 'host' => 'a.example.edu', 'base_norm' => 'one.pdf']);
        $this->seedOffsite(['url' => 'https://b.example.edu/two.pdf', 'host' => 'b.example.edu', 'base_norm' => 'two.pdf']);

        $rows = $this->exportOffsite(['host' => 'b.example.edu']);

        $this->assertSame(['two.pdf'], array_column(array_slice($rows, 1), 0));
        $this->assertSame(['one.pdf', 'two.pdf'], array_column(array_slice($this->exportOffsite(), 1), 0));
    }

    public function test_the_offsite_export_caps_the_places_listed_per_file_at_fifty(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->seedOffsite(['source_type' => 'option', 'source_id' => $i, 'source_pk' => 8000 + $i, 'source_label' => 'P'.$i]);
        }

        $row = $this->exportOffsite()[1];

        $this->assertSame('52', $row[3]);
        $this->assertCount(50, explode(' | ', (string) $row[6]));
    }

    public function test_the_offsite_export_runs_past_one_batch_of_two_hundred(): void
    {
        $this->bulkOffsite(201);

        $rows = $this->exportOffsite();

        $this->assertCount(202, $rows);
        $this->assertCount(201, array_unique(array_column(array_slice($rows, 1), 0)));
    }

    public function test_the_offsite_export_of_exactly_two_hundred_stops_cleanly(): void
    {
        $this->bulkOffsite(200);

        $this->assertCount(201, $this->exportOffsite());
    }

    /* ---------------------------------------------------------------------
     * Text, wiring and caches the first pass did not pin
     * ------------------------------------------------------------------ */

    public function test_the_main_screen_prints_each_explanation_and_label(): void
    {
        $this->library();

        $text = $this->text($this->main());

        foreach ([
            'Every file gets a tier from the scan and a decision from you.',
            'Filter by tier',
            'Filter by decision',
            'Paste attachment IDs, filenames, or URLs from a content audit',
            'Category, tag, and taxonomy fields.',
            'User profile fields.',
            'After the database scan, open the published page each unexplained file',
            'This is the only way to see a file a template lists at render time',
            'Offsite media',
            'While scanning, record uploads URLs that point at another site.',
            'Extra upload paths',
        ] as $line) {
            $this->assertStringContainsString($line, $text);
        }
    }

    public function test_the_undecided_filter_option_is_selected_when_that_filter_is_on(): void
    {
        $this->library();
        $this->get(['view' => 'all', 'disp' => 'none']);

        $this->assertMatchesRegularExpression('/<option value="none"\s+selected=\'selected\'>/', $this->main());

        $this->get(['view' => 'all']);
        $this->assertDoesNotMatchRegularExpression('/<option value="none"\s+selected/', $this->main());
    }

    public function test_the_results_form_names_its_page_and_view_in_two_adjacent_fields(): void
    {
        $this->library();
        $this->get(['view' => 'referenced']);

        $m = $this->match('/<form method="post" id="uams-mra-results-form">(.*?)<\/form>/s', $this->main());

        $this->assertStringContainsString('<input type="hidden" name="page" value="uams-media-audit" /><input type="hidden" name="view" value="referenced" />', $m[1]);
    }

    public function test_the_settings_form_carries_its_own_nonce(): void
    {
        $html = $this->main();

        preg_match_all('/name="_wpnonce" value="(\w+)"/', $html, $m);

        $this->assertContains(1, array_map(static fn (string $nonce): int|false => wp_verify_nonce($nonce, 'uams_mra_settings'), $m[1]));
    }

    public function test_the_offsite_screen_prints_its_lede_and_column_headings(): void
    {
        $this->seedOffsite();

        $html = $this->offsite();

        $this->assertStringContainsString('Media this site displays but does not host: uploads URLs stored in your', $this->text($html));
        $this->assertStringContainsString('<th scope="col" style="width:14em">Host</th>', $html);
        $this->assertStringContainsString('<th scope="col" style="width:12em">Local copy by name</th>', $html);
    }

    public function test_the_review_primes_the_meta_cache_in_one_batch_for_the_rows_it_shows(): void
    {
        [$a, $b, $c] = $this->selection();
        $primed = [];
        add_filter('update_post_metadata_cache', static function ($check, $ids) use (&$primed) {
            $primed[] = $ids;

            return $check;
        }, 10, 2);

        $this->review();

        $batches = array_values(array_filter($primed, static fn (array $ids): bool => count($ids) > 1));

        $this->assertCount(1, $batches, 'one batched read for every row, apart from the per-file reads of the triage marks');
        $read = [];

        foreach ($batches[0] as $id) {
            $read[] = (int) $id;
        }

        $this->assertEqualsCanonicalizing([$a, $b, $c], $read);
    }

    public function test_the_review_of_an_empty_selection_primes_nothing(): void
    {
        $primed = 0;
        add_filter('update_post_metadata_cache', static function ($check) use (&$primed) {
            $primed++;

            return $check;
        });

        $this->review();

        $this->assertSame(0, $primed);
    }

    public function test_the_results_export_lists_at_most_ten_references_per_file(): void
    {
        global $wpdb;

        $a = $this->attachment('Many');
        $this->seedResult($a, ['title' => 'Many']);

        for ($i = 1; $i <= 12; $i++) {
            $wpdb->insert(UAMS_MRA_Store::refs_table(), ['attachment_id' => $a, 'source_type' => 'content', 'source_id' => $i, 'source_label' => 'R'.$i]);
        }

        $labels = explode(' | ', (string) $this->exportCsv(['view' => 'all'])[1][12]);

        $this->assertCount(10, $labels);
        $this->assertSame('content #10 R10', $labels[9]);
    }

    public function test_a_source_that_is_not_a_post_gets_its_label_alone_even_when_its_id_is_a_post(): void
    {
        $post = self::factory()->post->create(['post_status' => 'publish']);

        $this->assertSame('Widget', $this->sourceLine(['source_type' => 'option', 'source_id' => $post, 'source_label' => 'Widget']));
        $this->assertSame('Term', $this->sourceLine(['source_type' => 'termmeta', 'source_id' => $post, 'source_label' => 'Term']));
    }

    /* ---------------------------------------------------------------------
     * Second pass: what a mutation run found unpinned
     * ------------------------------------------------------------------ */

    public function test_every_checkbox_of_the_settings_form_is_checked_when_its_setting_is_on(): void
    {
        update_option('uams_mra_settings', array_merge(uams_mra_default_settings(), array_fill_keys([
            'include_numeric_meta', 'include_revisions', 'include_termmeta', 'include_usermeta', 'include_comments',
            'include_render', 'include_offsite', 'include_network',
        ], 1)));

        $html = $this->main();

        $names = ['include_numeric_meta', 'include_revisions', 'include_termmeta', 'include_usermeta', 'include_comments', 'include_render', 'include_offsite'];

        if (is_multisite()) {
            $names[] = 'include_network';
        }

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/<input type="checkbox" name="'.$name.'" value="1"\s+checked=\'checked\'\s*\/>/', $html, $name);
        }
    }

    public function test_the_settings_form_labels_every_option_and_group(): void
    {
        $html = $this->main();

        foreach (['Where to look', 'Read the page itself', 'Offsite media', 'Batch sizes'] as $heading) {
            $this->assertStringContainsString('<th scope="row">'.$heading.'</th>', $html, $heading);
        }

        foreach ([
            'include_numeric_meta' => 'Count a custom field that stores only an ID as a reference.',
            'include_revisions' => 'Count references found in post revisions.',
            'include_termmeta' => 'Category, tag, and taxonomy fields.',
            'include_usermeta' => 'User profile fields.',
            'include_comments' => 'Comment fields.',
            'include_render' => 'After the database scan, open the published page each unexplained file is attached to',
            'include_offsite' => 'While scanning, record uploads URLs that point at another site.',
        ] as $name => $label) {
            $this->assertMatchesRegularExpression('/<input type="checkbox" name="'.$name.'" value="1"[^>]*\/>\s*'.preg_quote($label, '/').'/', $html, $name);
        }

        foreach ([
            'batch_render' => 'Pages per batch',
            'render_max_pages' => 'Most pages per scan',
            'batch_repoint' => 'Offsite files compared or rewritten per batch',
            'batch_sideload' => 'Files downloaded per batch',
            'sideload_max_mb' => 'Largest file to pull over (MB)',
            'batch_posts' => 'Posts',
            'batch_meta' => 'Meta rows',
            'batch_index' => 'Index',
            'batch_finalize' => 'Results',
        ] as $name => $label) {
            $this->assertStringContainsString('<label>'.$label.' <input type="number" name="'.$name.'"', $html, $name);
        }

        $text = $this->text($html);

        $this->assertStringContainsString('Count a custom field that stores only an ID as a reference. Keep this on for', $text);
        $this->assertStringContainsString('One path per line. Add the path a CDN or offload plugin rewrites media to, for example media/library. The default uploads path is always checked.', $text);
        $this->assertStringContainsString('Lower these if the scan times out on shared hosting.', $text);
        $this->assertStringContainsString('<h2>Scan</h2>', $html);
        $this->assertStringContainsString('Scans every post, custom field, theme setting, widget, and menu on this site, then reports which media files nothing points to. Nothing is deleted until you choose to delete it.', $text);
    }

    public function test_the_network_option_is_labeled_and_wired_on_a_network(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network.');
        }

        $this->assertMatchesRegularExpression(
            '/<input type="checkbox" name="include_network" value="1"[^>]*\/>\s*Other sites in this network\. Slower, but it catches images this site hosts and another site displays\./',
            $this->main()
        );
    }

    public function test_the_stat_cards_carry_a_second_line_only_where_there_is_one(): void
    {
        $this->library();

        $cards = $this->cards($this->main());

        $this->assertCount(4, $cards);
        $this->assertSame('Not attached 1', $cards[1]);
        $this->assertStringStartsWith('Media files 3 ', $cards[0]);
        $this->assertGreaterThan(strlen('Media files 3 '), strlen($cards[0]));
    }

    public function test_the_tier_cards_print_no_stray_address_and_the_filter_form_has_one_unwrapped_button(): void
    {
        $this->library();
        $this->get(['view' => 'unreferenced', 'tier' => 'ask']);

        $html = $this->main();

        $this->assertStringNotContainsString('page=uams-media-audit', $this->text($html));

        $form = $this->match('/<form[^>]*class="uams-mra-filters"[^>]*>(.*?)<\/form>/s', $html);
        $filter = $form[1] ?? $html;

        $this->assertStringContainsString('<input type="submit" class="button" value="Filter"  />', $filter);
        $this->assertStringNotContainsString('<p class="submit">', $filter);
        $this->assertStringContainsString('<option value="">Every tier</option>', $filter);
        $this->assertStringContainsString('<a class="button-link" href="'.esc_url(add_query_arg('view', 'unreferenced', menu_page_url(UAMS_MRA_Admin::SLUG, false))).'">', $filter);
        $this->assertDoesNotMatchRegularExpression('/<a class="button-link" href="[^"]*tier=/', $filter);
    }

    public function test_the_results_form_nonce_is_the_bulk_action_nonce(): void
    {
        $this->library();

        $m = $this->match('/<form method="post" id="uams-mra-results-form">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="(\w+)"/', $this->main());

        $this->assertSame(1, wp_verify_nonce($m[1], 'bulk-media_items'));
    }

    public function test_the_marked_files_button_shows_for_exactly_one_file_marked_delete(): void
    {
        [$a] = $this->library();
        UAMS_MRA_Triage::set($a, 'delete', '');

        $this->assertDoesNotMatchRegularExpression('/<p class="uams-mra-marked" hidden>/', $this->main());
        $this->assertStringContainsString('<p class="uams-mra-marked">', $this->main());
    }

    public function test_a_job_without_a_parts_list_lists_no_download(): void
    {
        $this->selection();
        update_user_meta(get_current_user_id(), '_uams_mra_archive', ['id' => 'job1']);

        $html = $this->review();

        $this->assertStringNotContainsString('Download part', $html);
        $this->assertStringContainsString('Discard this archive', $html);
    }

    public function test_the_review_table_has_its_five_headings_and_the_discard_form_its_nonce(): void
    {
        $this->selection();
        update_user_meta(get_current_user_id(), '_uams_mra_archive', ['id' => 'job1', 'parts' => []]);

        $html = $this->review();

        foreach (['File', 'Tier', 'Decision', 'References', 'Disk space'] as $heading) {
            $this->assertStringContainsString('<th scope="col">'.$heading.'</th>', $html, $heading);
        }

        $m = $this->match('/<form method="post" class="uams-mra-discard">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="(\w+)"/', $html);

        $this->assertSame(1, wp_verify_nonce($m[1], 'uams_mra_review'));
    }

    public function test_a_file_with_one_reference_gets_the_referenced_badge(): void
    {
        $id = $this->attachment('One ref');
        $this->seedResult($id, ['ref_count' => 1, 'is_attached' => 1, 'mime' => 'application/pdf', 'title' => 'One ref']);
        UAMS_MRA_Selection::store([$id]);

        $this->assertStringContainsString('<span class="uams-mra-badge uams-mra-badge-ok">1</span>', $this->review());
    }

    public function test_the_results_export_of_the_unattached_view_holds_only_unattached_files(): void
    {
        $a = $this->attachment('Loose');
        $b = $this->attachment('Held');
        $this->seedResult($a, ['is_attached' => 0, 'ref_count' => 3, 'title' => 'Loose']);
        $this->seedResult($b, ['is_attached' => 1, 'ref_count' => 0, 'title' => 'Held']);

        $rows = $this->exportCsv(['view' => 'unattached']);

        $this->assertSame([(string) $a], array_column(array_slice($rows, 1), 0));
    }

    public function test_the_results_export_primes_the_meta_cache_in_one_batch(): void
    {
        [$a, $b, $c] = $this->library();
        $log = (string) tempnam(sys_get_temp_dir(), 'mra');
        add_filter('update_post_metadata_cache', static function ($check, $ids) use ($log) {
            file_put_contents($log, implode(',', $ids)."\n", FILE_APPEND);

            return $check;
        }, 10, 2);

        $this->exportCsv(['view' => 'all']);

        $batches = array_values(array_filter(
            explode("\n", trim((string) file_get_contents($log))),
            static fn (string $line): bool => str_contains($line, ',')
        ));
        unlink($log);

        $this->assertCount(1, $batches, 'the export reads the post meta of its rows in one batch');
        $this->assertEqualsCanonicalizing([$a, $b, $c], array_map(intval(...), explode(',', $batches[0])));
    }

    public function test_a_file_stored_in_seven_places_lists_six_and_says_one_more(): void
    {
        $url = 'https://old.example.edu/wp-content/uploads/seven.pdf';

        for ($i = 1; $i <= 7; $i++) {
            $this->seedOffsite(['url' => $url, 'base_norm' => 'seven.pdf', 'source_label' => 'Place '.$i]);
        }

        $this->assertStringContainsString('and 1 more', $this->text($this->offsite()));
    }

    public function test_the_paging_links_are_the_bare_arrows(): void
    {
        $this->bulkOffsite(201);

        $this->assertMatchesRegularExpression('/<a class="next page-numbers" href="[^"]*">&raquo;<\/a>/', $this->offsite());

        $this->get(['paged' => '2']);

        $this->assertMatchesRegularExpression('/<a class="prev page-numbers" href="[^"]*">&laquo;<\/a>/', $this->offsite());
    }

    public function test_the_offsite_export_runs_past_a_second_batch(): void
    {
        $this->bulkOffsite(450);

        $rows = $this->exportOffsite();

        $this->assertCount(451, $rows);
        $this->assertCount(450, array_unique(array_column(array_slice($rows, 1), 0)));
    }

    public function test_a_source_label_keeps_a_named_entity_as_the_character_it_names(): void
    {
        $line = $this->sourceLine(['source_type' => 'option', 'source_id' => 1, 'source_label' => "Menu\u{00A0}item &nbsp; &copy;"]);

        $this->assertSame("Menu\u{00A0}item \u{00A0} \u{00A9}", $line);
    }

    public function test_a_csv_download_is_named_after_the_site_and_the_day(): void
    {
        $name = new ReflectionMethod(UAMS_MRA_Admin::class, 'csv_filename');
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $day = gmdate('Ymd');

        $this->assertSame('uams-media-audit-'.$host.'-all-'.$day.'.csv', $name->invoke(null, '', 'all-'));
        $this->assertSame('uams-media-audit-offsite-'.$host.'-'.$day.'.csv', $name->invoke(null, 'offsite-', ''));
    }

    public function test_a_file_whose_post_is_gone_still_gets_a_row_with_an_empty_editor_link(): void
    {
        $id = $this->attachment('Gone');
        $this->seedResult($id, ['title' => 'Gone', 'mime' => 'application/pdf']);
        UAMS_MRA_Selection::store([$id]);
        wp_delete_post($id, true);

        $this->assertStringContainsString('<strong><a href="">Gone</a></strong>', $this->review());
    }

    public function test_a_stat_card_without_a_second_line_prints_no_empty_note(): void
    {
        $this->library();

        preg_match_all('/<div class="uams-mra-stat">(.*?)<\/div>/s', $this->main(), $m);

        $this->assertStringNotContainsString('uams-mra-muted', $m[1][1]);
        $this->assertStringContainsString('uams-mra-muted', $m[1][0]);
    }

    public function test_the_offsite_table_names_its_file_and_stored_in_columns(): void
    {
        $this->seedOffsite();

        $html = $this->offsite();

        $this->assertStringContainsString('<th scope="col">File</th>', $html);
        $this->assertStringContainsString('<th scope="col">Stored in</th>', $html);
    }

    /**
     * An admin that writes each header it is asked to send to a file, so a forked export can be read back.
     */
    private function headerSpy(string $log): UAMS_MRA_Admin
    {
        return new class($log) extends UAMS_MRA_Admin
        {
            public function __construct(private readonly string $log)
            {
                parent::__construct();
            }

            protected function send_no_cache_headers(): void
            {
                file_put_contents($this->log, "nocache\n", FILE_APPEND);
            }

            protected function send_header(string $line): void
            {
                file_put_contents($this->log, $line."\n", FILE_APPEND);
            }
        };
    }

    /**
     * @return list<string> What the export asked for, in order.
     */
    private function sentBy(callable $export): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'mra-headers-');
        $this->admin = $this->headerSpy($log);
        $this->streamed($export);

        $sent = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        unlink($log);

        return $sent;
    }

    public function test_the_results_export_sends_no_cache_headers_then_the_csv_type_and_the_file_name(): void
    {
        $get = ['view' => 'all', '_wpnonce' => wp_create_nonce('uams_mra_export')];
        $this->get($get);

        $this->assertSame(
            [
                'nocache',
                'Content-Type: text/csv; charset=utf-8',
                'Content-Disposition: attachment; filename=uams-media-audit-'.wp_parse_url(home_url(), PHP_URL_HOST).'-all-'.gmdate('Ymd').'.csv',
            ],
            $this->sentBy(fn () => $this->admin->export_csv())
        );
    }

    public function test_the_offsite_export_sends_no_cache_headers_then_the_csv_type_and_the_file_name(): void
    {
        $this->get(['_wpnonce' => wp_create_nonce('uams_mra_offsite_export')]);

        $this->assertSame(
            [
                'nocache',
                'Content-Type: text/csv; charset=utf-8',
                'Content-Disposition: attachment; filename=uams-media-audit-offsite-'.wp_parse_url(home_url(), PHP_URL_HOST).'-'.gmdate('Ymd').'.csv',
            ],
            $this->sentBy(fn () => $this->admin->export_offsite_csv())
        );
    }
}
