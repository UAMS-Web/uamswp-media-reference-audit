<?php

declare(strict_types=1);

/**
 * Attachment lookup index and URL resolution.
 */
defined('ABSPATH') || exit;

/**
 * Builds and queries the filename index.
 */
class UAMS_MRA_Index
{
    /**
     * Normalize a file path so that every generated size collapses onto the original.
     *
     * Strips query strings, size suffixes (-1024x768), -scaled, -rotated, and the
     * -e1234567890 suffix WordPress adds to edited images.
     *
     * @param  string  $file  Relative or absolute path or URL fragment.
     * @return array {path_norm, base_norm}
     */
    public static function normalize($file): array
    {
        $file = (string) $file;
        $file = preg_replace('#[?\#].*$#', '', $file);
        $file = str_replace('\\/', '/', $file);
        $file = rawurldecode($file);
        $file = strtolower(trim($file, "/ \t\n\r\0\x0B"));

        if ($file === '') {
            return ['', ''];
        }

        $dir = ltrim(str_replace('\\', '/', dirname($file)), '/');
        $base = basename($file);
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $name = pathinfo($base, PATHINFO_FILENAME);

        $name = preg_replace('#-\d{1,5}x\d{1,5}$#', '', $name);
        $name = preg_replace('#-scaled$#', '', $name);
        $name = preg_replace('#-rotated$#', '', $name);
        $name = preg_replace('#-e\d{10,}$#', '', $name);

        $base_norm = $ext !== '' ? $name.'.'.$ext : $name;

        // Keep only the trailing YYYY/MM (or custom folder) segments so that
        // year/month folders match whether or not the URL carried a full path.
        $parts = array_values(
            array_filter(
                explode('/', $dir),
                static fn ($segment): bool => ! in_array($segment, ['', '.', '..'], true)
            )
        );
        $tail = array_slice($parts, -2);
        $path_norm = ($tail !== [] ? implode('/', $tail).'/' : '').$base_norm;

        return [
            mb_substr($path_norm, 0, 255),
            mb_substr($base_norm, 0, 191),
        ];
    }

    /**
     * Index one batch of attachments.
     *
     * @param  int  $after  Last attachment ID processed.
     * @param  int  $limit  Batch size.
     * @return array {count, last_id}
     */
    public static function build_batch($after, $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_value AS file
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment' AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d",
                $after,
                $limit
            )
        );

        if (empty($rows)) {
            return [0, $after];
        }

        $values = [];
        $args = [];
        $last_id = $after;

        foreach ($rows as $row) {
            $last_id = (int) $row->ID;
            $file = $row->file;
            if (! $file) {
                $file = (string) get_post_meta($row->ID, '_wp_attached_file', true);
            }

            [$path_norm, $base_norm] = self::normalize($file);

            $values[] = '(%d,%s,%s)';
            $args[] = (int) $row->ID;
            $args[] = $path_norm;
            $args[] = $base_norm;
        }

        $sql = 'REPLACE INTO '.UAMS_MRA_Store::index_table().' (attachment_id, path_norm, base_norm) VALUES '.implode(',', $values);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare($sql, $args));

        return [count($rows), $last_id];
    }

    /**
     * Resolve a batch of candidate file paths to attachment IDs.
     *
     * Exact path match wins. When only the basename matches and several
     * attachments share it, every candidate is returned, because marking an
     * extra file as referenced is far safer than missing a live one.
     *
     * @param  array  $candidates  Raw path or URL fragments.
     * @return array Attachment IDs.
     */
    public static function resolve_paths(array $candidates): array
    {
        global $wpdb;

        if ($candidates === []) {
            return [];
        }

        $bases = [];

        foreach ($candidates as $candidate) {
            [, $base_norm] = self::normalize($candidate);
            if ($base_norm !== '') {
                $bases[$base_norm] = true;
            }
        }

        if ($bases === []) {
            return [];
        }

        $ids = [];

        // Chunk to keep the IN() lists inside packet limits.
        foreach (array_chunk(array_keys($bases), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $sql = 'SELECT attachment_id FROM '.UAMS_MRA_Store::index_table()." WHERE base_norm IN ({$placeholders})";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ($wpdb->get_col($wpdb->prepare($sql, $chunk)) as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Filter a list of integers down to those that are real attachment IDs.
     *
     * @param  array  $ids  Candidate IDs.
     */
    public static function filter_attachment_ids(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = 'SELECT attachment_id FROM '.UAMS_MRA_Store::index_table()." WHERE attachment_id IN ({$placeholders})";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_col($wpdb->prepare($sql, $chunk));
            foreach ($found as $id) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }
}
