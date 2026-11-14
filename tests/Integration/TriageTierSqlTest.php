<?php

/**
 * UAMS_MRA_Triage's SQL against a real WordPress and database.
 *
 * The Unit suite pins the strings. These tests run them, which is the only way
 * to show that the tier a row is counted in is the tier tier() gives it, and that
 * the disposition queries read the post meta the way set() writes it.
 *
 * @see includes/class-triage.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Store;
use UAMS_MRA_Triage;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class TriageTierSqlTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
    }

    /**
     * Store one results row, and the attachment post its marks hang from.
     */
    private function row(string $mime, int $attached, string $render = '', int $bytes = 10): int
    {
        global $wpdb;

        $id = self::factory()->attachment->create(['post_mime_type' => $mime]);

        $wpdb->insert(UAMS_MRA_Store::results_table(), [
            'attachment_id' => $id,
            'is_attached' => $attached,
            'mime' => $mime,
            'render_state' => $render,
            'bytes' => $bytes,
        ]);

        return $id;
    }

    /**
     * Attachment IDs the results table selects under a WHERE fragment.
     *
     * @return list<int>
     */
    private function selected(string $where): array
    {
        global $wpdb;

        return array_map(intval(...), (array) $wpdb->get_col('SELECT attachment_id FROM '.UAMS_MRA_Store::results_table()." WHERE {$where} ORDER BY attachment_id"));
    }

    public function test_every_row_is_selected_by_exactly_the_tier_tier_gives_it(): void
    {
        $fixtures = [
            ['image/jpeg', 0, ''],
            ['image/jpeg', 1, ''],
            ['image/jpeg', 1, 'absent'],
            ['image/jpeg', 1, 'found'],
            ['image/jpeg', 1, 'error'],
            ['application/pdf', 0, ''],
            ['application/pdf', 1, ''],
            ['application/pdf', 1, 'absent'],
            ['video/mp4', 0, ''],
            ['audio/mpeg', 1, 'absent'],
            ['text/csv', 0, ''],
            ['text/plain', 0, ''],
            ['', 0, ''],
        ];

        $expected = ['verify' => [], 'ask' => [], 'clear' => []];

        foreach ($fixtures as [$mime, $attached, $render]) {
            $id = $this->row($mime, $attached, $render);
            $expected[UAMS_MRA_Triage::tier(['is_attached' => $attached, 'mime' => $mime, 'render_state' => $render])][] = $id;
        }

        foreach ($expected as $tier => $ids) {
            $this->assertNotSame([], $ids, "the {$tier} tier needs a row for the check to mean anything");
            $this->assertSame($ids, $this->selected(UAMS_MRA_Triage::tier_where($tier)), "tier_where('{$tier}')");
        }

        $this->assertCount(count($fixtures), $this->selected(UAMS_MRA_Triage::tier_where('anything')));
    }

    public function test_counts_agree_with_the_rows_each_tier_selects(): void
    {
        $this->row('image/jpeg', 1);
        $this->row('image/jpeg', 0);
        $this->row('application/pdf', 0);
        $this->row('video/mp4', 0);

        $counts = UAMS_MRA_Triage::counts();

        $this->assertSame(['verify' => 1, 'ask' => 2, 'clear' => 1], $counts['tier']);
        $this->assertSame(4, $counts['none']);
    }

    public function test_counts_stay_inside_the_scope_given(): void
    {
        $this->row('image/jpeg', 1);
        $this->row('image/jpeg', 0);

        $counts = UAMS_MRA_Triage::counts('is_attached = 0');

        $this->assertSame(['verify' => 0, 'ask' => 0, 'clear' => 1], $counts['tier']);
        $this->assertSame(1, $counts['none']);
    }

    public function test_marks_are_read_back_by_the_queries_that_use_them(): void
    {
        $keep = $this->row('image/jpeg', 0, '', 100);
        $delete = $this->row('image/jpeg', 0, '', 4000);
        $other_delete = $this->row('image/jpeg', 0, '', 500);
        $unmarked = $this->row('image/jpeg', 0);

        UAMS_MRA_Triage::set($keep, 'keep', 'a note');
        UAMS_MRA_Triage::set($delete, 'delete', '');
        UAMS_MRA_Triage::set($other_delete, 'delete', '');

        $this->assertSame(['d' => 'keep', 'n' => 'a note'], UAMS_MRA_Triage::get($keep));
        $this->assertSame(['d' => '', 'n' => ''], UAMS_MRA_Triage::get($unmarked));

        $this->assertSame([$delete, $other_delete], $this->selected(UAMS_MRA_Triage::disposition_where('delete')));
        $this->assertSame([$unmarked], $this->selected(UAMS_MRA_Triage::disposition_where('none')));

        $ids = UAMS_MRA_Triage::ids_marked('delete');
        sort($ids);
        $this->assertSame([$delete, $other_delete], $ids);
        $this->assertSame([], UAMS_MRA_Triage::ids_marked('ask'));

        $counts = UAMS_MRA_Triage::counts();

        $this->assertSame(['keep' => 1, 'ask' => 0, 'delete' => 2], $counts['disp']);
        $this->assertSame(4500, $counts['delete_bytes']);
        $this->assertSame(1, $counts['none']);
    }

    public function test_a_mark_on_a_file_the_last_scan_did_not_produce_does_not_resurface(): void
    {
        $gone = self::factory()->attachment->create();

        UAMS_MRA_Triage::set($gone, 'delete', '');

        $this->assertSame([], UAMS_MRA_Triage::ids_marked('delete'));
    }

    public function test_clearing_a_mark_leaves_the_file_undecided(): void
    {
        $id = $this->row('image/jpeg', 0);

        UAMS_MRA_Triage::set($id, 'delete', 'x');
        UAMS_MRA_Triage::set($id, '', '');

        $this->assertSame(['d' => '', 'n' => ''], UAMS_MRA_Triage::get($id));
        $this->assertSame([$id], $this->selected(UAMS_MRA_Triage::disposition_where('none')));
    }
}
