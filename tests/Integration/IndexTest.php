<?php

/**
 * UAMS_MRA_Index against a real WordPress and database.
 *
 * The Unit suite pins normalize() and the derivative filter. These tests run the
 * batch builder and the three lookups through real queries, which is where a
 * wrong placeholder, a missed chunk or a stale row shows up.
 *
 * @see includes/class-index.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Index;
use UAMS_MRA_Store;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class IndexTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
    }

    /**
     * Create an attachment whose stored file is the given upload path.
     */
    private function attachment(string $file): int
    {
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);

        update_post_meta($id, '_wp_attached_file', $file);

        return $id;
    }

    /**
     * Every index row as attachment ID => [path_norm, base_norm].
     *
     * @return array<int, array{string, string}>
     */
    private function rows(): array
    {
        global $wpdb;

        $out = [];

        foreach ((array) $wpdb->get_results('SELECT attachment_id, path_norm, base_norm FROM '.UAMS_MRA_Store::index_table()) as $row) {
            $out[(int) $row->attachment_id] = [(string) $row->path_norm, (string) $row->base_norm];
        }

        return $out;
    }

    public function test_a_batch_indexes_attachments_in_id_order_and_reports_where_it_stopped(): void
    {
        $a = $this->attachment('2025/11/a-300x200.jpg');
        $b = $this->attachment('2025/11/b.jpg');
        $c = $this->attachment('2025/12/c-scaled.jpg');
        self::factory()->post->create();

        $first = UAMS_MRA_Index::build_batch($a - 1, 2);

        $this->assertSame([2, $b], $first);
        $this->assertSame([$a => ['2025/11/a.jpg', 'a.jpg'], $b => ['2025/11/b.jpg', 'b.jpg']], $this->rows());

        $second = UAMS_MRA_Index::build_batch($b, 2);

        $this->assertSame([1, $c], $second);
        $this->assertSame(['2025/12/c.jpg', 'c.jpg'], $this->rows()[$c]);
    }

    public function test_a_batch_past_the_last_attachment_returns_the_cursor_it_was_given(): void
    {
        $a = $this->attachment('2025/11/a.jpg');

        $this->assertSame([0, $a], UAMS_MRA_Index::build_batch($a, 10));
    }

    public function test_indexing_again_replaces_a_row_instead_of_adding_one(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        update_post_meta($a, '_wp_attached_file', '2025/11/renamed.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $this->assertSame([$a => ['2025/11/renamed.jpg', 'renamed.jpg']], $this->rows());
    }

    public function test_an_attachment_with_no_stored_file_is_indexed_with_empty_names(): void
    {
        $id = (int) self::factory()->attachment->create();

        UAMS_MRA_Index::build_batch($id - 1, 10);

        $this->assertSame([$id => ['', '']], $this->rows());
    }

    public function test_a_path_resolves_to_the_attachment_whatever_size_it_names(): void
    {
        $a = $this->attachment('2025/11/hero.jpg');
        $this->attachment('2025/11/other.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $this->assertSame([$a], UAMS_MRA_Index::resolve_paths(['https://example.edu/wp-content/uploads/2025/11/hero-768x432.jpg']));
    }

    public function test_a_shared_file_name_returns_every_attachment_that_carries_it(): void
    {
        $a = $this->attachment('2024/01/report.pdf');
        $b = $this->attachment('2025/11/report.pdf');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $ids = UAMS_MRA_Index::resolve_paths(['report.pdf']);
        sort($ids);

        $this->assertSame([$a, $b], $ids);
    }

    public function test_a_name_nobody_has_and_an_empty_candidate_resolve_to_nothing(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $this->assertSame([], UAMS_MRA_Index::resolve_paths(['missing.jpg', '', '/']));
        $this->assertSame([], UAMS_MRA_Index::resolve_paths([]));
        $this->assertSame([], UAMS_MRA_Index::resolve_map([]));
        $this->assertSame([], UAMS_MRA_Index::resolve_map(['', '?ver=1']));
    }

    public function test_the_map_says_which_candidate_found_which_attachments(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        $b = $this->attachment('2025/11/b.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $map = UAMS_MRA_Index::resolve_map(['a-300x200.jpg', 'a.jpg', 'b.jpg']);

        $this->assertSame(['a.jpg' => [$a], 'b.jpg' => [$b]], $map);
    }

    /**
     * The chunk sizes (400 candidates, 500 IDs) exist to stay inside a packet limit, so
     * raising one changes nothing a test can see; a mutant on either number survives
     * by nature. This pins that a list longer than one chunk is still all looked up.
     */
    public function test_more_candidates_than_one_query_holds_are_all_looked_up(): void
    {
        $a = $this->attachment('2025/11/needle.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $candidates = array_map(static fn (int $n): string => "filler-{$n}.jpg", range(1, 400));
        $candidates[] = 'needle.jpg';

        $this->assertSame([$a], UAMS_MRA_Index::resolve_paths($candidates));
    }

    public function test_only_real_attachment_ids_survive_the_filter(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        $b = $this->attachment('2025/11/b.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $ids = UAMS_MRA_Index::filter_attachment_ids([$b, $a, $a, 0, 999999, '7x']);
        sort($ids);

        $this->assertSame([$a, $b], $ids);
        $this->assertSame([], UAMS_MRA_Index::filter_attachment_ids([]));
        $this->assertSame([], UAMS_MRA_Index::filter_attachment_ids([0, 0]));
    }

    /**
     * See the note on the candidate-chunk test: this pins the second chunk is read.
     */
    public function test_more_ids_than_one_query_holds_are_all_checked(): void
    {
        $a = $this->attachment('2025/11/a.jpg');
        UAMS_MRA_Index::build_batch($a - 1, 10);

        $ids = range($a + 1000, $a + 1500);
        $ids[] = $a;

        $this->assertSame([$a], UAMS_MRA_Index::filter_attachment_ids($ids));
    }
}
