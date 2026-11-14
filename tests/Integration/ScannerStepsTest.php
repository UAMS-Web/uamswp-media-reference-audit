<?php

/**
 * The scan steps of UAMS_MRA_Scanner against a real WordPress and database.
 *
 * Every step reads real rows and writes the real tables, so the cursor, the batch
 * limit, the source labels and the evidence strength are all asserted on what ends
 * up stored. The one HTTP call, the render check, is answered through
 * `pre_http_request`.
 *
 * @see includes/class-scanner.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Index;
use UAMS_MRA_Scanner;
use UAMS_MRA_Store;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class ScannerStepsTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
        UAMS_MRA_Store::reset();
        delete_option(UAMS_MRA_Scanner::STATE_OPTION);
        delete_option('uams_mra_repoint_state');
    }

    /**
     * A scan state at one step, with default settings and any overrides.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function state(string $step, array $settings = [], array $extra = []): array
    {
        return array_merge([
            'step' => $step,
            'cursor' => 0,
            'processed' => 0,
            'attachments' => 0,
            'started' => time(),
            'finished' => 0,
            'site_queue' => [],
            'render_queue' => [],
            'settings' => array_merge(uams_mra_default_settings(), $settings),
            'counts' => [],
            'message' => '',
        ], $extra);
    }

    /**
     * Store a state and run one batch.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function scanStep(array $state): array
    {
        UAMS_MRA_Scanner::set_state($state);

        return UAMS_MRA_Scanner::step();
    }

    /**
     * An attachment whose stored file is the given upload path, indexed.
     */
    private function attachment(string $file, int $parent = 0, string $title = ''): int
    {
        $args = ['post_mime_type' => 'image/jpeg', 'post_parent' => $parent];

        if ($title !== '') {
            $args['post_title'] = $title;
        }

        $id = self::factory()->attachment->create($args);

        update_post_meta($id, '_wp_attached_file', $file);
        UAMS_MRA_Index::build_batch($id - 1, 1);

        return $id;
    }

    /**
     * The URL of a file under this site's uploads.
     */
    private function url(string $file): string
    {
        return wp_get_upload_dir()['baseurl'].'/'.$file;
    }

    /**
     * Every stored reference, in insertion order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function refs(): array
    {
        global $wpdb;

        return (array) $wpdb->get_results('SELECT attachment_id, source_type, source_id, source_label, site_id, confidence FROM '.UAMS_MRA_Store::refs_table().' ORDER BY ref_id ASC', ARRAY_A);
    }

    /**
     * Every stored offsite hit, in insertion order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function offsite(): array
    {
        global $wpdb;

        return (array) $wpdb->get_results('SELECT url, host, base_norm, source_type, source_id, source_pk, source_key, source_label FROM '.UAMS_MRA_Store::offsite_table().' ORDER BY offsite_id ASC', ARRAY_A);
    }

    /**
     * One results row.
     *
     * @return array<string, mixed>
     */
    private function result(int $attachment_id): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.UAMS_MRA_Store::results_table().' WHERE attachment_id = %d', $attachment_id), ARRAY_A);

        return is_array($row) ? $row : [];
    }

    /**
     * Write a results row directly, for the steps that read results.
     *
     * @param  array<string, mixed>  $row
     */
    private function putResult(int $attachment_id, array $row = []): void
    {
        global $wpdb;

        $wpdb->replace(UAMS_MRA_Store::results_table(), array_merge([
            'attachment_id' => $attachment_id,
            'parent_id' => 0,
            'is_attached' => 0,
            'ref_count' => 0,
        ], $row));
    }

    /**
     * The highest ID in a table column, to start a cursor after the rows WordPress ships.
     */
    private function maxId(string $table, string $column): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT MAX({$column}) FROM {$table}");
    }

    /**
     * assertSame() with untyped parameters, so static analysis does not narrow either side from what it saw earlier.
     */
    private function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame($expected, $actual, $message);
    }

    // get_state(), set_state(), step_label()

    public function test_the_state_is_empty_when_nothing_is_stored_or_the_stored_value_is_not_an_array(): void
    {
        $this->same([], UAMS_MRA_Scanner::get_state());

        update_option(UAMS_MRA_Scanner::STATE_OPTION, 'garbage');

        $this->same([], UAMS_MRA_Scanner::get_state());
    }

    public function test_the_state_is_stored_without_autoloading_and_read_back_whole(): void
    {
        global $wpdb;

        UAMS_MRA_Scanner::set_state(['step' => 'posts', 'cursor' => 7]);

        $this->same(['step' => 'posts', 'cursor' => 7], UAMS_MRA_Scanner::get_state());
        $this->assertContains(
            $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", UAMS_MRA_Scanner::STATE_OPTION)),
            ['no', 'off'],
        );
    }

    public function test_every_step_has_its_own_label_and_an_unknown_one_labels_itself(): void
    {
        $labels = [];

        foreach (['index', 'posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'commentmeta', 'network', 'finalize', 'render', 'done'] as $step) {
            $labels[$step] = UAMS_MRA_Scanner::step_label($step);
            $this->assertNotSame($step, $labels[$step], $step);
        }

        $this->assertCount(11, array_unique($labels));
        $this->same('Building the file index', $labels['index']);
        $this->same('Scan complete', $labels['done']);
        $this->same('mystery', UAMS_MRA_Scanner::step_label('mystery'));
    }

    // start()

    public function test_a_scan_starts_from_an_empty_store_with_a_full_state(): void
    {
        $this->attachment('2025/11/a.jpg');
        $this->attachment('2025/11/b.jpg');
        UAMS_MRA_Store::add_refs([['attachment_id' => 1, 'source_type' => 'content', 'source_id' => 2, 'source_label' => 'x', 'confidence' => 2]]);

        $before = time();
        $state = UAMS_MRA_Scanner::start();

        $this->assertIsArray($state);
        $this->same([], $this->refs());
        $this->same($state, UAMS_MRA_Scanner::get_state());
        $this->same('index', $state['step']);
        $this->same(0, $state['cursor']);
        $this->same(0, $state['processed']);
        $this->same(2, $state['attachments']);
        $this->assertGreaterThanOrEqual($before, $state['started']);
        $this->same(0, $state['finished']);
        $this->same([], $state['site_queue']);
        $this->same([], $state['render_queue']);
        $this->same(uams_mra_settings(), $state['settings']);
        $this->same([], $state['counts']);
        $this->same('Building the file index', $state['message']);
    }

    public function test_a_scan_does_not_start_while_a_repoint_is_running(): void
    {
        update_option('uams_mra_repoint_state', ['phase' => 'rewrite', 'updated' => time()]);
        UAMS_MRA_Store::add_refs([['attachment_id' => 1, 'source_type' => 'content', 'source_id' => 2, 'source_label' => 'x', 'confidence' => 2]]);

        $result = UAMS_MRA_Scanner::start();

        $this->assertWPError($result);
        $this->same('uams_mra_busy', $result->get_error_code());
        $this->assertCount(1, $this->refs());
        $this->same([], UAMS_MRA_Scanner::get_state());
    }

    // step() and advance()

    public function test_a_step_with_no_state_or_a_finished_scan_changes_nothing(): void
    {
        $this->same([], UAMS_MRA_Scanner::step());
        $this->assertFalse(get_option(UAMS_MRA_Scanner::STATE_OPTION));

        $done = $this->state('done', [], ['finished' => 123, 'message' => 'Scan complete']);
        UAMS_MRA_Scanner::set_state($done);

        $this->same($done, UAMS_MRA_Scanner::step());
        $this->same($done, UAMS_MRA_Scanner::get_state());
    }

    public function test_a_step_runs_the_named_step_and_stores_the_result(): void
    {
        $this->attachment('2025/11/a.jpg');

        $state = $this->scanStep($this->state('index'));

        $this->same('posts', $state['step']);
        $this->same($state, UAMS_MRA_Scanner::get_state());
    }

    public function test_a_step_with_no_method_moves_on_and_is_not_stored_by_itself(): void
    {
        $state = $this->scanStep($this->state('index', [], ['step' => 'bogus']));

        $this->same('done', $state['step']);
        $this->same('Scan complete', $state['message']);
        $this->assertGreaterThan(0, $state['finished']);
    }

    public function test_the_pipeline_moves_through_every_step_in_order_and_resets_the_cursor(): void
    {
        $settings = ['include_termmeta' => 1, 'include_usermeta' => 1, 'include_comments' => 1, 'include_network' => 0, 'include_render' => 0];
        $seen = [];
        $state = $this->state('index', $settings, ['cursor' => 99]);

        $method = new \ReflectionMethod(UAMS_MRA_Scanner::class, 'advance');

        for ($i = 0; $i < 12 && $state['step'] !== 'done'; $i++) {
            $state = $method->invoke(null, $state);
            $seen[] = $state['step'];
        }

        $this->same(['posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'commentmeta', 'finalize', 'done'], $seen);
        $this->same(0, $state['cursor']);
    }

    public function test_the_last_step_ends_the_scan_and_a_new_step_gets_its_label(): void
    {
        $method = new \ReflectionMethod(UAMS_MRA_Scanner::class, 'advance');

        $next = $method->invoke(null, $this->state('posts', [], ['cursor' => 5]));

        $this->same('postmeta', $next['step']);
        $this->same(0, $next['cursor']);
        $this->same(UAMS_MRA_Scanner::step_label('postmeta'), $next['message']);

        $end = $method->invoke(null, $this->state('render'));

        $this->same('done', $end['step']);
        $this->same('Scan complete', $end['message']);
        $this->assertGreaterThan(0, $end['finished']);

        $unknown = $method->invoke(null, $this->state('nowhere'));

        $this->same('done', $unknown['step']);
    }

    public function test_each_optional_step_is_skipped_only_when_its_own_setting_is_off(): void
    {
        $method = new \ReflectionMethod(UAMS_MRA_Scanner::class, 'advance');
        $on = ['include_termmeta' => 1, 'include_usermeta' => 1, 'include_comments' => 1, 'include_network' => 0, 'include_render' => 0];

        $this->same('termmeta', $method->invoke(null, $this->state('options', $on))['step']);
        $this->same('usermeta', $method->invoke(null, $this->state('options', ['include_termmeta' => 0] + $on))['step']);
        $this->same('commentmeta', $method->invoke(null, $this->state('options', ['include_termmeta' => 0, 'include_usermeta' => 0] + $on))['step']);
        $this->same('finalize', $method->invoke(null, $this->state('options', ['include_termmeta' => 0, 'include_usermeta' => 0, 'include_comments' => 0] + $on))['step']);
        $this->same('usermeta', $method->invoke(null, $this->state('termmeta', $on))['step']);
        $this->same('commentmeta', $method->invoke(null, $this->state('usermeta', $on))['step']);
        $this->same('finalize', $method->invoke(null, $this->state('commentmeta', $on))['step']);
        $this->same('done', $method->invoke(null, $this->state('finalize', $on))['step']);
    }

    public function test_the_skipped_steps_still_leave_the_cursor_at_zero_and_the_label_of_the_step_reached(): void
    {
        $method = new \ReflectionMethod(UAMS_MRA_Scanner::class, 'advance');

        $next = $method->invoke(null, $this->state('options', ['include_termmeta' => 0, 'include_usermeta' => 0, 'include_comments' => 0, 'include_render' => 0], ['cursor' => 9]));

        $this->same('finalize', $next['step']);
        $this->same(0, $next['cursor']);
        $this->same(UAMS_MRA_Scanner::step_label('finalize'), $next['message']);
    }

    public function test_the_render_step_is_skipped_when_it_is_off_or_has_nothing_to_fetch_and_entered_when_it_does(): void
    {
        $method = new \ReflectionMethod(UAMS_MRA_Scanner::class, 'advance');

        $this->same('done', $method->invoke(null, $this->state('finalize', ['include_render' => 0]))['step']);
        $this->same('done', $method->invoke(null, $this->state('finalize', ['include_render' => 1]))['step']);

        $parent = self::factory()->post->create(['post_status' => 'publish']);
        $attachment = $this->attachment('2025/11/a.jpg', $parent);
        $this->putResult($attachment, ['parent_id' => $parent, 'is_attached' => 1]);

        $state = $method->invoke(null, $this->state('finalize', ['include_render' => 1], ['cursor' => 4]));

        $this->same('render', $state['step']);
        $this->same([$parent], $state['render_queue']);
        $this->same(0, $state['cursor']);
        $this->same(UAMS_MRA_Scanner::step_label('render'), $state['message']);
    }

    public function test_the_network_step_is_skipped_on_a_single_site_or_when_it_is_off(): void
    {
        $method = new \ReflectionMethod(UAMS_MRA_Scanner::class, 'advance');

        $this->same('finalize', $method->invoke(null, $this->state('commentmeta', ['include_network' => 0]))['step']);

        if (! is_multisite()) {
            $this->same('finalize', $method->invoke(null, $this->state('commentmeta', ['include_network' => 1]))['step']);

            return;
        }

        $this->same('finalize', $method->invoke(null, $this->state('commentmeta', ['include_network' => 1]))['step'], 'No other site yet.');

        $this->setExpectedDeprecated('wpmu_new_blog');
        $other = self::factory()->blog->create();
        $state = $method->invoke(null, $this->state('commentmeta', ['include_network' => 1]));

        $this->same('network', $state['step']);
        $this->same([$other], $state['site_queue']);
    }

    // step_index()

    public function test_the_index_step_builds_one_batch_and_stays_while_batches_are_full(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        $b = $this->attachment('2025/11/b.jpg');
        $c = $this->attachment('2025/11/c.jpg');
        global $wpdb;
        $wpdb->query('DELETE FROM '.UAMS_MRA_Store::index_table());

        $state = $this->scanStep($this->state('index', ['batch_index' => 2], ['cursor' => $a - 1, 'processed' => 5]));

        $this->same('index', $state['step']);
        $this->same($b, $state['cursor']);
        $this->same(7, $state['processed']);
        $this->same(2, (int) $wpdb->get_var('SELECT COUNT(*) FROM '.UAMS_MRA_Store::index_table()));

        $state = $this->scanStep($state);

        $this->same('posts', $state['step']);
        $this->same(0, $state['cursor']);
        $this->same(8, $state['processed']);
        $this->same(3, (int) $wpdb->get_var('SELECT COUNT(*) FROM '.UAMS_MRA_Store::index_table()));
        $this->same($c, (int) $wpdb->get_var('SELECT MAX(attachment_id) FROM '.UAMS_MRA_Store::index_table()));
    }

    public function test_the_index_step_stays_on_a_batch_that_is_exactly_full_and_ends_on_the_next(): void
    {
        $a = $this->attachment('2025/11/a.jpg');

        $full = $this->scanStep($this->state('index', ['batch_index' => 1], ['cursor' => $a - 1]));

        $this->same('index', $full['step']);

        $end = $this->scanStep($full);

        $this->same('posts', $end['step']);
    }

    // step_posts()

    public function test_the_posts_step_records_strong_references_with_the_label_of_the_post(): void
    {
        $by_class = $this->attachment('2025/11/a.jpg');
        $by_path = $this->attachment('2025/11/b.jpg');
        $unused = $this->attachment('2025/11/c.jpg');

        $post = self::factory()->post->create([
            'post_title' => 'Alpha',
            'post_status' => 'draft',
            'post_content' => '<img class="wp-image-'.$by_class.'"> <img src="'.$this->url('2025/11/b-300x200.jpg').'">',
        ]);
        $cursor = $post - 1;

        $state = $this->scanStep($this->state('posts', [], ['cursor' => $cursor]));

        $refs = $this->refs();
        $ids = array_column($refs, 'attachment_id');
        sort($ids);

        $this->same([$by_class, $by_path], array_map(intval(...), $ids));
        $this->assertNotContains($unused, array_map(intval(...), $ids));

        foreach ($refs as $ref) {
            $this->same('content', $ref['source_type']);
            $this->same((string) $post, $ref['source_id']);
            $this->same('post: Alpha (draft)', $ref['source_label']);
            $this->same('2', $ref['confidence']);
        }

        $this->same('postmeta', $state['step']);
    }

    public function test_the_posts_step_reads_the_excerpt_and_labels_an_untitled_published_post_without_a_status(): void
    {
        $attachment = $this->attachment('2025/11/a.jpg');

        $post = self::factory()->post->create(['post_title' => '', 'post_status' => 'publish', 'post_content' => '', 'post_excerpt' => 'wp-image-'.$attachment]);

        $this->scanStep($this->state('posts', [], ['cursor' => $post - 1]));

        $this->same([['attachment_id' => (string) $attachment, 'source_type' => 'content', 'source_id' => (string) $post, 'source_label' => 'post: (no title)', 'site_id' => '0', 'confidence' => '2']], $this->refs());
    }

    public function test_the_posts_step_scans_every_type_but_attachments_and_a_post_is_not_its_own_reference_source(): void
    {
        $attachment = $this->attachment('2025/11/a.jpg');
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About', 'post_content' => 'wp-image-'.$attachment]);

        $this->scanStep($this->state('posts', [], ['cursor' => $attachment - 1]));

        $refs = $this->refs();

        $this->assertCount(1, $refs);
        $this->same('page: About', $refs[0]['source_label']);
        $this->same((string) $page, $refs[0]['source_id']);
    }

    public function test_the_posts_step_counts_revisions_only_when_asked_and_labels_their_references(): void
    {
        $attachment = $this->attachment('2025/11/a.jpg');
        $post = self::factory()->post->create(['post_content' => 'plain']);
        $revision = wp_insert_post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post.'-revision-v1', 'post_title' => 'Rev', 'post_content' => 'wp-image-'.$attachment]);

        $this->scanStep($this->state('posts', ['include_revisions' => 0], ['cursor' => $post - 1]));

        $this->same([], $this->refs());

        $this->scanStep($this->state('posts', ['include_revisions' => 1], ['cursor' => $post - 1]));
        $refs = $this->refs();

        $this->assertCount(1, $refs);
        $this->same('revision', $refs[0]['source_type']);
        $this->same((string) $revision, $refs[0]['source_id']);
        $this->same('revision: Rev', $refs[0]['source_label']);
    }

    public function test_the_posts_step_records_offsite_urls_for_posts_but_never_for_revisions_or_when_tracking_is_off(): void
    {
        $post = self::factory()->post->create(['post_title' => 'Alpha', 'post_content' => 'https://old.example.edu/x/wp-content/uploads/2020/a-300x200.jpg']);
        $revision = wp_insert_post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post.'-revision-v1', 'post_title' => 'Rev', 'post_content' => 'https://old.example.edu/x/wp-content/uploads/2020/a-300x200.jpg']);

        $this->scanStep($this->state('posts', ['include_revisions' => 1, 'include_offsite' => 0], ['cursor' => $post - 1]));

        $this->same([], $this->offsite());

        $this->scanStep($this->state('posts', ['include_revisions' => 1, 'include_offsite' => 1], ['cursor' => $post - 1]));

        $this->same([[
            'url' => 'https://old.example.edu/x/wp-content/uploads/2020/a-300x200.jpg',
            'host' => 'old.example.edu',
            'base_norm' => 'a.jpg',
            'source_type' => 'content',
            'source_id' => (string) $post,
            'source_pk' => (string) $post,
            'source_key' => '',
            'source_label' => 'post: Alpha',
        ]], $this->offsite());
        $this->assertGreaterThan($post, $revision);
    }

    public function test_the_posts_step_moves_its_cursor_past_a_post_with_nothing_in_it(): void
    {
        $attachment = $this->attachment('2025/11/a.jpg');
        $empty = self::factory()->post->create(['post_content' => '', 'post_excerpt' => '']);
        $full = self::factory()->post->create(['post_content' => 'wp-image-'.$attachment]);

        $state = $this->scanStep($this->state('posts', [], ['cursor' => $empty - 1, 'processed' => 3]));

        $this->same('postmeta', $state['step']);
        $this->assertCount(1, $this->refs());

        UAMS_MRA_Store::reset();
        UAMS_MRA_Index::build_batch($attachment - 1, 1);

        $first = $this->scanStep($this->state('posts', ['batch_posts' => 1], ['cursor' => $empty - 1]));

        $this->same('posts', $first['step']);
        $this->same($empty, $first['cursor']);
        $this->same(1, $first['processed']);
        $this->same([], $this->refs());

        $second = $this->scanStep($first);

        $this->same($full, $second['cursor']);
        $this->same(2, $second['processed']);
        $this->assertCount(1, $this->refs());
    }

    public function test_the_posts_step_advances_when_there_is_nothing_after_the_cursor(): void
    {
        $post = self::factory()->post->create();

        $state = $this->scanStep($this->state('posts', [], ['cursor' => $post + 1000, 'processed' => 4]));

        $this->same('postmeta', $state['step']);
        $this->same(0, $state['cursor']);
        $this->same(4, $state['processed']);
    }

    public function test_the_posts_step_batches_by_its_setting(): void
    {
        $a = self::factory()->post->create();
        $b = self::factory()->post->create();
        $c = self::factory()->post->create();

        $first = $this->scanStep($this->state('posts', ['batch_posts' => 2], ['cursor' => $a - 1]));

        $this->same('posts', $first['step']);
        $this->same($b, $first['cursor']);
        $this->same(2, $first['processed']);

        $second = $this->scanStep($first);

        $this->same('postmeta', $second['step']);
        $this->same(3, $second['processed']);
        $this->assertGreaterThan($b, $c);
    }

    // step_postmeta()

    public function test_the_postmeta_step_records_strong_and_weak_references_with_the_label_of_the_post(): void
    {
        global $wpdb;

        $by_path = $this->attachment('2025/11/a.jpg');
        $by_number = $this->attachment('2025/11/b.jpg');
        $post = self::factory()->post->create(['post_title' => 'Alpha', 'post_status' => 'draft']);
        $before = $this->maxId($wpdb->postmeta, 'meta_id');

        add_post_meta($post, 'hero', $this->url('2025/11/a.jpg'));
        add_post_meta($post, 'logo', (string) $by_number);

        $state = $this->scanStep($this->state('postmeta', [], ['cursor' => $before]));
        $refs = $this->refs();
        usort($refs, static fn (array $a, array $b): int => (int) $a['attachment_id'] <=> (int) $b['attachment_id']);

        $this->same([
            ['attachment_id' => (string) $by_path, 'source_type' => 'postmeta', 'source_id' => (string) $post, 'source_label' => 'hero on post: Alpha (draft)', 'site_id' => '0', 'confidence' => '2'],
            ['attachment_id' => (string) $by_number, 'source_type' => 'postmeta', 'source_id' => (string) $post, 'source_label' => 'logo on post: Alpha (draft)', 'site_id' => '0', 'confidence' => '1'],
        ], $refs);
        $this->same('options', $state['step']);
    }

    public function test_the_postmeta_step_takes_a_bare_number_only_when_the_setting_allows_it(): void
    {
        global $wpdb;

        $attachment = $this->attachment('2025/11/a.jpg');
        $post = self::factory()->post->create();
        $before = $this->maxId($wpdb->postmeta, 'meta_id');
        add_post_meta($post, 'logo', (string) $attachment);

        $this->scanStep($this->state('postmeta', ['include_numeric_meta' => 0], ['cursor' => $before]));

        $this->same([], $this->refs());

        $this->scanStep($this->state('postmeta', ['include_numeric_meta' => 1], ['cursor' => $before]));

        $this->assertCount(1, $this->refs());
    }

    public function test_the_postmeta_step_skips_meta_that_describes_the_attachment_and_empty_values(): void
    {
        global $wpdb;

        $attachment = $this->attachment('2025/11/a.jpg');
        $post = self::factory()->post->create();
        $before = $this->maxId($wpdb->postmeta, 'meta_id');

        add_post_meta($post, '_edit_lock', $this->url('2025/11/a.jpg'));
        add_post_meta($post, '_wp_attachment_image_alt', $this->url('2025/11/a.jpg'));
        add_post_meta($post, 'hero', '');

        $state = $this->scanStep($this->state('postmeta', [], ['cursor' => $before]));

        $this->same([], $this->refs());
        $this->same('options', $state['step']);
        $this->same(3, $state['processed']);
        $this->assertLessThan($post, $attachment);
    }

    public function test_the_postmeta_step_does_not_let_an_attachment_reference_itself(): void
    {
        global $wpdb;

        $attachment = $this->attachment('2025/11/a.jpg');
        $other = $this->attachment('2025/11/b.jpg');
        $before = $this->maxId($wpdb->postmeta, 'meta_id');

        add_post_meta($attachment, 'related', $attachment.','.$other);

        $this->scanStep($this->state('postmeta', [], ['cursor' => $before]));

        $refs = $this->refs();

        $this->assertCount(1, $refs);
        $this->same((string) $other, $refs[0]['attachment_id']);
    }

    public function test_the_postmeta_step_reads_revision_meta_only_when_asked_and_calls_it_revision_meta(): void
    {
        global $wpdb;

        $attachment = $this->attachment('2025/11/a.jpg');
        $post = self::factory()->post->create();
        $revision = wp_insert_post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post.'-revision-v1', 'post_title' => 'Rev']);
        $before = $this->maxId($wpdb->postmeta, 'meta_id');
        add_metadata('post', $revision, 'hero', $this->url('2025/11/a.jpg'));

        $this->scanStep($this->state('postmeta', ['include_revisions' => 0], ['cursor' => $before]));

        $this->same([], $this->refs());

        $this->scanStep($this->state('postmeta', ['include_revisions' => 1], ['cursor' => $before]));
        $refs = $this->refs();

        $this->assertCount(1, $refs);
        $this->same('revision_meta', $refs[0]['source_type']);
        $this->same((string) $revision, $refs[0]['source_id']);
        $this->same('hero on revision: Rev', $refs[0]['source_label']);
        $this->same((string) $attachment, $refs[0]['attachment_id']);
    }

    public function test_the_postmeta_step_records_offsite_urls_with_the_row_and_key_to_write_back_to(): void
    {
        global $wpdb;

        $post = self::factory()->post->create(['post_title' => 'Alpha']);
        $revision = wp_insert_post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post.'-revision-v1', 'post_title' => 'Rev']);
        $before = $this->maxId($wpdb->postmeta, 'meta_id');
        $url = 'https://old.example.edu/x/wp-content/uploads/2020/a-300x200.jpg';
        add_post_meta($post, 'hero', $url);
        $meta_id = $this->maxId($wpdb->postmeta, 'meta_id');
        add_metadata('post', $revision, 'hero', $url);

        $this->scanStep($this->state('postmeta', ['include_offsite' => 0, 'include_revisions' => 1], ['cursor' => $before]));

        $this->same([], $this->offsite());

        $this->scanStep($this->state('postmeta', ['include_offsite' => 1, 'include_revisions' => 1], ['cursor' => $before]));

        $this->same([[
            'url' => $url,
            'host' => 'old.example.edu',
            'base_norm' => 'a.jpg',
            'source_type' => 'postmeta',
            'source_id' => (string) $post,
            'source_pk' => (string) $meta_id,
            'source_key' => 'hero',
            'source_label' => 'hero on post: Alpha',
        ]], $this->offsite());
    }

    public function test_the_postmeta_step_batches_by_its_setting_and_advances_on_an_empty_page(): void
    {
        global $wpdb;

        $post = self::factory()->post->create();
        $before = $this->maxId($wpdb->postmeta, 'meta_id');
        add_post_meta($post, 'a', 'x');
        add_post_meta($post, 'b', 'x');
        add_post_meta($post, 'c', 'x');
        $ids = array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id > %d ORDER BY meta_id", $before)));
        $second = $ids[1];

        $first = $this->scanStep($this->state('postmeta', ['batch_meta' => 2], ['cursor' => $before]));

        $this->same('postmeta', $first['step']);
        $this->same($second, $first['cursor']);
        $this->same(2, $first['processed']);

        $next = $this->scanStep($first);

        $this->same('options', $next['step']);
        $this->same(3, $next['processed']);

        $empty = $this->scanStep($this->state('postmeta', [], ['cursor' => $second + 100, 'processed' => 8]));

        $this->same('options', $empty['step']);
        $this->same(8, $empty['processed']);
    }

    // step_options()

    public function test_the_options_step_records_references_labelled_with_the_option_name(): void
    {
        global $wpdb;

        $by_path = $this->attachment('2025/11/a.jpg');
        $by_number = $this->attachment('2025/11/b.jpg');
        $before = $this->maxId($wpdb->options, 'option_id');
        update_option('site_logo_x', $this->url('2025/11/a.jpg'));
        update_option('header_image_x', (string) $by_number);

        $state = $this->scanStep($this->state('options', [], ['cursor' => $before]));
        $refs = $this->refs();
        usort($refs, static fn (array $a, array $b): int => (int) $a['attachment_id'] <=> (int) $b['attachment_id']);

        $this->assertCount(2, $refs);
        $this->same((string) $by_path, $refs[0]['attachment_id']);
        $this->same('option', $refs[0]['source_type']);
        $this->same('Option: site_logo_x', $refs[0]['source_label']);
        $this->same('2', $refs[0]['confidence']);
        $this->same((string) $this->optionId('site_logo_x'), $refs[0]['source_id']);
        $this->same((string) $by_number, $refs[1]['attachment_id']);
        $this->same('Option: header_image_x', $refs[1]['source_label']);
        $this->same('1', $refs[1]['confidence']);
        $this->same('termmeta', $state['step']);
    }

    private function optionId(string $name): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    public function test_the_options_step_leaves_out_transients_and_its_own_options_but_not_look_alikes(): void
    {
        global $wpdb;

        $this->attachment('2025/11/a.jpg');
        $before = $this->maxId($wpdb->options, 'option_id');
        $url = $this->url('2025/11/a.jpg');
        update_option('_transient_cache_x', $url);
        update_option('_site_transient_cache_x', $url);
        update_option('uams_mra_own_x', $url);
        update_option('Xtransient_look_x', $url);
        update_option('XsiteXtransient_look_x', $url);
        update_option('uams_mraXlook_x', $url);
        update_option('empty_x', '');

        $state = $this->scanStep($this->state('options', [], ['cursor' => $before]));
        $labels = array_column($this->refs(), 'source_label');
        sort($labels);
        $expected = ['Option: Xtransient_look_x', 'Option: XsiteXtransient_look_x', 'Option: uams_mraXlook_x'];
        sort($expected);

        $this->same($expected, $labels);
        $this->same(4, $state['processed']);
    }

    public function test_the_options_step_takes_a_bare_number_only_when_the_setting_allows_it(): void
    {
        global $wpdb;

        $attachment = $this->attachment('2025/11/a.jpg');
        $before = $this->maxId($wpdb->options, 'option_id');
        update_option('logo_x', (string) $attachment);

        $this->scanStep($this->state('options', ['include_numeric_meta' => 0], ['cursor' => $before]));

        $this->same([], $this->refs());
    }

    public function test_the_options_step_records_offsite_urls_with_the_option_to_write_back_to(): void
    {
        global $wpdb;

        $before = $this->maxId($wpdb->options, 'option_id');
        $url = 'https://old.example.edu/x/wp-content/uploads/2020/a-300x200.jpg';
        update_option('site_logo_x', $url);
        $id = $this->optionId('site_logo_x');

        $this->scanStep($this->state('options', ['include_offsite' => 0], ['cursor' => $before]));

        $this->same([], $this->offsite());

        $this->scanStep($this->state('options', ['include_offsite' => 1], ['cursor' => $before]));

        $this->same([[
            'url' => $url,
            'host' => 'old.example.edu',
            'base_norm' => 'a.jpg',
            'source_type' => 'option',
            'source_id' => (string) $id,
            'source_pk' => (string) $id,
            'source_key' => 'site_logo_x',
            'source_label' => 'Option: site_logo_x',
        ]], $this->offsite());
    }

    public function test_the_options_step_batches_by_its_setting_and_advances_on_an_empty_page(): void
    {
        global $wpdb;

        $before = $this->maxId($wpdb->options, 'option_id');
        update_option('a_x', 'v');
        update_option('b_x', 'v');
        update_option('c_x', 'v');

        $first = $this->scanStep($this->state('options', ['batch_meta' => 2], ['cursor' => $before]));

        $this->same('options', $first['step']);
        $this->same($this->optionId('b_x'), $first['cursor']);
        $this->same(2, $first['processed']);

        $next = $this->scanStep($first);

        $this->same('termmeta', $next['step']);
        $this->same(3, $next['processed']);

        $empty = $this->scanStep($this->state('options', [], ['cursor' => $this->maxId($wpdb->options, 'option_id') + 100, 'processed' => 6]));

        $this->same('termmeta', $empty['step']);
        $this->same(6, $empty['processed']);
    }

    // step_termmeta(), step_usermeta(), step_commentmeta()

    public function test_the_term_meta_step_records_references_labelled_with_the_term(): void
    {
        global $wpdb;

        $by_path = $this->attachment('2025/11/a.jpg');
        $by_number = $this->attachment('2025/11/b.jpg');
        $term = self::factory()->term->create();
        $before = $this->maxId($wpdb->termmeta, 'meta_id');
        add_term_meta($term, 'banner', $this->url('2025/11/a.jpg'));
        add_term_meta($term, 'icon', (string) $by_number);

        $state = $this->scanStep($this->state('termmeta', [], ['cursor' => $before]));
        $refs = $this->refs();
        usort($refs, static fn (array $a, array $b): int => (int) $a['attachment_id'] <=> (int) $b['attachment_id']);

        $this->same([
            ['attachment_id' => (string) $by_path, 'source_type' => 'termmeta', 'source_id' => (string) $term, 'source_label' => 'Term #'.$term.': banner', 'site_id' => '0', 'confidence' => '2'],
            ['attachment_id' => (string) $by_number, 'source_type' => 'termmeta', 'source_id' => (string) $term, 'source_label' => 'Term #'.$term.': icon', 'site_id' => '0', 'confidence' => '1'],
        ], $refs);
        $this->same('usermeta', $state['step']);
    }

    public function test_the_user_meta_step_never_takes_a_bare_number_but_does_take_a_url(): void
    {
        global $wpdb;

        $by_path = $this->attachment('2025/11/a.jpg');
        $by_number = $this->attachment('2025/11/b.jpg');
        $user = self::factory()->user->create();
        $before = $this->maxId($wpdb->usermeta, 'umeta_id');
        add_user_meta($user, 'avatar_x', (string) $by_number);
        add_user_meta($user, 'banner_x', $this->url('2025/11/a.jpg'));

        $state = $this->scanStep($this->state('usermeta', ['include_numeric_meta' => 1], ['cursor' => $before]));
        $refs = $this->refs();

        $this->assertCount(1, $refs);
        $this->same((string) $by_path, $refs[0]['attachment_id']);
        $this->same('usermeta', $refs[0]['source_type']);
        $this->same('User #'.$user.': banner_x', $refs[0]['source_label']);
        $this->same('commentmeta', $state['step']);
    }

    public function test_the_comment_meta_step_records_references_labelled_with_the_comment(): void
    {
        global $wpdb;

        $attachment = $this->attachment('2025/11/a.jpg');
        $comment = self::factory()->comment->create(['comment_post_ID' => self::factory()->post->create()]);
        $before = $this->maxId($wpdb->commentmeta, 'meta_id');
        add_comment_meta($comment, 'photo_x', $this->url('2025/11/a.jpg'));

        $state = $this->scanStep($this->state('commentmeta', ['include_network' => 0], ['cursor' => $before]));
        $refs = $this->refs();

        $this->same([['attachment_id' => (string) $attachment, 'source_type' => 'commentmeta', 'source_id' => (string) $comment, 'source_label' => 'Comment #'.$comment.': photo_x', 'site_id' => '0', 'confidence' => '2']], $refs);
        $this->same('finalize', $state['step']);
    }

    public function test_the_generic_meta_steps_skip_describing_meta_and_empty_values_and_record_offsite_urls(): void
    {
        global $wpdb;

        $this->attachment('2025/11/a.jpg');
        $term = self::factory()->term->create();
        $before = $this->maxId($wpdb->termmeta, 'meta_id');
        $url = 'https://old.example.edu/x/wp-content/uploads/2020/other-300x200.jpg';
        add_term_meta($term, '_edit_lock', $this->url('2025/11/a.jpg'));
        add_term_meta($term, 'blank_x', '');
        add_term_meta($term, 'banner_x', $url);
        $pk = $this->maxId($wpdb->termmeta, 'meta_id');

        $this->scanStep($this->state('termmeta', ['include_offsite' => 0], ['cursor' => $before]));

        $this->same([], $this->offsite());
        $this->same([], $this->refs());

        $state = $this->scanStep($this->state('termmeta', ['include_offsite' => 1], ['cursor' => $before]));

        $this->same([[
            'url' => $url,
            'host' => 'old.example.edu',
            'base_norm' => 'other.jpg',
            'source_type' => 'termmeta',
            'source_id' => (string) $term,
            'source_pk' => (string) $pk,
            'source_key' => 'banner_x',
            'source_label' => 'Term #'.$term.': banner_x',
        ]], $this->offsite());
        $this->same(3, $state['processed']);
    }

    public function test_the_generic_meta_steps_batch_by_their_setting_and_advance_on_an_empty_page(): void
    {
        global $wpdb;

        $term = self::factory()->term->create();
        $before = $this->maxId($wpdb->termmeta, 'meta_id');
        add_term_meta($term, 'a_x', 'v');
        add_term_meta($term, 'b_x', 'v');
        add_term_meta($term, 'c_x', 'v');
        $ids = array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id", $before)));

        $first = $this->scanStep($this->state('termmeta', ['batch_meta' => 2], ['cursor' => $before]));

        $this->same('termmeta', $first['step']);
        $this->same($ids[1], $first['cursor']);
        $this->same(2, $first['processed']);

        $next = $this->scanStep($first);

        $this->same('usermeta', $next['step']);
        $this->same(3, $next['processed']);

        $empty = $this->scanStep($this->state('termmeta', [], ['cursor' => $ids[2] + 100, 'processed' => 7]));

        $this->same('usermeta', $empty['step']);
        $this->same(7, $empty['processed']);
    }

    // step_network()

    /**
     * @expectedDeprecated wpmu_new_blog
     */
    public function test_the_network_step_records_references_from_a_sibling_site(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network.');
        }

        $attachment = $this->attachment('2025/11/a.jpg');
        $blog = self::factory()->blog->create();
        switch_to_blog($blog);
        $post = self::factory()->post->create(['post_title' => 'Sibling', 'post_status' => 'draft', 'post_content' => 'see wp-content/uploads/2025/11/a-300x200.jpg']);
        self::factory()->post->create(['post_content' => 'nothing to see']);
        $name = (string) get_bloginfo('name');
        restore_current_blog();

        $state = $this->scanStep($this->state('network', [], ['site_queue' => [$blog], 'cursor' => $post - 1]));

        $this->same([[
            'attachment_id' => (string) $attachment,
            'source_type' => 'network',
            'source_id' => (string) $post,
            'source_label' => $name.': post: Sibling (draft)',
            'site_id' => (string) $blog,
            'confidence' => '2',
        ]], $this->refs());
        $this->same('finalize', $state['step']);
        $this->same([], $state['site_queue']);
        $this->same(0, $state['cursor']);
        $this->same(2, $state['processed']);
    }

    /**
     * @expectedDeprecated wpmu_new_blog
     */
    public function test_the_network_step_moves_to_the_next_site_when_one_is_exhausted_and_pages_through_a_large_one(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network.');
        }

        $first = self::factory()->blog->create();
        $second = self::factory()->blog->create();
        switch_to_blog($first);
        $a = self::factory()->post->create();
        $b = self::factory()->post->create();
        $c = self::factory()->post->create();
        restore_current_blog();

        $page = $this->scanStep($this->state('network', ['batch_posts' => 2], ['site_queue' => [$first, $second], 'cursor' => $a - 1]));

        $this->same('network', $page['step']);
        $this->same([$first, $second], $page['site_queue']);
        $this->same($b, $page['cursor']);
        $this->same(2, $page['processed']);

        $last = $this->scanStep($page);

        $this->same('network', $last['step']);
        $this->same([$second], $last['site_queue']);
        $this->same(0, $last['cursor']);
        $this->same(3, $last['processed']);
        $this->assertGreaterThan($b, $c);

        $done = $this->scanStep($this->state('network', [], ['site_queue' => [$second]]));

        $this->same('finalize', $done['step']);
        $this->same([], $done['site_queue']);
    }

    public function test_the_network_step_with_no_sites_left_moves_on(): void
    {
        $state = $this->scanStep($this->state('network', [], ['site_queue' => [], 'processed' => 3]));

        $this->same('finalize', $state['step']);
        $this->same(3, $state['processed']);
    }

    // step_finalize()

    public function test_the_finalize_step_writes_one_result_row_for_each_attachment(): void
    {
        $live = self::factory()->post->create(['post_status' => 'publish']);
        $draft = self::factory()->post->create(['post_status' => 'draft']);
        $a = $this->attachment('2025/11/a.jpg', $live, 'Alpha');
        $b = $this->attachment('2025/11/b.jpg', $draft);
        $c = $this->attachment('2025/11/c.jpg', 999999);
        $d = $this->attachment('2025/11/d.jpg');

        UAMS_MRA_Store::add_refs([
            ['attachment_id' => $a, 'source_type' => 'content', 'source_id' => 1, 'source_label' => 'x', 'confidence' => 2],
            ['attachment_id' => $a, 'source_type' => 'content', 'source_id' => 2, 'source_label' => 'x', 'confidence' => 1],
            ['attachment_id' => $a, 'source_type' => 'content', 'source_id' => 3, 'source_label' => 'x', 'confidence' => 1],
            ['attachment_id' => $b, 'source_type' => 'content', 'source_id' => 1, 'source_label' => 'x', 'confidence' => 1],
        ]);

        $before = time();
        $state = $this->scanStep($this->state('finalize', [], ['cursor' => $a - 1, 'processed' => 2]));

        $this->same('done', $state['step']);
        $this->same(6, $state['processed']);

        $row = $this->result($a);

        $this->same((string) $live, $row['parent_id']);
        $this->same('publish', $row['parent_status']);
        $this->same('1', $row['is_attached']);
        $this->same('3', $row['ref_count']);
        $this->same('1', $row['ref_count_strict']);
        $this->same('0', $row['bytes']);
        $this->same('image/jpeg', $row['mime']);
        $this->same(get_post($a)->post_date, $row['post_date']);
        $this->same('Alpha', $row['title']);
        $this->same('2025/11/a.jpg', $row['file']);
        $this->assertGreaterThanOrEqual($before - 2, strtotime($row['scanned_at'].' UTC'));

        $row = $this->result($b);

        $this->same('draft', $row['parent_status']);
        $this->same('1', $row['is_attached']);
        $this->same('1', $row['ref_count']);
        $this->same('0', $row['ref_count_strict']);

        $row = $this->result($c);

        $this->same('999999', $row['parent_id']);
        $this->same('missing', $row['parent_status']);
        $this->same('0', $row['is_attached']);
        $this->same('0', $row['ref_count']);
        $this->same('0', $row['ref_count_strict']);

        $row = $this->result($d);

        $this->same('0', $row['parent_id']);
        $this->same('', $row['parent_status']);
        $this->same('0', $row['is_attached']);
        $this->same('0', $row['ref_count']);
        $this->same('0', $row['ref_count_strict']);
    }

    public function test_the_finalize_step_cuts_a_long_title_and_file_to_250_characters(): void
    {
        $title = str_repeat('é', 300);
        $attachment = $this->attachment('2025/11/'.str_repeat('f', 300).'.jpg', 0, $title);

        $this->scanStep($this->state('finalize', [], ['cursor' => $attachment - 1]));

        $row = $this->result($attachment);

        $this->same(str_repeat('é', 250), $row['title']);
        $this->same(250, mb_strlen($row['file']));
    }

    public function test_the_finalize_step_measures_the_files_on_disk(): void
    {
        $dir = wp_get_upload_dir()['basedir'].'/2025/11';
        wp_mkdir_p($dir);
        file_put_contents($dir.'/zz-final.jpg', str_repeat('x', 12));
        $attachment = $this->attachment('2025/11/zz-final.jpg');

        $this->scanStep($this->state('finalize', [], ['cursor' => $attachment - 1]));

        $this->same('12', $this->result($attachment)['bytes']);

        unlink($dir.'/zz-final.jpg');
    }

    public function test_the_finalize_step_batches_by_its_setting_and_advances_on_an_empty_page(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        $b = $this->attachment('2025/11/b.jpg');
        $c = $this->attachment('2025/11/c.jpg');

        $first = $this->scanStep($this->state('finalize', ['batch_finalize' => 2, 'include_render' => 0], ['cursor' => $a - 1]));

        $this->same('finalize', $first['step']);
        $this->same($b, $first['cursor']);
        $this->same(2, $first['processed']);
        $this->same([], $this->result($c));

        $next = $this->scanStep($first);

        $this->same('done', $next['step']);
        $this->same(3, $next['processed']);
        $this->assertNotSame([], $this->result($c));

        $empty = $this->scanStep($this->state('finalize', ['include_render' => 0], ['cursor' => $c + 1000, 'processed' => 5]));

        $this->same('done', $empty['step']);
        $this->same(5, $empty['processed']);
    }

    // build_render_queue(), pending_attachment_ids()

    /**
     * Run a protected static method of the scanner.
     */
    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(UAMS_MRA_Scanner::class, $method))->invoke(null, ...$args);
    }

    /**
     * A parent post with attachments hanging off it that no reference has explained.
     *
     * @param  array<string, mixed>  $post
     * @return array{int, array<int, int>}
     */
    private function parentWithFiles(int $files, array $post = []): array
    {
        $parent = self::factory()->post->create(array_merge(['post_status' => 'publish', 'post_title' => 'Parent'], $post));
        $ids = [];

        for ($i = 0; $i < $files; $i++) {
            $id = $this->attachment('2025/11/f'.$parent.'-'.$i.'.jpg', $parent);
            $this->putResult($id, ['parent_id' => $parent, 'is_attached' => 1, 'ref_count' => 0]);
            $ids[] = $id;
        }

        return [$parent, $ids];
    }

    public function test_the_render_queue_holds_the_readable_parents_of_unexplained_files_busiest_first(): void
    {
        [$few, $few_files] = $this->parentWithFiles(1);
        [$many, $many_files] = $this->parentWithFiles(3);
        [$tie, $tie_files] = $this->parentWithFiles(1);

        $referenced = $this->attachment('2025/11/r.jpg', $few);
        $this->putResult($referenced, ['parent_id' => $few, 'is_attached' => 1, 'ref_count' => 1]);
        $unattached = $this->attachment('2025/11/u.jpg', $few);
        $this->putResult($unattached, ['parent_id' => $few, 'is_attached' => 0, 'ref_count' => 0]);
        $orphan = $this->attachment('2025/11/o.jpg');
        $this->putResult($orphan, ['parent_id' => 0, 'is_attached' => 1, 'ref_count' => 0]);

        $state = $this->call('build_render_queue', $this->state('finalize'));

        $this->same([$many, $few, $tie], $state['render_queue']);
        $this->same(3, $state['render_total']);
        $this->same(0, $state['render_skipped']);
        $this->same(0, $state['render_capped']);
        $this->same(['pages' => 0, 'found' => 0, 'absent' => 0, 'error' => 0, 'offsite' => 0], $state['render_stats']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $state['render_token']);
        $this->same([], UAMS_MRA_Store::render_counts());
        $this->assertCount(3, $many_files);
        $this->assertCount(1, $few_files);
        $this->assertCount(1, $tie_files);
    }

    public function test_a_parent_that_cannot_be_fetched_anonymously_is_settled_as_skipped(): void
    {
        register_post_type('zz_hidden', ['public' => false, 'publicly_queryable' => false]);

        [$draft, $draft_files] = $this->parentWithFiles(1, ['post_status' => 'draft']);
        [$locked, $locked_files] = $this->parentWithFiles(1, ['post_password' => 'secret']);
        [$hidden, $hidden_files] = $this->parentWithFiles(1, ['post_type' => 'zz_hidden']);
        [$open, $open_files] = $this->parentWithFiles(1);

        $state = $this->call('build_render_queue', $this->state('finalize'));

        $this->same([$open], $state['render_queue']);
        $this->same(3, $state['render_skipped']);
        $this->same(0, $state['render_capped']);
        $this->same(['skipped' => 3], UAMS_MRA_Store::render_counts());

        foreach (array_merge($draft_files, $locked_files, $hidden_files) as $id) {
            $this->same('skipped', $this->result($id)['render_state']);
        }

        $this->same('', $this->result($open_files[0])['render_state']);
        $this->assertGreaterThan($draft, $locked);
        $this->assertGreaterThan($locked, $hidden);

        unregister_post_type('zz_hidden');
    }

    public function test_the_render_queue_is_capped_and_the_files_behind_the_cut_are_marked_skipped(): void
    {
        [$first, $first_files] = $this->parentWithFiles(3);
        [$second, $second_files] = $this->parentWithFiles(2);
        [$third, $third_files] = $this->parentWithFiles(1);

        $state = $this->call('build_render_queue', $this->state('finalize', ['render_max_pages' => 2]));

        $this->same([$first, $second], $state['render_queue']);
        $this->same(2, $state['render_total']);
        $this->same(1, $state['render_skipped']);
        $this->same(1, $state['render_capped']);
        $this->same('skipped', $this->result($third_files[0])['render_state']);
        $this->same('', $this->result($second_files[0])['render_state']);
        $this->same('', $this->result($first_files[0])['render_state']);
        $this->assertGreaterThan($first, $third);
    }

    public function test_the_render_ceiling_is_at_least_one_page_and_defaults_when_the_setting_is_absent(): void
    {
        [$first] = $this->parentWithFiles(2);
        $this->parentWithFiles(1);

        $zero = $this->call('build_render_queue', $this->state('finalize', ['render_max_pages' => 0]));

        $this->same([$first], $zero['render_queue']);
        $this->same(1, $zero['render_capped']);

        $state = $this->state('finalize');
        unset($state['settings']['render_max_pages']);

        $this->assertCount(2, $this->call('build_render_queue', $state)['render_queue']);
    }

    public function test_pending_files_are_grouped_by_parent_and_leave_out_the_explained_ones(): void
    {
        [$one, $one_files] = $this->parentWithFiles(2);
        [$two, $two_files] = $this->parentWithFiles(1);

        $explained = $this->attachment('2025/11/e.jpg', $one);
        $this->putResult($explained, ['parent_id' => $one, 'is_attached' => 1, 'ref_count' => 2]);

        $this->same([$one => $one_files, $two => $two_files], $this->call('pending_attachment_ids', [(string) $one, $two, 0, $one]));
        $this->same([$two => $two_files], $this->call('pending_attachment_ids', [$two]));
        $this->same([], $this->call('pending_attachment_ids', []));
        $this->same([], $this->call('pending_attachment_ids', [0, '']));
        $this->same([], $this->call('pending_attachment_ids', [999999]));
    }

    public function test_pending_files_are_found_for_more_parents_than_one_query_takes(): void
    {
        global $wpdb;

        $parents = [];

        for ($i = 1; $i <= 201; $i++) {
            $parents[] = 800000 + $i;
            $wpdb->insert(UAMS_MRA_Store::results_table(), ['attachment_id' => 900000 + $i, 'parent_id' => 800000 + $i, 'is_attached' => 1, 'ref_count' => 0]);
        }

        $found = $this->call('pending_attachment_ids', $parents);

        $this->assertCount(201, $found);
        $this->same([900201], $found[800201]);
        $this->same([900001], $found[800001]);
    }

    // step_render(), fetch_rendered()

    /**
     * Answer every outgoing request from a callable, and record what was asked.
     *
     * @param  callable(string, array<string, mixed>): mixed  $respond  Returns a body string, or a ready response or error.
     * @return \ArrayObject<int, array{url: string, args: array<string, mixed>}>
     */
    private function serve(callable $respond): \ArrayObject
    {
        $log = new \ArrayObject;

        add_filter('pre_http_request', static function ($pre, $args, $url) use ($respond, $log) {
            $log->append(['url' => (string) $url, 'args' => (array) $args]);
            $answer = $respond((string) $url, (array) $args);

            if (is_string($answer)) {
                return ['headers' => [], 'body' => $answer, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
            }

            return $answer;
        }, 10, 3);

        return $log;
    }

    /**
     * A response with the given status and body.
     *
     * @return array<string, mixed>
     */
    private function reply(int $code, string $body = '<p>page</p>'): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * A render state ready to run one batch of pages.
     *
     * @param  array<int, int>  $queue
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function renderState(array $queue, array $settings = [], array $extra = []): array
    {
        return $this->state('render', $settings, array_merge(['render_queue' => $queue, 'render_token' => 'tok12345'], $extra));
    }

    public function test_the_render_step_reads_a_page_and_credits_the_files_it_serves(): void
    {
        $this->useRealTables();

        [$parent, $files] = $this->parentWithFiles(1, ['post_title' => 'Showcase']);
        [$other, $other_files] = $this->parentWithFiles(1);
        $own = (string) get_post($files[0])->ID;
        $served = $this->url('2025/11/f'.$parent.'-0.jpg');
        $elsewhere = $this->url('2025/11/f'.$other.'-0.jpg');

        $log = $this->serve(fn (): string => '<img src="'.$served.'"><img src="'.$elsewhere.'">');

        $state = $this->scanStep($this->renderState([$parent]));

        $this->assertCount(1, $log);
        $this->same(add_query_arg('uams-mra-check', 'tok12345', (string) get_permalink($parent)), $log[0]['url']);
        $this->same([
            ['attachment_id' => $own, 'source_type' => 'render', 'source_id' => (string) $parent, 'source_label' => 'Rendered page: Showcase', 'site_id' => '0', 'confidence' => '2'],
            ['attachment_id' => (string) $other_files[0], 'source_type' => 'render', 'source_id' => (string) $parent, 'source_label' => 'Rendered page: Showcase', 'site_id' => '0', 'confidence' => '2'],
        ], $this->refs());

        $row = $this->result($files[0]);

        $this->same('found', $row['render_state']);
        $this->same(get_permalink($parent), $row['render_url']);
        $this->same('', $row['render_note']);
        $this->same('1', $row['ref_count']);
        $this->same('1', $row['ref_count_strict']);

        // A file that belongs to another parent but is shown here is explained as well, and left for that parent's own turn.
        $row = $this->result($other_files[0]);

        $this->same('1', $row['ref_count']);
        $this->same('', $row['render_state']);

        $this->same('done', $state['step']);
        $this->same(1, $state['processed']);
        $this->same(['pages' => 1, 'found' => 1, 'absent' => 0, 'error' => 0, 'offsite' => 0], $state['render_stats']);
    }

    public function test_the_render_step_marks_a_file_the_page_does_not_serve_as_absent(): void
    {
        [$parent, $files] = $this->parentWithFiles(2);

        $this->serve(fn (): string => '<p>Nothing from uploads here</p>');

        $state = $this->scanStep($this->renderState([$parent]));

        foreach ($files as $id) {
            $row = $this->result($id);

            $this->same('absent', $row['render_state']);
            $this->same(get_permalink($parent), $row['render_url']);
            $this->same('', $row['render_note']);
            $this->same('0', $row['ref_count']);
        }

        $this->same([], $this->refs());
        $this->same(['pages' => 1, 'found' => 0, 'absent' => 2, 'error' => 0, 'offsite' => 0], $state['render_stats']);
    }

    public function test_the_render_step_notes_the_host_when_a_page_serves_the_file_from_elsewhere(): void
    {
        [$parent, $files] = $this->parentWithFiles(2);
        $this->serve(fn (): string => '<img src="https://old.example.edu/wp-content/uploads/sites/2/2020/01/f'.$parent.'-0-300x200.jpg">');

        $state = $this->scanStep($this->renderState([$parent]));

        $row = $this->result($files[0]);

        $this->same('offsite', $row['render_state']);
        $this->same('Served from old.example.edu', $row['render_note']);
        $this->same(get_permalink($parent), $row['render_url']);
        $this->same('absent', $this->result($files[1])['render_state']);
        $this->same(['pages' => 1, 'found' => 0, 'absent' => 1, 'error' => 0, 'offsite' => 1], $state['render_stats']);
    }

    public function test_the_render_step_prefers_the_file_served_here_over_the_same_name_served_elsewhere(): void
    {
        $this->useRealTables();

        [$parent, $files] = $this->parentWithFiles(2);
        $this->serve(fn (): string => '<img src="'.$this->url('2025/11/f'.$parent.'-0.jpg').'"><img src="https://old.example.edu/wp-content/uploads/sites/2/2020/01/f'.$parent.'-0.jpg">');

        $state = $this->scanStep($this->renderState([$parent]));

        $this->same('found', $this->result($files[0])['render_state']);
        $this->same('', $this->result($files[0])['render_note']);
        $this->same('absent', $this->result($files[1])['render_state']);
        $this->same(['pages' => 1, 'found' => 1, 'absent' => 1, 'error' => 0, 'offsite' => 0], $state['render_stats']);
    }

    public function test_a_page_that_cannot_be_fetched_leaves_its_files_for_a_person_to_check(): void
    {
        [$parent, $files] = $this->parentWithFiles(2);
        $this->serve(fn (): \WP_Error => new \WP_Error('http_request_failed', 'timed out'));

        $state = $this->scanStep($this->renderState([$parent], [], ['render_stats' => ['pages' => 4, 'found' => 1, 'absent' => 1, 'error' => 1, 'offsite' => 1]]));

        foreach ($files as $id) {
            $row = $this->result($id);

            $this->same('error', $row['render_state']);
            $this->same(get_permalink($parent), $row['render_url']);
        }

        $this->same(['pages' => 5, 'found' => 1, 'absent' => 1, 'error' => 3, 'offsite' => 1], $state['render_stats']);
    }

    public function test_a_parent_with_no_permalink_is_skipped_without_a_request(): void
    {
        $this->putResult(555001, ['parent_id' => 777777, 'is_attached' => 1, 'ref_count' => 0]);
        $log = $this->serve(fn (): string => 'x');

        $state = $this->scanStep($this->renderState([777777]));

        $this->assertCount(0, $log);
        $this->same('skipped', $this->result(555001)['render_state']);
        $this->same(0, $state['render_stats']['pages']);
        $this->same(1, $state['processed']);
    }

    public function test_a_queued_parent_whose_files_are_already_explained_is_not_fetched(): void
    {
        [$parent, $files] = $this->parentWithFiles(1);
        $this->putResult($files[0], ['parent_id' => $parent, 'is_attached' => 1, 'ref_count' => 3]);
        $log = $this->serve(fn (): string => 'x');

        $state = $this->scanStep($this->renderState([$parent]));

        $this->assertCount(0, $log);
        $this->same('', $this->result($files[0])['render_state']);
        $this->same(1, $state['processed']);
        $this->same('done', $state['step']);
    }

    public function test_a_file_cleared_by_an_earlier_page_in_the_batch_is_not_marked_absent_by_a_later_one(): void
    {
        $this->useRealTables();

        [$first, $first_files] = $this->parentWithFiles(1);
        [$second, $second_files] = $this->parentWithFiles(1);
        $log = $this->serve(fn (): string => '<img src="'.$this->url('2025/11/f'.$second.'-0.jpg').'">');

        $state = $this->scanStep($this->renderState([$first, $second]));

        $this->assertCount(1, $log);
        $this->same('absent', $this->result($first_files[0])['render_state']);
        $this->same('', $this->result($second_files[0])['render_state']);
        $this->same('1', $this->result($second_files[0])['ref_count']);
        $this->same(2, $state['processed']);
    }

    public function test_the_render_step_takes_a_batch_by_its_setting_clamped_between_one_and_twenty_five(): void
    {
        $queue = range(900001, 900030);

        $three = $this->scanStep($this->renderState($queue));
        $this->same(3, $three['processed']);
        $this->same(array_slice($queue, 3), $three['render_queue']);
        $this->same('render', $three['step']);

        $two = $this->scanStep($this->renderState($queue, ['batch_render' => 2]));
        $this->same(2, $two['processed']);
        $this->same(array_slice($queue, 2), $two['render_queue']);

        $one = $this->scanStep($this->renderState($queue, ['batch_render' => 0]));
        $this->same(1, $one['processed']);

        $cap = $this->scanStep($this->renderState($queue, ['batch_render' => 100]));
        $this->same(25, $cap['processed']);
        $this->same(array_slice($queue, 25), $cap['render_queue']);

        $exact = $this->scanStep($this->renderState($queue, ['batch_render' => 25]));
        $this->same(25, $exact['processed']);

        $absent = $this->state('render', [], ['render_queue' => $queue]);
        unset($absent['settings']['batch_render']);
        $this->same(3, $this->scanStep($absent)['processed']);

        $this->same(3, $this->scanStep($this->renderState(array_slice($queue, 0, 3)))['processed']);
    }

    public function test_the_render_step_with_an_empty_queue_moves_on_and_keeps_its_stats_as_given(): void
    {
        $state = $this->scanStep($this->renderState([], [], ['processed' => 4]));

        $this->same('done', $state['step']);
        $this->same(4, $state['processed']);
        $this->assertArrayNotHasKey('render_stats', $state);
    }

    public function test_the_render_step_starts_stats_from_zero_when_none_were_stored(): void
    {
        [$parent] = $this->parentWithFiles(1);
        $this->serve(fn (): string => '<p>none</p>');

        $state = $this->scanStep($this->renderState([$parent]));

        $this->same(['pages' => 1, 'found' => 0, 'absent' => 1, 'error' => 0, 'offsite' => 0], $state['render_stats']);

        $odd = $this->scanStep($this->renderState([999998], [], ['render_stats' => 'broken']));

        $this->same(['pages' => 0, 'found' => 0, 'absent' => 0, 'error' => 0, 'offsite' => 0], $odd['render_stats']);
    }

    private function fetch(string $url = 'https://site.example.edu/page/', string $token = 'tok12345'): ?string
    {
        $result = $this->call('fetch_rendered', $url, $token);

        return is_string($result) ? $result : null;
    }

    public function test_a_fetch_adds_the_scan_token_so_a_cached_copy_is_not_read(): void
    {
        $log = $this->serve(fn (): string => '<p>ok</p>');

        $this->same('<p>ok</p>', $this->fetch('https://site.example.edu/page/'));
        $this->same('https://site.example.edu/page/?uams-mra-check=tok12345', $log[0]['url']);

        $this->fetch('https://site.example.edu/page/?a=1');
        $this->same('https://site.example.edu/page/?a=1&uams-mra-check=tok12345', $log[1]['url']);

        $this->fetch('https://site.example.edu/page/', '');
        $this->same('https://site.example.edu/page/', $log[2]['url']);
    }

    public function test_the_cache_bust_can_be_turned_off_per_page(): void
    {
        $seen = [];
        add_filter('uams_mra_render_cache_bust', static function ($bust, $url) use (&$seen): false {
            $seen[] = [$bust, $url];

            return false;
        }, 10, 2);
        $log = $this->serve(fn (): string => 'x');

        $this->fetch('https://site.example.edu/page/');

        $this->same([[true, 'https://site.example.edu/page/']], $seen);
        $this->same('https://site.example.edu/page/', $log[0]['url']);
    }

    public function test_the_filter_is_not_asked_when_there_is_no_token(): void
    {
        $asked = 0;
        add_filter('uams_mra_render_cache_bust', static function ($bust) use (&$asked) {
            $asked++;

            return $bust;
        });
        $this->serve(fn (): string => 'x');

        $this->fetch('https://site.example.edu/page/', '');

        $this->same(0, $asked);
    }

    public function test_a_fetch_sends_the_documented_request_arguments(): void
    {
        $log = $this->serve(fn (): string => 'x');

        $this->fetch();

        $args = $log[0]['args'];

        $this->same(15, $args['timeout']);
        $this->same(3, $args['redirection']);
        $this->assertTrue($args['sslverify']);
        $this->same('UAMS-Media-Reference-Audit/'.UAMS_MRA_VERSION.' (+'.home_url().')', $args['user-agent']);
        $this->same('no-cache', $args['headers']['Cache-Control']);
        $this->same('no-cache', $args['headers']['Pragma']);
    }

    public function test_the_request_arguments_and_ssl_check_can_be_changed_by_filters(): void
    {
        $given = [];
        add_filter('uams_mra_render_sslverify', static fn ($verify): bool => ! $verify);
        add_filter('uams_mra_render_request_args', static function ($args, $url) use (&$given) {
            $given = [$args['sslverify'], $url];
            $args['timeout'] = 99;

            return $args;
        }, 10, 2);
        $log = $this->serve(fn (): string => 'x');

        $this->fetch('https://site.example.edu/page/');

        $this->same([false, 'https://site.example.edu/page/'], $given);
        $this->same(99, $log[0]['args']['timeout']);
        $this->assertFalse($log[0]['args']['sslverify']);
    }

    public function test_a_fetch_that_fails_or_answers_with_anything_but_a_page_returns_nothing(): void
    {
        foreach ([199, 201, 204, 301, 403, 404, 500] as $code) {
            $this->serve(fn (): array => $this->reply($code, '<p>body</p>'));
            $this->assertNull($this->fetch(), 'status '.$code);
            remove_all_filters('pre_http_request');
        }

        $this->serve(fn (): \WP_Error => new \WP_Error('http_request_failed', 'no route'));
        $this->assertNull($this->fetch());
        remove_all_filters('pre_http_request');

        foreach (['', '   ', "\n\t "] as $blank) {
            $this->serve(fn (): array => $this->reply(200, $blank));
            $this->assertNull($this->fetch(), 'blank body');
            remove_all_filters('pre_http_request');
        }

        $this->serve(fn (): array => $this->reply(200, '0'));
        $this->same('0', $this->fetch());
        remove_all_filters('pre_http_request');

        $this->serve(fn (): array => $this->reply(200, "  <p>x</p>\n"));
        $this->same("  <p>x</p>\n", $this->fetch());
    }

    // post_label()

    public function test_a_post_label_names_the_type_and_title_and_flags_a_status_that_is_not_live(): void
    {
        $label = fn (array $row): string => $this->call('post_label', (object) $row);

        $this->same('page: About', $label(['post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish']));
        $this->same('attachment: Photo', $label(['post_type' => 'attachment', 'post_title' => 'Photo', 'post_status' => 'inherit']));
        $this->same('post: About (draft)', $label(['post_type' => 'post', 'post_title' => 'About', 'post_status' => 'draft']));
        $this->same('post: About', $label(['post_type' => 'post', 'post_title' => 'About', 'post_status' => '']));
        $this->same('post: About', $label(['post_title' => 'About']));
        $this->same('post: (no title)', $label([]));
        $this->same('post: (no title)', $label(['post_title' => '']));
        $this->same('post: 0', $label(['post_title' => '0']));
        $this->same('post: (no title) (private)', $label(['post_title' => '', 'post_status' => 'private']));
        $this->same('post: A (publish2)', $label(['post_title' => 'A', 'post_status' => 'publish2']));
        $this->same('post: A (Inherit)', $label(['post_title' => 'A', 'post_status' => 'Inherit']));
    }

    // attachment_bytes()

    /**
     * Write files under the uploads directory and return a cleanup callback.
     *
     * @param  array<string, string>  $files  Relative path => contents.
     */
    private function putFiles(array $files): void
    {
        $base = wp_get_upload_dir()['basedir'];

        foreach ($files as $relative => $contents) {
            wp_mkdir_p(dirname($base.'/'.$relative));
            file_put_contents($base.'/'.$relative, $contents);
            $this->written[] = $base.'/'.$relative;
        }
    }

    /**
     * Rows in the shared tables when real tables were swapped in, so the test's own can be removed.
     *
     * @var array<string, int>|null
     */
    private ?array $realTablesFrom = null;

    /**
     * Use real plugin tables for the rest of this test.
     *
     * The test case makes tables temporary, and MySQL cannot reopen a temporary table
     * inside one statement, which the recount does (it reads the references table
     * twice). Creating a table commits the test's transaction, so rows made from here on
     * are removed by hand in tear_down(). Call this before creating any fixture.
     */
    private function useRealTables(): void
    {
        global $wpdb;

        $this->realTablesFrom = [
            'posts' => $this->maxId($wpdb->posts, 'ID'),
            'postmeta' => $this->maxId($wpdb->postmeta, 'meta_id'),
        ];

        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);

        foreach ($this->plugin_tables() as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        UAMS_MRA_Store::install();
    }

    /**
     * @return array<int, string>
     */
    private function plugin_tables(): array
    {
        return [UAMS_MRA_Store::index_table(), UAMS_MRA_Store::refs_table(), UAMS_MRA_Store::results_table(), UAMS_MRA_Store::offsite_table()];
    }

    /**
     * @var array<int, string>
     */
    private array $written = [];

    private function dropRealTables(): void
    {
        global $wpdb;

        if ($this->realTablesFrom === null) {
            return;
        }

        foreach ($this->plugin_tables() as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_id > %d", $this->realTablesFrom['postmeta']));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID > %d", $this->realTablesFrom['posts']));
        // The test case runs with autocommit off, so the deletes above are rolled back unless they are committed.
        $wpdb->query('COMMIT');

        add_filter('query', [$this, '_create_temporary_tables']);
        add_filter('query', [$this, '_drop_temporary_tables']);
        $this->realTablesFrom = null;
    }

    public function tear_down(): void
    {
        $this->dropRealTables();

        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        $this->written = [];

        parent::tear_down();
    }

    public function test_attachment_bytes_adds_up_the_original_every_size_and_the_unscaled_original(): void
    {
        $this->putFiles([
            '2025/11/zb-photo.jpg' => str_repeat('a', 100),
            '2025/11/zb-photo-150x150.jpg' => str_repeat('b', 10),
            '2025/11/zb-photo-300x200.jpg' => str_repeat('c', 20),
            '2025/11/zb-photo-orig.jpg' => str_repeat('d', 1000),
        ]);
        $id = $this->attachment('2025/11/zb-photo.jpg');
        wp_update_attachment_metadata($id, [
            'original_image' => 'zb-photo-orig.jpg',
            'sizes' => [
                'thumbnail' => ['file' => 'zb-photo-150x150.jpg'],
                'medium' => ['file' => 'zb-photo-300x200.jpg'],
                'again' => ['file' => 'zb-photo-300x200.jpg'],
                'empty' => ['file' => ''],
                'nofile' => [],
                'gone' => ['file' => 'zb-photo-missing.jpg'],
            ],
        ]);

        $this->same(1130, UAMS_MRA_Scanner::attachment_bytes($id));
        $this->same(1130, UAMS_MRA_Scanner::attachment_bytes($id, '2025/11/zb-photo.jpg'));
    }

    public function test_attachment_bytes_counts_the_file_alone_when_there_is_no_metadata_and_zero_when_there_is_no_file(): void
    {
        $this->putFiles(['2025/11/zc-solo.jpg' => str_repeat('a', 7)]);
        $id = $this->attachment('2025/11/zc-solo.jpg');

        $this->same(7, UAMS_MRA_Scanner::attachment_bytes($id));

        $none = self::factory()->attachment->create();
        delete_post_meta($none, '_wp_attached_file');

        $this->same(0, UAMS_MRA_Scanner::attachment_bytes($none));
        $this->same(0, UAMS_MRA_Scanner::attachment_bytes($none, ''));
        $this->same(0, UAMS_MRA_Scanner::attachment_bytes($id, '2025/11/zc-missing.jpg'));
    }

    public function test_attachment_bytes_reads_an_explicit_file_over_the_stored_one_and_tolerates_a_leading_slash(): void
    {
        $this->putFiles(['2025/11/zd-one.jpg' => str_repeat('a', 3), '2025/11/zd-two.jpg' => str_repeat('b', 5)]);
        $id = $this->attachment('2025/11/zd-one.jpg');

        $this->same(5, UAMS_MRA_Scanner::attachment_bytes($id, '2025/11/zd-two.jpg'));
        $this->same(5, UAMS_MRA_Scanner::attachment_bytes($id, '/2025/11/zd-two.jpg'));
    }

    public function test_attachment_bytes_finds_sizes_beside_a_file_kept_at_the_top_of_the_uploads_folder(): void
    {
        $this->putFiles(['ze-root.jpg' => str_repeat('a', 4), 'ze-root-150x150.jpg' => str_repeat('b', 6)]);
        $id = $this->attachment('ze-root.jpg');
        wp_update_attachment_metadata($id, ['sizes' => ['thumbnail' => ['file' => 'ze-root-150x150.jpg']]]);

        $this->same(10, UAMS_MRA_Scanner::attachment_bytes($id));
    }

    public function test_attachment_bytes_ignores_a_folder_and_a_malformed_size_list(): void
    {
        $this->putFiles(['2025/11/zf-main.jpg' => str_repeat('a', 9)]);
        $dir = wp_get_upload_dir()['basedir'].'/2025/11/zf-folder.jpg';
        wp_mkdir_p($dir);
        $this->written[] = $dir;
        $id = $this->attachment('2025/11/zf-main.jpg');
        wp_update_attachment_metadata($id, ['original_image' => 'zf-folder.jpg', 'sizes' => 'broken']);

        $this->same(9, UAMS_MRA_Scanner::attachment_bytes($id));

        wp_update_attachment_metadata($id, ['sizes' => ['x' => ['file' => 'zf-folder.jpg']]]);

        $this->same(9, UAMS_MRA_Scanner::attachment_bytes($id));
    }

    // A whole scan

    /**
     * Run a started scan to the end, recording each step it passed through.
     *
     * @return array<int, string>
     */
    private function runToEnd(int $guard = 200): array
    {
        $seen = [];

        while ($guard-- > 0) {
            $state = UAMS_MRA_Scanner::step();
            $seen[] = (string) ($state['step'] ?? '');

            if (($state['step'] ?? '') === 'done') {
                return $seen;
            }
        }

        $this->fail('The scan did not finish.');
    }

    /**
     * A site with one linked file, one file only a rendered page shows, and one nothing uses.
     *
     * @return array{int, int, int, int}
     */
    private function smallSite(): array
    {
        $linked = $this->attachment('2025/11/zg-linked.jpg');
        $parent = self::factory()->post->create(['post_title' => 'Gallery', 'post_status' => 'publish', 'post_content' => '<img src="'.$this->url('2025/11/zg-linked.jpg').'">']);
        $shown = $this->attachment('2025/11/zg-shown.jpg', $parent);
        $unused = $this->attachment('2025/11/zg-unused.jpg');

        return [$linked, $parent, $shown, $unused];
    }

    public function test_a_scan_over_a_small_site_walks_every_step_and_reaches_the_end(): void
    {
        $this->useRealTables();

        [$linked, $parent, $shown, $unused] = $this->smallSite();
        $this->serve(fn (): string => '<img src="'.$this->url('2025/11/zg-shown.jpg').'">');

        global $wpdb;
        $attachments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
        $state = UAMS_MRA_Scanner::start();

        $this->same('index', $state['step']);

        $seen = $this->runToEnd();
        $final = UAMS_MRA_Scanner::get_state();

        $this->same(['posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'commentmeta', 'finalize', 'render', 'done'], $seen);
        $this->same('done', $final['step']);
        $this->same('Scan complete', $final['message']);
        $this->assertGreaterThanOrEqual($state['started'], $final['finished']);
        $this->same($attachments, $final['attachments']);
        $this->assertGreaterThanOrEqual(3, $attachments);

        $this->same('1', $this->result($linked)['ref_count']);
        $this->same('found', $this->result($shown)['render_state']);
        $this->same('1', $this->result($shown)['ref_count']);
        $this->same('0', $this->result($unused)['ref_count']);
        $this->same('', $this->result($unused)['render_state']);
        $this->assertGreaterThan(0, $parent);
    }

    public function test_a_scan_that_stops_part_way_continues_from_where_it_was(): void
    {
        $this->useRealTables();

        [$linked] = $this->smallSite();
        $this->serve(fn (): string => '<p>x</p>');

        $started = UAMS_MRA_Scanner::start();

        while (UAMS_MRA_Scanner::get_state()['step'] !== 'options') {
            UAMS_MRA_Scanner::step();
        }

        $midway = UAMS_MRA_Scanner::get_state();
        $kept = $this->refs();

        $this->assertNotSame([], $kept);
        $this->same($started['started'], $midway['started']);

        $this->runToEnd();

        $final = UAMS_MRA_Scanner::get_state();

        $this->same('done', $final['step']);
        $this->same($started['started'], $final['started']);
        $this->same($kept, array_slice($this->refs(), 0, count($kept)));
        $this->same('1', $this->result($linked)['ref_count']);
    }

    // Paths that only a later page, row or setting can show

    public function test_the_posts_step_reads_the_content_before_the_excerpt(): void
    {
        $post = self::factory()->post->create([
            'post_content' => 'https://old.example.edu/wp-content/uploads/2020/01/zh-first.jpg',
            'post_excerpt' => 'https://old.example.edu/wp-content/uploads/2020/01/zh-second.jpg',
        ]);

        $this->scanStep($this->state('posts', [], ['cursor' => $post - 1]));

        $this->same(['zh-first.jpg', 'zh-second.jpg'], array_column($this->offsite(), 'base_norm'));
    }

    public function test_a_skipped_meta_key_does_not_end_the_batch(): void
    {
        $attachment = $this->attachment('2025/11/zi-a.jpg');
        $post = self::factory()->post->create();
        $before = $this->maxId($GLOBALS['wpdb']->postmeta, 'meta_id');
        add_post_meta($post, '_edit_lock', '1:1');
        add_post_meta($post, 'zi_hero', $this->url('2025/11/zi-a.jpg'));

        $this->scanStep($this->state('postmeta', [], ['cursor' => $before]));

        $this->same([(string) $attachment], array_column($this->refs(), 'attachment_id'));
    }

    public function test_an_empty_option_does_not_end_the_batch(): void
    {
        $attachment = $this->attachment('2025/11/zj-a.jpg');
        $before = $this->maxId($GLOBALS['wpdb']->options, 'option_id');
        add_option('zj_empty', '');
        add_option('zj_hero', $this->url('2025/11/zj-a.jpg'));

        $this->scanStep($this->state('options', [], ['cursor' => $before]));

        $this->same([(string) $attachment], array_column($this->refs(), 'attachment_id'));
    }

    public function test_the_render_step_is_skipped_by_its_setting_even_when_pages_are_waiting(): void
    {
        $this->parentWithFiles(1);

        $state = $this->call('advance', $this->state('finalize', ['include_render' => 0]));

        $this->same('done', $state['step']);
        $this->same([], $state['render_queue']);
        $this->assertArrayNotHasKey('render_token', $state);

        $on = $this->call('advance', $this->state('finalize', ['include_render' => 1]));

        $this->same('render', $on['step']);
        $this->assertCount(1, $on['render_queue']);
    }

    public function test_a_queue_with_exactly_as_many_pages_as_the_ceiling_is_not_reported_as_capped(): void
    {
        $this->parentWithFiles(1);
        $this->parentWithFiles(1);

        $exact = $this->call('build_render_queue', $this->state('finalize', ['render_max_pages' => 2]));

        $this->assertCount(2, $exact['render_queue']);
        $this->same(0, $exact['render_capped']);
        $this->same(0, $exact['render_skipped']);
    }

    public function test_the_render_token_is_eight_letters_and_digits_with_no_symbols(): void
    {
        $asked = [];
        add_filter('random_password', static function ($password, $length, $special, $extra) use (&$asked) {
            $asked[] = [$length, $special, $extra];

            return $password;
        }, 10, 4);

        $state = $this->call('build_render_queue', $this->state('finalize'));

        $this->same([[8, false, false]], $asked);
        $this->same(8, strlen($state['render_token']));
    }

    public function test_a_page_with_nothing_waiting_does_not_stop_the_pages_after_it(): void
    {
        $this->useRealTables();

        [$explained, $explained_files] = $this->parentWithFiles(1);
        $this->putResult($explained_files[0], ['parent_id' => $explained, 'is_attached' => 1, 'ref_count' => 2]);
        [$live, $live_files] = $this->parentWithFiles(1);
        $this->serve(fn (): string => '<p>none</p>');

        $this->scanStep($this->renderState([$explained, $live]));

        $this->same('absent', $this->result($live_files[0])['render_state']);
    }

    public function test_a_page_with_no_permalink_does_not_stop_the_pages_after_it(): void
    {
        $this->useRealTables();

        $this->putResult(555002, ['parent_id' => 777778, 'is_attached' => 1, 'ref_count' => 0]);
        [$live, $live_files] = $this->parentWithFiles(1);
        $this->serve(fn (): string => '<p>none</p>');

        $this->scanStep($this->renderState([777778, $live]));

        $this->same('skipped', $this->result(555002)['render_state']);
        $this->same('absent', $this->result($live_files[0])['render_state']);
    }

    public function test_a_page_that_fails_does_not_stop_the_pages_after_it(): void
    {
        $this->useRealTables();

        [$broken, $broken_files] = $this->parentWithFiles(1);
        [$live, $live_files] = $this->parentWithFiles(1);
        $this->serve(static fn (string $url): string|\WP_Error => str_contains($url, 'p='.$broken.'&') ? new \WP_Error('x', 'down') : '<p>none</p>');

        $this->scanStep($this->renderState([$broken, $live]));

        $this->same('error', $this->result($broken_files[0])['render_state']);
        $this->same('absent', $this->result($live_files[0])['render_state']);
    }

    public function test_pending_files_ignore_a_row_with_no_parent_even_when_it_is_marked_attached(): void
    {
        $this->putResult(555003, ['parent_id' => 0, 'is_attached' => 1, 'ref_count' => 0]);

        $this->same([], $this->call('pending_attachment_ids', [0]));
    }

    public function test_a_file_with_no_stored_path_counts_no_bytes_even_when_its_metadata_lists_sizes(): void
    {
        $this->putFiles(['zk-size.jpg' => str_repeat('a', 5)]);
        $id = self::factory()->attachment->create();
        delete_post_meta($id, '_wp_attached_file');
        wp_update_attachment_metadata($id, ['sizes' => ['thumbnail' => ['file' => 'zk-size.jpg']]]);

        $this->same(0, UAMS_MRA_Scanner::attachment_bytes($id));
    }

    /**
     * @expectedDeprecated wpmu_new_blog
     */
    public function test_the_network_step_hands_the_table_prefix_back_when_it_is_done(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network.');
        }

        global $wpdb;

        $blog = self::factory()->blog->create();

        $this->scanStep($this->state('network', [], ['site_queue' => [$blog]]));

        switch_to_blog($blog);
        $table = UAMS_MRA_Store::refs_table();
        $expected = $wpdb->prefix.'uams_mra_refs';
        restore_current_blog();

        $this->same($expected, $table);
    }

    public function test_a_render_state_with_no_token_fetches_the_plain_permalink(): void
    {
        [$parent] = $this->parentWithFiles(1);
        $log = $this->serve(fn (): string => '<p>none</p>');
        $state = $this->renderState([$parent]);
        unset($state['render_token']);

        $this->scanStep($state);

        $this->same((string) get_permalink($parent), $log[0]['url']);
    }

    public function test_a_revision_row_and_an_empty_value_do_not_end_the_postmeta_batch(): void
    {
        $attachment = $this->attachment('2025/11/zl-a.jpg');
        $post = self::factory()->post->create();
        $revision = wp_insert_post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post.'-revision-v1', 'post_title' => 'Rev']);
        $before = $this->maxId($GLOBALS['wpdb']->postmeta, 'meta_id');
        add_post_meta($revision, 'zl_old', 'x');
        add_post_meta($post, 'zl_empty', '');
        add_post_meta($post, 'zl_hero', $this->url('2025/11/zl-a.jpg'));

        $this->scanStep($this->state('postmeta', ['include_revisions' => 0], ['cursor' => $before]));

        $this->same([(string) $attachment], array_column($this->refs(), 'attachment_id'));
    }

    /**
     * @expectedDeprecated wpmu_new_blog
     */
    public function test_the_network_step_asks_for_up_to_500_other_sites_by_id(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network.');
        }

        $other = self::factory()->blog->create();
        $asked = [];
        add_action('pre_get_sites', static function ($query) use (&$asked): void {
            $asked = $query->query_vars;
        });

        $state = $this->call('advance', $this->state('commentmeta', ['include_network' => 1]));

        $this->same([$other], $state['site_queue']);
        $this->same(500, $asked['number']);
        $this->same('ids', $asked['fields']);
        $this->same([get_current_blog_id()], $asked['site__not_in']);
    }
}
