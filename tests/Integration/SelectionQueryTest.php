<?php

/**
 * UAMS_MRA_Selection's queries against a real WordPress and database.
 *
 * The Unit suite pins the stored set and the pasted-text parsing with a fake
 * database. These tests run the filter match, the row read and the filename lookup
 * through real SQL, where a wrong LIMIT, a wrong order or an unescaped wildcard shows up.
 *
 * @see includes/class-selection.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Selection;
use UAMS_MRA_Store;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class SelectionQueryTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();

        $_REQUEST = ['view' => 'all'];
    }

    public function tear_down(): void
    {
        $_REQUEST = [];

        parent::tear_down();
    }

    /**
     * Store one results row.
     */
    private function row(int $id, int $bytes = 10, string $file = '', string $title = '', int $refs = 0): void
    {
        global $wpdb;

        $wpdb->insert(UAMS_MRA_Store::results_table(), [
            'attachment_id' => $id,
            'bytes' => $bytes,
            'file' => $file,
            'title' => $title,
            'ref_count' => $refs,
        ]);
    }

    /**
     * Store a results row for a real attachment, and return its ID.
     */
    private function attachment(string $file, int $bytes = 10): int
    {
        $id = self::factory()->attachment->create();

        $this->row($id, $bytes, $file);

        return $id;
    }

    public function test_the_filter_match_is_ordered_largest_first(): void
    {
        $this->row(1, 100);
        $this->row(2, 300);
        $this->row(3, 200);

        $this->assertSame([2, 3, 1], UAMS_MRA_Selection::ids_matching_filter());
    }

    public function test_the_filter_match_follows_the_view_on_screen(): void
    {
        $this->row(1, 10, '', '', 0);
        $this->row(2, 20, '', '', 3);

        $_REQUEST = ['view' => 'unreferenced'];

        $this->assertSame([1], UAMS_MRA_Selection::ids_matching_filter());
    }

    public function test_the_filter_match_follows_the_search_text_through_a_prepared_query(): void
    {
        $this->row(1, 30, '2025/11/budget.pdf', 'Budget');
        $this->row(2, 20, '2025/11/other.pdf', 'Other');
        $this->row(3, 10, '2025/11/a.pdf', 'Annual budget summary');

        $_REQUEST = ['view' => 'all', 's' => 'budget'];

        $this->assertSame([1, 3], UAMS_MRA_Selection::ids_matching_filter());

        $_REQUEST = ['view' => 'all', 's' => '100%'];

        $this->assertSame([], UAMS_MRA_Selection::ids_matching_filter());
    }

    public function test_the_filter_match_returns_one_more_than_the_limit_so_the_caller_can_tell(): void
    {
        global $wpdb;

        $max = UAMS_MRA_Selection::MAX;
        $table = UAMS_MRA_Store::results_table();

        $wpdb->query("INSERT INTO {$table} (attachment_id, bytes) SELECT seq, seq FROM (SELECT (a.n + b.n * 10 + c.n * 100 + d.n * 1000 + e.n * 10000) + 1 AS seq FROM
            (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
            (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
            (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c,
            (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d,
            (SELECT 0 n UNION SELECT 1) e) t WHERE seq <= ".($max + 20));

        $this->assertSame($max + 20, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"));

        $ids = UAMS_MRA_Selection::ids_matching_filter();

        $this->assertCount($max + 1, $ids);
        $this->assertSame($max + 20, $ids[0]);
    }

    public function test_the_rows_of_a_set_come_back_largest_first_and_only_for_that_set(): void
    {
        $this->row(1, 100);
        $this->row(2, 300);
        $this->row(3, 200);

        $rows = UAMS_MRA_Selection::rows([1, 2, '9']);

        $this->assertSame([2, 1], array_map(static fn (array $row): int => (int) $row['attachment_id'], $rows));
        $this->assertSame('300', $rows[0]['bytes']);
    }

    public function test_the_rows_of_an_empty_set_are_empty_without_asking_the_database(): void
    {
        global $wpdb;

        $before = $wpdb->num_queries;

        $this->assertSame([], UAMS_MRA_Selection::rows([]));
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_a_filename_matches_the_stored_name_or_the_end_of_a_stored_path(): void
    {
        $exact = $this->attachment('plain.pdf');
        $nested = $this->attachment('2025/11/report.pdf');

        $out = UAMS_MRA_Selection::resolve_text("plain.pdf\nreport.pdf\nport.pdf\nxplain.pdf");

        $this->assertSame([$exact, $nested], $out['ids']);
        $this->assertSame(['port.pdf', 'xplain.pdf'], $out['unmatched']);
    }

    public function test_a_filename_wildcard_is_read_as_the_character_it_is(): void
    {
        $this->attachment('2025/11/abc.pdf');
        $literal = $this->attachment('2025/11/a_c.pdf');

        $this->assertSame([$literal], UAMS_MRA_Selection::resolve_text('a_c.pdf')['ids']);
        $this->assertSame([], UAMS_MRA_Selection::resolve_text('a%c.pdf')['ids']);
    }

    public function test_the_lowest_attachment_wins_when_two_files_share_a_name(): void
    {
        $first = $this->attachment('2024/01/dup.pdf');
        $this->attachment('2025/11/dup.pdf');

        $this->assertSame([$first], UAMS_MRA_Selection::resolve_text('dup.pdf')['ids']);
    }

    public function test_a_numeric_token_must_be_an_attachment_and_a_url_falls_back_to_its_filename(): void
    {
        $id = $this->attachment('2025/11/hero image.jpg');
        $post = (int) self::factory()->post->create();

        $out = UAMS_MRA_Selection::resolve_text("{$id}\n{$post}\nhttps://cdn.example.net/media/hero%20image.jpg?v=2");

        $this->assertSame([$id], $out['ids']);
        $this->assertSame([(string) $post], $out['unmatched']);
    }
}
