<?php

/**
 * UAMS_MRA_Store against a real WordPress and database.
 *
 * The class talks to $wpdb throughout, so every method is run through real
 * queries: the table names and the schema, the reference and offsite writes with
 * their truncation, duplicate and chunking rules, and the reads built on them.
 *
 * Mutants this suite cannot kill, each equivalent: an empty prefix override stored as
 * an empty string rather than null (prefix() reads either as "none"); the three
 * chunk sizes (500 rows, 200 IDs twice), which are packet limits whose only effect
 * is how many statements run; and the host not being cut to 191 characters in PHP
 * (INSERT IGNORE cuts it to the same 191 in the column).
 *
 * @see includes/class-store.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Store;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class StoreTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        UAMS_MRA_Store::install();
        UAMS_MRA_Store::reset();
    }

    public function tear_down(): void
    {
        UAMS_MRA_Store::set_prefix_override(null);

        parent::tear_down();
    }

    /**
     * Run a check against real tables instead of the temporary ones the test case makes.
     *
     * The test case turns CREATE and DROP TABLE into their temporary forms. Two things
     * need the real ones: SHOW TABLES does not list a temporary table, which the
     * stale-key check reads, and MySQL cannot reopen a temporary table inside one
     * statement, which the recount does (it reads the references table twice). The real
     * tables are dropped afterward and the temporary ones put back.
     */
    private function withRealTables(callable $check): void
    {
        global $wpdb;

        $tables = [UAMS_MRA_Store::index_table(), UAMS_MRA_Store::refs_table(), UAMS_MRA_Store::results_table(), UAMS_MRA_Store::offsite_table()];

        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);

        try {
            foreach ($tables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS {$table}");
            }

            UAMS_MRA_Store::install();
            $check();
        } finally {
            foreach ($tables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS {$table}");
            }

            add_filter('query', [$this, '_create_temporary_tables']);
            add_filter('query', [$this, '_drop_temporary_tables']);
            UAMS_MRA_Store::install();
            UAMS_MRA_Store::reset();
        }
    }

    /**
     * Store one results row.
     *
     * @param  array<string, int|string>  $values
     */
    public function result(?int $id = null, array $values = []): void
    {
        global $wpdb;

        $wpdb->insert(UAMS_MRA_Store::results_table(), ['attachment_id' => $id] + $values);
    }

    /**
     * A results row as the database holds it.
     *
     * @return array<string, string>
     */
    private function resultRow(int $id): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.UAMS_MRA_Store::results_table().' WHERE attachment_id = %d', $id), ARRAY_A);

        $this->assertIsArray($row);

        return $row;
    }

    /**
     * An offsite row with every field the writer reads.
     *
     * @param  array<string, int|string>  $values
     * @return array<string, int|string>
     */
    private function offsite(string $url, array $values = []): array
    {
        return $values + [
            'url' => $url,
            'host' => 'old.example.edu',
            'base_norm' => 'a.jpg',
            'source_type' => 'content',
            'source_id' => 1,
            'source_label' => 'A page',
        ];
    }

    /**
     * Hand a value over with its type hidden, as a caller outside this code may.
     */
    private static function loose(mixed $value): mixed
    {
        return $value;
    }

    private function rowCount(string $table, string $where = '1=1'): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }

    /**
     * Column names of a unique key, in order.
     *
     * @return list<string>
     */
    private function keyColumns(string $table, string $key): array
    {
        global $wpdb;

        $columns = [];

        foreach ((array) $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = '{$key}'") as $row) {
            $columns[(int) $row->Seq_in_index] = (string) $row->Column_name;
        }

        ksort($columns);

        return array_values($columns);
    }

    public function test_table_names_follow_the_site_prefix_and_the_override(): void
    {
        global $wpdb;

        $this->assertSame($wpdb->prefix.'uams_mra_index', UAMS_MRA_Store::index_table());
        $this->assertSame($wpdb->prefix.'uams_mra_refs', UAMS_MRA_Store::refs_table());
        $this->assertSame($wpdb->prefix.'uams_mra_results', UAMS_MRA_Store::results_table());
        $this->assertSame($wpdb->prefix.'uams_mra_offsite', UAMS_MRA_Store::offsite_table());

        UAMS_MRA_Store::set_prefix_override('wp_7_');

        $this->assertSame('wp_7_uams_mra_index', UAMS_MRA_Store::index_table());
        $this->assertSame('wp_7_uams_mra_refs', UAMS_MRA_Store::refs_table());
        $this->assertSame('wp_7_uams_mra_results', UAMS_MRA_Store::results_table());
        $this->assertSame('wp_7_uams_mra_offsite', UAMS_MRA_Store::offsite_table());

        UAMS_MRA_Store::set_prefix_override('');

        $this->assertSame($wpdb->prefix.'uams_mra_index', UAMS_MRA_Store::index_table());
    }

    public function test_an_empty_override_is_the_same_as_none(): void
    {
        global $wpdb;

        UAMS_MRA_Store::set_prefix_override('wp_7_');
        UAMS_MRA_Store::set_prefix_override(null);

        $this->assertSame($wpdb->prefix.'uams_mra_refs', UAMS_MRA_Store::refs_table());
    }

    public function test_install_creates_the_four_tables_with_their_keys_and_records_the_version(): void
    {
        global $wpdb;

        delete_option('uams_mra_db_version');

        UAMS_MRA_Store::install();

        foreach ([UAMS_MRA_Store::index_table(), UAMS_MRA_Store::refs_table(), UAMS_MRA_Store::results_table(), UAMS_MRA_Store::offsite_table()] as $table) {
            $this->assertNotEmpty($wpdb->get_results("SHOW COLUMNS FROM {$table}"), "{$table} exists");
        }

        $this->assertSame(['attachment_id', 'source_type', 'source_id', 'site_id'], $this->keyColumns(UAMS_MRA_Store::refs_table(), 'uniq_ref'));
        $this->assertSame(['url_hash', 'source_type', 'source_pk'], $this->keyColumns(UAMS_MRA_Store::offsite_table(), 'uniq_hit'));
        $this->assertSame(['base_norm'], $this->keyColumns(UAMS_MRA_Store::index_table(), 'base_norm'));
        $this->assertSame(['render_state'], $this->keyColumns(UAMS_MRA_Store::results_table(), 'render_state'));
        $this->assertSame(UAMS_MRA_VERSION, get_option('uams_mra_db_version'));
    }

    public function test_installing_twice_keeps_the_rows(): void
    {
        UAMS_MRA_Store::add_refs([['attachment_id' => 1, 'source_type' => 'content', 'source_id' => 2, 'source_label' => 'x']]);

        UAMS_MRA_Store::install();

        $this->assertSame(1, $this->rowCount(UAMS_MRA_Store::refs_table()));
    }

    public function test_install_replaces_an_offsite_key_that_still_names_the_object_instead_of_the_row(): void
    {
        global $wpdb;

        $offsite = UAMS_MRA_Store::offsite_table();

        $this->withRealTables(function () use ($wpdb, $offsite): void {
            $this->assertSame(['url_hash', 'source_type', 'source_pk'], $this->keyColumns($offsite, 'uniq_hit'));

            $wpdb->query("ALTER TABLE {$offsite} DROP INDEX uniq_hit, ADD UNIQUE KEY uniq_hit (url_hash,source_type,source_id)");
            $this->assertSame(['url_hash', 'source_type', 'source_id'], $this->keyColumns($offsite, 'uniq_hit'));

            UAMS_MRA_Store::install();

            $this->assertSame(['url_hash', 'source_type', 'source_pk'], $this->keyColumns($offsite, 'uniq_hit'));

            // A key that is already current is left alone, rows and all.
            UAMS_MRA_Store::add_offsite([$this->offsite('https://old.example.edu/a.jpg')]);
            UAMS_MRA_Store::install();

            $this->assertSame('url_hash,source_type,source_pk', implode(',', $this->keyColumns($offsite, 'uniq_hit')));
            $this->assertSame(1, $this->rowCount($offsite));
        });
    }

    public function test_install_issues_no_drop_when_the_offsite_table_has_no_such_key_or_a_current_one(): void
    {
        global $wpdb;

        $offsite = UAMS_MRA_Store::offsite_table();
        $drops = [];

        $this->withRealTables(function () use ($wpdb, $offsite, &$drops): void {
            $record = static function (string $query) use (&$drops): string {
                if (stripos($query, 'DROP INDEX') !== false) {
                    $drops[] = $query;
                }

                return $query;
            };

            add_filter('query', $record);

            try {
                UAMS_MRA_Store::install();
                $this->assertSame([], $drops, 'a current key is left alone');

                $wpdb->query("ALTER TABLE {$offsite} DROP INDEX uniq_hit");
                $drops = [];

                UAMS_MRA_Store::install();
                $this->assertSame([], $drops, 'no key means nothing to drop');
                $this->assertSame(['url_hash', 'source_type', 'source_pk'], $this->keyColumns($offsite, 'uniq_hit'));

                $wpdb->query("ALTER TABLE {$offsite} DROP INDEX uniq_hit, ADD UNIQUE KEY uniq_hit (url_hash,source_type,source_id)");
                $drops = [];

                UAMS_MRA_Store::install();
                $this->assertCount(1, $drops, 'a stale key is dropped once');
            } finally {
                remove_filter('query', $record);
            }
        });
    }

    public function test_reset_empties_every_working_table_and_forgets_the_cached_summary(): void
    {
        global $wpdb;

        $wpdb->insert(UAMS_MRA_Store::index_table(), ['attachment_id' => 1]);
        $this->result(1, ['bytes' => 5]);
        UAMS_MRA_Store::add_refs([['attachment_id' => 1, 'source_type' => 'content', 'source_id' => 2, 'source_label' => 'x']]);
        UAMS_MRA_Store::add_offsite([$this->offsite('https://old.example.edu/a.jpg')]);

        $this->assertSame(1, UAMS_MRA_Store::summary()['total']);

        UAMS_MRA_Store::reset();

        $this->assertSame(0, $this->rowCount(UAMS_MRA_Store::index_table()));
        $this->assertSame(0, $this->rowCount(UAMS_MRA_Store::refs_table()));
        $this->assertSame(0, $this->rowCount(UAMS_MRA_Store::results_table()));
        $this->assertSame(0, $this->rowCount(UAMS_MRA_Store::offsite_table()));
        $this->assertSame(0, UAMS_MRA_Store::summary()['total']);
    }

    public function test_references_are_stored_with_their_defaults_and_duplicates_are_ignored(): void
    {
        UAMS_MRA_Store::add_refs([
            ['attachment_id' => '4', 'source_type' => 'content', 'source_id' => '9', 'source_label' => 'Page'],
            ['attachment_id' => 4, 'source_type' => 'content', 'source_id' => 9, 'source_label' => 'Same again'],
            ['attachment_id' => 4, 'source_type' => 'meta', 'source_id' => 9, 'source_label' => 'Meta', 'site_id' => 3, 'confidence' => 2],
        ]);

        $refs = UAMS_MRA_Store::get_refs(4);

        $this->assertCount(2, $refs);
        $this->assertSame('meta', $refs[0]->source_type);
        $this->assertSame('3', $refs[0]->site_id);
        $this->assertSame('2', $refs[0]->confidence);
        $this->assertSame('content', $refs[1]->source_type);
        $this->assertSame('0', $refs[1]->site_id);
        $this->assertSame('1', $refs[1]->confidence);
        $this->assertSame('Page', $refs[1]->source_label);
    }

    public function test_the_same_reference_from_two_sites_is_kept_twice(): void
    {
        UAMS_MRA_Store::add_refs([
            ['attachment_id' => 4, 'source_type' => 'content', 'source_id' => 9, 'source_label' => 'a', 'site_id' => 1],
            ['attachment_id' => 4, 'source_type' => 'content', 'source_id' => 9, 'source_label' => 'b', 'site_id' => 2],
        ]);

        $this->assertSame(2, $this->rowCount(UAMS_MRA_Store::refs_table()));
    }

    public function test_adding_no_references_changes_nothing(): void
    {
        UAMS_MRA_Store::add_refs([]);

        $this->assertSame(0, $this->rowCount(UAMS_MRA_Store::refs_table()));
    }

    public function test_a_reference_label_is_cut_to_250_characters_not_bytes(): void
    {
        UAMS_MRA_Store::add_refs([
            ['attachment_id' => 1, 'source_type' => 'a', 'source_id' => 1, 'source_label' => str_repeat('é', 250)],
            ['attachment_id' => 1, 'source_type' => 'b', 'source_id' => 1, 'source_label' => str_repeat('é', 251)],
        ]);

        $labels = array_map(static fn (object $ref): string => $ref->source_label, UAMS_MRA_Store::get_refs(1));

        $this->assertSame([str_repeat('é', 250), str_repeat('é', 250)], $labels);
    }

    public function test_references_in_batches_larger_than_500_are_all_stored(): void
    {
        $rows = [];

        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = ['attachment_id' => $i, 'source_type' => 'content', 'source_id' => $i, 'source_label' => 'p'];
        }

        UAMS_MRA_Store::add_refs(array_slice($rows, 0, 500));
        $this->assertSame(500, $this->rowCount(UAMS_MRA_Store::refs_table()));

        UAMS_MRA_Store::reset();
        UAMS_MRA_Store::add_refs(array_slice($rows, 0, 501));
        $this->assertSame(501, $this->rowCount(UAMS_MRA_Store::refs_table()));

        UAMS_MRA_Store::reset();
        UAMS_MRA_Store::add_refs($rows);
        $this->assertSame(1001, $this->rowCount(UAMS_MRA_Store::refs_table()));
    }

    public function test_references_come_back_strongest_first_then_oldest_and_only_for_that_file(): void
    {
        UAMS_MRA_Store::add_refs([
            ['attachment_id' => 1, 'source_type' => 'weak-first', 'source_id' => 1, 'source_label' => '', 'confidence' => 1],
            ['attachment_id' => 1, 'source_type' => 'strong-first', 'source_id' => 2, 'source_label' => '', 'confidence' => 2],
            ['attachment_id' => 1, 'source_type' => 'strong-second', 'source_id' => 3, 'source_label' => '', 'confidence' => 2],
            ['attachment_id' => 2, 'source_type' => 'other', 'source_id' => 4, 'source_label' => '', 'confidence' => 2],
        ]);

        $types = array_map(static fn (object $ref): string => $ref->source_type, UAMS_MRA_Store::get_refs(1));

        $this->assertSame(['strong-first', 'strong-second', 'weak-first'], $types);
    }

    public function test_references_are_limited_to_twenty_unless_asked_for_more_or_fewer(): void
    {
        $rows = [];

        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['attachment_id' => 1, 'source_type' => 'content', 'source_id' => $i, 'source_label' => ''];
        }

        UAMS_MRA_Store::add_refs($rows);

        $this->assertCount(20, UAMS_MRA_Store::get_refs(1));
        $this->assertCount(3, UAMS_MRA_Store::get_refs(1, 3));
        $this->assertCount(25, UAMS_MRA_Store::get_refs(1, 100));
        $this->assertSame([], UAMS_MRA_Store::get_refs(99));
    }

    public function test_recounting_folds_the_references_into_the_result_rows_it_names(): void
    {
        $this->withRealTables(function (): void {
            $this->result(1);
            $this->result(2);
            $this->result(3, ['ref_count' => 7, 'ref_count_strict' => 7]);

            UAMS_MRA_Store::add_refs([
                ['attachment_id' => 1, 'source_type' => 'a', 'source_id' => 1, 'source_label' => '', 'confidence' => 1],
                ['attachment_id' => 1, 'source_type' => 'b', 'source_id' => 1, 'source_label' => '', 'confidence' => 2],
                ['attachment_id' => 1, 'source_type' => 'c', 'source_id' => 1, 'source_label' => '', 'confidence' => 3],
                ['attachment_id' => 2, 'source_type' => 'a', 'source_id' => 1, 'source_label' => '', 'confidence' => 1],
            ]);

            UAMS_MRA_Store::recount_refs([1, '2', 0, 1, 99]);

            $this->assertSame(['3', '2'], [$this->resultRow(1)['ref_count'], $this->resultRow(1)['ref_count_strict']]);
            $this->assertSame(['1', '0'], [$this->resultRow(2)['ref_count'], $this->resultRow(2)['ref_count_strict']]);
            $this->assertSame(['7', '7'], [$this->resultRow(3)['ref_count'], $this->resultRow(3)['ref_count_strict']]);
        });
    }

    public function test_recounting_brings_a_count_back_down_when_references_are_gone(): void
    {
        $this->withRealTables(function (): void {
            $this->result(1, ['ref_count' => 4, 'ref_count_strict' => 4]);

            UAMS_MRA_Store::recount_refs([1]);

            $this->assertSame(['0', '0'], [$this->resultRow(1)['ref_count'], $this->resultRow(1)['ref_count_strict']]);
        });
    }

    public function test_recounting_nothing_touches_nothing(): void
    {
        $this->withRealTables(function (): void {
            $this->result(1, ['ref_count' => 4]);

            UAMS_MRA_Store::recount_refs([]);
            UAMS_MRA_Store::recount_refs([0, '']);

            $this->assertSame('4', $this->resultRow(1)['ref_count']);
        });
    }

    public function test_recounting_more_than_one_chunk_reaches_every_file(): void
    {
        $this->withRealTables(function (): void {
            global $wpdb;

            $ids = range(1, 450);
            $table = UAMS_MRA_Store::results_table();

            $wpdb->query("INSERT INTO {$table} (attachment_id, ref_count) VALUES ".implode(',', array_map(static fn (int $id): string => "({$id}, 9)", $ids)));

            UAMS_MRA_Store::recount_refs($ids);

            $this->assertSame(0, $this->rowCount($table, 'ref_count <> 0'));
        });
    }

    public function test_a_render_state_is_recorded_with_its_url_note_and_time_for_the_files_named(): void
    {
        $this->result(1);
        $this->result(2);

        UAMS_MRA_Store::set_render_state([1, '1', 0], 'offsite', 'https://x.example/p', 'old.example.edu');

        $one = $this->resultRow(1);
        $two = $this->resultRow(2);

        $this->assertSame('offsite', $one['render_state']);
        $this->assertSame('https://x.example/p', $one['render_url']);
        $this->assertSame('old.example.edu', $one['render_note']);
        $this->assertLessThan(120, abs(strtotime($one['render_checked_at']) - strtotime(current_time('mysql'))));
        $this->assertSame('', $two['render_state']);
        $this->assertSame('0000-00-00 00:00:00', $two['render_checked_at']);
    }

    public function test_a_render_state_url_and_note_default_to_empty_and_are_cut_to_250_characters(): void
    {
        $this->result(1);
        $this->result(2);
        $this->result(3);

        UAMS_MRA_Store::set_render_state([1], 'found');
        UAMS_MRA_Store::set_render_state([2], 'error', str_repeat('é', 250), str_repeat('é', 250));
        UAMS_MRA_Store::set_render_state([3], 'error', str_repeat('é', 251), str_repeat('é', 251));

        $this->assertSame(['', ''], [$this->resultRow(1)['render_url'], $this->resultRow(1)['render_note']]);
        $this->assertSame([str_repeat('é', 250), str_repeat('é', 250)], [$this->resultRow(2)['render_url'], $this->resultRow(2)['render_note']]);
        $this->assertSame([str_repeat('é', 250), str_repeat('é', 250)], [$this->resultRow(3)['render_url'], $this->resultRow(3)['render_note']]);
    }

    public function test_recording_a_render_state_for_no_files_changes_nothing(): void
    {
        $this->result(1);

        UAMS_MRA_Store::set_render_state([], 'found');
        UAMS_MRA_Store::set_render_state([0], 'found');

        $this->assertSame('', $this->resultRow(1)['render_state']);
    }

    public function test_a_render_state_reaches_every_file_across_chunks(): void
    {
        global $wpdb;

        $ids = range(1, 450);
        $table = UAMS_MRA_Store::results_table();

        $wpdb->query("INSERT INTO {$table} (attachment_id) VALUES ".implode(',', array_map(static fn (int $id): string => "({$id})", $ids)));

        UAMS_MRA_Store::set_render_state($ids, 'absent');

        $this->assertSame(450, $this->rowCount($table, "render_state = 'absent'"));
    }

    public function test_render_counts_group_the_states_and_leave_out_files_not_yet_read(): void
    {
        $this->result(1, ['render_state' => 'found']);
        $this->result(2, ['render_state' => 'found']);
        $this->result(3, ['render_state' => 'absent']);
        $this->result(4);

        $counts = UAMS_MRA_Store::render_counts();
        ksort($counts);

        $this->assertSame(['absent' => 1, 'found' => 2], $counts);
    }

    public function test_render_counts_are_empty_when_nothing_was_read(): void
    {
        $this->assertSame([], UAMS_MRA_Store::render_counts());
    }

    public function test_an_offsite_hit_is_stored_with_a_hash_and_the_row_it_sits_in(): void
    {
        global $wpdb;

        $url = 'https://old.example.edu/wp-content/uploads/a.jpg';

        UAMS_MRA_Store::add_offsite([
            $this->offsite($url, ['source_id' => 5]),
            $this->offsite($url, ['source_type' => 'meta', 'source_id' => 5, 'source_pk' => 77, 'source_key' => 'hero']),
        ]);

        $rows = $wpdb->get_results('SELECT * FROM '.UAMS_MRA_Store::offsite_table().' ORDER BY offsite_id', ARRAY_A);

        $this->assertCount(2, $rows);
        $this->assertSame($url, $rows[0]['url']);
        $this->assertSame(md5($url), $rows[0]['url_hash']);
        $this->assertSame('old.example.edu', $rows[0]['host']);
        $this->assertSame('a.jpg', $rows[0]['base_norm']);
        $this->assertSame('content', $rows[0]['source_type']);
        $this->assertSame('5', $rows[0]['source_id']);
        $this->assertSame('5', $rows[0]['source_pk']);
        $this->assertSame('', $rows[0]['source_key']);
        $this->assertSame('A page', $rows[0]['source_label']);
        $this->assertSame('77', $rows[1]['source_pk']);
        $this->assertSame('hero', $rows[1]['source_key']);
    }

    public function test_the_same_url_in_the_same_row_is_stored_once_and_in_different_rows_of_one_post_each_time(): void
    {
        $url = 'https://old.example.edu/a.jpg';

        UAMS_MRA_Store::add_offsite([
            $this->offsite($url, ['source_type' => 'meta', 'source_id' => 5, 'source_pk' => 1]),
            $this->offsite($url, ['source_type' => 'meta', 'source_id' => 5, 'source_pk' => 1]),
            $this->offsite($url, ['source_type' => 'meta', 'source_id' => 5, 'source_pk' => 2]),
            $this->offsite($url, ['source_type' => 'content', 'source_id' => 5, 'source_pk' => 1]),
        ]);

        $this->assertSame(3, $this->rowCount(UAMS_MRA_Store::offsite_table()));
    }

    public function test_offsite_text_is_cut_at_its_column_limits_in_characters(): void
    {
        global $wpdb;

        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://x.example/'.str_repeat('é', 600), [
                'host' => str_repeat('é', 200),
                'base_norm' => str_repeat('é', 200),
                'source_key' => str_repeat('é', 200),
                'source_label' => str_repeat('é', 300),
            ]),
        ]);

        $row = $wpdb->get_row('SELECT * FROM '.UAMS_MRA_Store::offsite_table(), ARRAY_A);

        $this->assertSame(500, mb_strlen($row['url']));
        $this->assertSame(md5($row['url']), $row['url_hash']);
        $this->assertSame(191, mb_strlen($row['host']));
        $this->assertSame(191, mb_strlen($row['base_norm']));
        $this->assertSame(191, mb_strlen($row['source_key']));
        $this->assertSame(250, mb_strlen($row['source_label']));
    }

    public function test_offsite_text_at_its_limit_is_kept_whole(): void
    {
        global $wpdb;

        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://x.example/'.str_repeat('a', 482), [
                'host' => str_repeat('h', 191),
                'base_norm' => str_repeat('b', 191),
                'source_key' => str_repeat('k', 191),
                'source_label' => str_repeat('l', 250),
            ]),
        ]);

        $row = $wpdb->get_row('SELECT * FROM '.UAMS_MRA_Store::offsite_table(), ARRAY_A);

        $this->assertSame([500, 191, 191, 191, 250], [strlen($row['url']), strlen($row['host']), strlen($row['base_norm']), strlen($row['source_key']), strlen($row['source_label'])]);
    }

    public function test_adding_no_offsite_hits_changes_nothing(): void
    {
        UAMS_MRA_Store::add_offsite([]);

        $this->assertSame(0, $this->rowCount(UAMS_MRA_Store::offsite_table()));
    }

    public function test_offsite_hits_in_batches_larger_than_500_are_all_stored(): void
    {
        $rows = [];

        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = $this->offsite("https://old.example.edu/{$i}.jpg", ['source_id' => $i]);
        }

        UAMS_MRA_Store::add_offsite(array_slice($rows, 0, 500));
        $this->assertSame(500, $this->rowCount(UAMS_MRA_Store::offsite_table()));

        UAMS_MRA_Store::reset();
        UAMS_MRA_Store::add_offsite(array_slice($rows, 0, 501));
        $this->assertSame(501, $this->rowCount(UAMS_MRA_Store::offsite_table()));

        UAMS_MRA_Store::reset();
        UAMS_MRA_Store::add_offsite($rows);
        $this->assertSame(1001, $this->rowCount(UAMS_MRA_Store::offsite_table()));
    }

    public function test_a_report_from_before_the_row_was_recorded_needs_a_rescan(): void
    {
        $this->assertFalse(UAMS_MRA_Store::offsite_needs_rescan());

        UAMS_MRA_Store::add_offsite([$this->offsite('https://old.example.edu/a.jpg', ['source_id' => 5])]);
        $this->assertFalse(UAMS_MRA_Store::offsite_needs_rescan());

        UAMS_MRA_Store::add_offsite([$this->offsite('https://old.example.edu/b.jpg', ['source_id' => 0])]);
        $this->assertTrue(UAMS_MRA_Store::offsite_needs_rescan());
    }

    public function test_the_offsite_summary_counts_hits_files_hosts_and_files_with_a_local_copy(): void
    {
        global $wpdb;

        $this->assertSame(['hits' => 0, 'files' => 0, 'hosts' => 0, 'with_local' => 0], UAMS_MRA_Store::offsite_summary());

        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://old.example.edu/a.jpg', ['base_norm' => 'a.jpg', 'source_id' => 1]),
            $this->offsite('https://old.example.edu/a.jpg', ['base_norm' => 'a.jpg', 'source_id' => 2]),
            $this->offsite('https://cdn.example.net/b.jpg', ['base_norm' => 'b.jpg', 'host' => 'cdn.example.net', 'source_id' => 3]),
        ]);

        $wpdb->insert(UAMS_MRA_Store::index_table(), ['attachment_id' => 10, 'base_norm' => 'a.jpg']);
        $wpdb->insert(UAMS_MRA_Store::index_table(), ['attachment_id' => 11, 'base_norm' => 'a.jpg']);

        $this->assertSame(['hits' => 3, 'files' => 2, 'hosts' => 2, 'with_local' => 1], UAMS_MRA_Store::offsite_summary());
    }

    public function test_offsite_hosts_come_back_most_references_first(): void
    {
        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://cdn.example.net/a.jpg', ['host' => 'cdn.example.net', 'source_id' => 1]),
            $this->offsite('https://old.example.edu/a.jpg', ['source_id' => 1]),
            $this->offsite('https://old.example.edu/b.jpg', ['source_id' => 2]),
            $this->offsite('https://old.example.edu/b.jpg', ['source_id' => 3]),
        ]);

        $this->assertSame(
            [
                ['host' => 'old.example.edu', 'hits' => '3', 'files' => '2'],
                ['host' => 'cdn.example.net', 'hits' => '1', 'files' => '1'],
            ],
            UAMS_MRA_Store::offsite_hosts()
        );
    }

    public function test_offsite_files_list_each_file_once_with_its_hits_types_and_local_copies(): void
    {
        global $wpdb;

        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://old.example.edu/a.jpg', ['base_norm' => 'a.jpg', 'source_type' => 'meta', 'source_id' => 1]),
            $this->offsite('https://old.example.edu/a.jpg', ['base_norm' => 'a.jpg', 'source_type' => 'content', 'source_id' => 2]),
            $this->offsite('https://old.example.edu/b.jpg', ['base_norm' => 'b.jpg', 'source_id' => 3]),
            $this->offsite('https://cdn.example.net/c.jpg', ['base_norm' => 'c.jpg', 'host' => 'cdn.example.net', 'source_id' => 4]),
        ]);

        $wpdb->insert(UAMS_MRA_Store::index_table(), ['attachment_id' => 12, 'base_norm' => 'a.jpg']);
        $wpdb->insert(UAMS_MRA_Store::index_table(), ['attachment_id' => 10, 'base_norm' => 'a.jpg']);

        $files = UAMS_MRA_Store::offsite_files();

        $this->assertSame(['https://old.example.edu/a.jpg', 'https://cdn.example.net/c.jpg', 'https://old.example.edu/b.jpg'], array_column($files, 'url'));
        $this->assertSame('2', $files[0]['hits']);
        $this->assertSame('content, meta', $files[0]['source_types']);
        $this->assertSame('10,12', $files[0]['local_ids']);
        $this->assertSame(md5('https://old.example.edu/a.jpg'), $files[0]['url_hash']);
        $this->assertNull($files[1]['local_ids']);
    }

    public function test_offsite_files_can_be_limited_to_one_host_and_paged(): void
    {
        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://old.example.edu/a.jpg', ['source_id' => 1]),
            $this->offsite('https://old.example.edu/b.jpg', ['source_id' => 2]),
            $this->offsite('https://old.example.edu/c.jpg', ['source_id' => 3]),
            $this->offsite('https://cdn.example.net/d.jpg', ['host' => 'cdn.example.net', 'source_id' => 4]),
        ]);

        $this->assertSame(
            ['https://old.example.edu/a.jpg', 'https://old.example.edu/b.jpg', 'https://old.example.edu/c.jpg'],
            array_column(UAMS_MRA_Store::offsite_files('old.example.edu'), 'url')
        );
        $this->assertSame(['https://old.example.edu/b.jpg'], array_column(UAMS_MRA_Store::offsite_files('old.example.edu', 1, 1), 'url'));
        $this->assertSame(['https://old.example.edu/c.jpg'], array_column(UAMS_MRA_Store::offsite_files('old.example.edu', self::loose('5'), self::loose('2')), 'url'));
        $this->assertCount(4, UAMS_MRA_Store::offsite_files());
        $this->assertCount(2, UAMS_MRA_Store::offsite_files('', 2));
        $this->assertSame([], UAMS_MRA_Store::offsite_files('nowhere.example'));
    }

    public function test_offsite_files_default_to_a_hundred_a_page(): void
    {
        $rows = [];

        for ($i = 1; $i <= 101; $i++) {
            $rows[] = $this->offsite("https://old.example.edu/{$i}.jpg", ['source_id' => $i]);
        }

        UAMS_MRA_Store::add_offsite($rows);

        $this->assertCount(100, UAMS_MRA_Store::offsite_files());
        $this->assertCount(101, UAMS_MRA_Store::offsite_files('', 200));
    }

    public function test_the_offsite_file_count_follows_the_host(): void
    {
        UAMS_MRA_Store::add_offsite([
            $this->offsite('https://old.example.edu/a.jpg', ['source_id' => 1]),
            $this->offsite('https://old.example.edu/a.jpg', ['source_id' => 2]),
            $this->offsite('https://old.example.edu/b.jpg', ['source_id' => 3]),
            $this->offsite('https://cdn.example.net/c.jpg', ['host' => 'cdn.example.net', 'source_id' => 4]),
        ]);

        $this->assertSame(3, UAMS_MRA_Store::offsite_file_count());
        $this->assertSame(2, UAMS_MRA_Store::offsite_file_count('old.example.edu'));
        $this->assertSame(0, UAMS_MRA_Store::offsite_file_count('nowhere.example'));
    }

    public function test_the_sources_of_an_offsite_file_come_back_in_order_and_limited(): void
    {
        $url = 'https://old.example.edu/a.jpg';
        $rows = [];

        for ($i = 30; $i >= 1; $i--) {
            $rows[] = $this->offsite($url, ['source_type' => 'meta', 'source_id' => $i, 'source_label' => "m{$i}"]);
        }

        $rows[] = $this->offsite($url, ['source_type' => 'content', 'source_id' => 99, 'source_label' => 'late']);
        $rows[] = $this->offsite('https://old.example.edu/other.jpg', ['source_id' => 1]);

        UAMS_MRA_Store::add_offsite($rows);

        $sources = UAMS_MRA_Store::offsite_sources(md5($url));

        $this->assertCount(25, $sources);
        $this->assertSame(['source_type' => 'content', 'source_id' => '99', 'source_label' => 'late'], $sources[0]);
        $this->assertSame('1', $sources[1]['source_id']);
        $this->assertSame('24', $sources[24]['source_id']);
        $this->assertCount(2, UAMS_MRA_Store::offsite_sources(md5($url), 2));
        $this->assertSame([], UAMS_MRA_Store::offsite_sources(md5('unknown')));
    }

    public function test_the_summary_aggregates_the_results_and_is_kept_for_the_request(): void
    {
        $this->result(1, ['bytes' => 100, 'ref_count' => 0, 'is_attached' => 0]);
        $this->result(2, ['bytes' => 50, 'ref_count' => 0, 'is_attached' => 1]);
        $this->result(3, ['bytes' => 20, 'ref_count' => 2, 'ref_count_strict' => 0, 'is_attached' => 0]);
        $this->result(4, ['bytes' => 5, 'ref_count' => 2, 'ref_count_strict' => 1, 'is_attached' => 0]);
        $this->result(6, ['bytes' => 4, 'ref_count' => 3, 'ref_count_strict' => 2, 'is_attached' => 1]);

        $expected = [
            'total' => 5,
            'total_bytes' => 179,
            'unattached' => 3,
            'unreferenced' => 2,
            'unreferenced_bytes' => 150,
            'orphaned' => 1,
            'orphaned_bytes' => 100,
            'weak_only' => 1,
        ];

        $this->assertSame($expected, UAMS_MRA_Store::summary());

        $this->result(5, ['bytes' => 1]);

        $this->assertSame($expected, UAMS_MRA_Store::summary(), 'the second read is served from the cache');
    }

    public function test_the_summary_of_nothing_is_all_zeros(): void
    {
        $this->assertSame(
            ['total' => 0, 'total_bytes' => 0, 'unattached' => 0, 'unreferenced' => 0, 'unreferenced_bytes' => 0, 'orphaned' => 0, 'orphaned_bytes' => 0, 'weak_only' => 0],
            UAMS_MRA_Store::summary()
        );
    }
}
