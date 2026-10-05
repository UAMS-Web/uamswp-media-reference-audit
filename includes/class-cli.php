<?php

declare(strict_types=1);

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\make_progress_bar;

/**
 * WP-CLI commands.
 */
defined('ABSPATH') || exit;

/**
 * Audit media references from the command line.
 */
class UAMS_MRA_CLI
{
    /**
     * Run a full scan on the current site.
     *
     * ## OPTIONS
     *
     * [--quiet]
     * : Hide the progress bar.
     *
     * ## EXAMPLES
     *
     *     wp uams-media-audit scan
     *     wp uams-media-audit scan --url=cancer.uams.edu
     *
     * @param  array  $args  Positional args.
     * @param  array  $assoc_args  Flags.
     */
    public function scan($args, $assoc_args): void
    {
        UAMS_MRA_Store::install();

        $state = UAMS_MRA_Scanner::start();
        $quiet = isset($assoc_args['quiet']);

        $last = '';

        while ($state['step'] !== 'done') {
            $state = UAMS_MRA_Scanner::step();

            if (! $quiet && $state['message'] !== $last) {
                WP_CLI::log($state['message']);
                $last = $state['message'];
            }
        }

        $summary = UAMS_MRA_Store::summary();

        WP_CLI::success(
            sprintf(
                '%d media files. %d unattached, %d unreferenced, %d both (%s reclaimable).',
                $summary['total'],
                $summary['unattached'],
                $summary['unreferenced'],
                $summary['orphaned'],
                size_format($summary['orphaned_bytes'], 1)
            )
        );
    }

    /**
     * List scan results.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Which slice to show.
     * ---
     * default: orphaned
     * options:
     *   - all
     *   - orphaned
     *   - unreferenced
     *   - unattached
     *   - weak
     *   - referenced
     * ---
     *
     * [--limit=<limit>]
     * : Maximum rows.
     * ---
     * default: 100
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - ids
     * ---
     *
     * @param  array  $args  Positional args.
     * @param  array  $assoc_args  Flags.
     */
    public function list($args, $assoc_args): void
    {
        global $wpdb;

        $status = $assoc_args['status'] ?? 'orphaned';
        $limit = (int) ($assoc_args['limit'] ?? 100);
        $format = $assoc_args['format'] ?? 'table';
        $where = self::where_for($status);
        $table = UAMS_MRA_Store::results_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY bytes DESC LIMIT %d", $limit),
            ARRAY_A
        );

        if (empty($rows)) {
            WP_CLI::success('Nothing matched that filter.');

            return;
        }

        if ($format === 'ids') {
            WP_CLI::line(implode(' ', wp_list_pluck($rows, 'attachment_id')));

            return;
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => $row['attachment_id'],
                'file' => $row['file'],
                'size' => size_format((int) $row['bytes'], 1),
                'attached' => $row['parent_id'] ?: '',
                'refs' => $row['ref_count'],
                'strong' => $row['ref_count_strict'],
                'uploaded' => substr($row['post_date'], 0, 10),
            ];
        }

        format_items($format, $out, ['id', 'file', 'size', 'attached', 'refs', 'strong', 'uploaded']);
    }

    /**
     * Delete media that the last scan found no references for.
     *
     * Always run scan first, and always take a backup first.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Which slice to delete.
     * ---
     * default: orphaned
     * options:
     *   - orphaned
     *   - unreferenced
     * ---
     *
     * [--dry-run]
     * : Report what would be deleted and stop.
     *
     * [--limit=<limit>]
     * : Maximum files to delete in this run.
     * ---
     * default: 500
     * ---
     *
     * @param  array  $args  Positional args.
     * @param  array  $assoc_args  Flags.
     */
    public function delete($args, $assoc_args): void
    {
        global $wpdb;

        $status = $assoc_args['status'] ?? 'orphaned';
        $limit = (int) ($assoc_args['limit'] ?? 500);
        $dry_run = isset($assoc_args['dry-run']);
        $where = self::where_for($status);
        $table = UAMS_MRA_Store::results_table();

        if ($status !== 'orphaned' && $status !== 'unreferenced') {
            WP_CLI::error('Refusing to delete anything outside the orphaned or unreferenced sets.');
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT attachment_id, bytes, file FROM {$table} WHERE {$where} AND ref_count = 0 ORDER BY bytes DESC LIMIT %d", $limit),
            ARRAY_A
        );

        if (empty($rows)) {
            WP_CLI::success('Nothing to delete.');

            return;
        }

        $bytes = array_sum(wp_list_pluck($rows, 'bytes'));

        if ($dry_run) {
            foreach ($rows as $row) {
                WP_CLI::log($row['attachment_id']."\t".$row['file']."\t".size_format((int) $row['bytes'], 1));
            }

            WP_CLI::success(sprintf('Dry run. %d files, %s would be freed.', count($rows), size_format($bytes, 1)));

            return;
        }

        WP_CLI::confirm(sprintf('Permanently delete %d files and free %s?', count($rows), size_format($bytes, 1)), $assoc_args);

        $deleted = 0;
        $progress = make_progress_bar('Deleting', count($rows));

        foreach ($rows as $row) {
            if (wp_delete_attachment((int) $row['attachment_id'], true)) {
                $deleted++;
                $wpdb->delete($table, ['attachment_id' => (int) $row['attachment_id']], ['%d']);
            }

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::success(sprintf('Deleted %d files, freed %s.', $deleted, size_format($bytes, 1)));
    }

    /**
     * Show why a single attachment is considered in use.
     *
     * ## OPTIONS
     *
     * <id>
     * : Attachment ID.
     *
     * @param  array  $args  Positional args.
     */
    public function why($args): void
    {
        $id = (int) $args[0];
        $refs = UAMS_MRA_Store::get_refs($id, 100);

        if (empty($refs)) {
            WP_CLI::warning(sprintf('No references recorded for attachment %d.', $id));

            return;
        }

        $out = [];
        foreach ($refs as $ref) {
            $out[] = [
                'type' => $ref->source_type,
                'source' => $ref->source_id,
                'label' => $ref->source_label,
                'confidence' => (int) $ref->confidence >= 2 ? 'strong' : 'by ID',
                'site' => $ref->site_id ?: '',
            ];
        }

        format_items('table', $out, ['type', 'source', 'label', 'confidence', 'site']);
    }

    /**
     * Map a status keyword to a WHERE fragment.
     *
     * @param  string  $status  Status keyword.
     */
    protected static function where_for($status): string
    {
        $map = [
            'all' => '1=1',
            'orphaned' => 'ref_count = 0 AND is_attached = 0',
            'unreferenced' => 'ref_count = 0',
            'unattached' => 'is_attached = 0',
            'weak' => 'ref_count > 0 AND ref_count_strict = 0',
            'referenced' => 'ref_count > 0',
        ];

        return $map[$status] ?? $map['orphaned'];
    }
}
