<?php

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
     * -e1234567890 suffix WordPress adds to edited images, in whatever order they
     * are stacked. Backslashes are read as separators on every platform.
     *
     * @param  mixed  $file  Relative or absolute path or URL fragment; any other value is cast to a string.
     * @return array {path_norm, base_norm}
     */
    public static function normalize($file): array
    {
        $file = (string) $file;
        $file = preg_replace('#[?\#].*$#', '', $file);
        $file = rawurldecode($file);

        // One separator, whatever the source wrote: dirname() and basename() read a
        // backslash as one only on Windows, so left alone the same value would split
        // differently there. This also covers the slash-escaped `\/` of block JSON
        // and a `%5C` that decoding just produced.
        $file = str_replace('\\', '/', $file);
        $file = strtolower(trim($file, "/ \t\n\r\0\x0B"));

        // Equivalent: a leading `/` only adds an empty first segment, which the segment filter drops below.
        // @pest-mutate-ignore:UnwrapLtrim
        $dir = ltrim(dirname($file), '/');
        $base = basename($file);
        // Equivalent: `$file` was lower-cased above, so its extension already is.
        // @pest-mutate-ignore:UnwrapStrtolower
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $name = pathinfo($base, PATHINFO_FILENAME);

        // Repeat until nothing more comes off: WordPress stacks suffixes in either
        // order (an edit stamp after -scaled, a size after an edit stamp), and one
        // pass in a fixed order leaves whatever the last pattern uncovered.
        $patterns = self::derivative_patterns();

        do {
            $before = $name;

            foreach ($patterns as $pattern) {
                // Equivalent: preg_replace() returns null only when a filtered pattern
                // fails to run, and null reaches the next call as an empty string either
                // way. The cast is for the analyzer, which sees string|null.
                // @pest-mutate-ignore:RemoveStringCast
                $name = (string) preg_replace($pattern, '', $name);
            }
        } while ($name !== $before);

        $base_norm = $ext !== '' ? $name.'.'.$ext : $name;

        // Keep only the trailing YYYY/MM (or custom folder) segments so that
        // year/month folders match whether or not the URL carried a full path.
        // Equivalent: array_slice() renumbers a list, so the keys array_filter() leaves gaps in never reach the output.
        // @pest-mutate-ignore:UnwrapArrayValues
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
     * Suffixes WordPress and themes append when they generate a derivative.
     *
     * Applied in order, so a size suffix comes off before -scaled underneath it.
     *
     * The size pattern allows a short trailing crop marker -- hero-768x432-cc.jpg
     * -- because themes that build their own derivatives put one there. Without
     * it that URL resolves to a filename no attachment has, and a file the page
     * is visibly displaying reports as unreferenced, which is the one direction
     * this plugin must never be wrong in.
     *
     * The marker is letters only and at most four of them, so a real filename
     * ending in something like -1920x1080-v2 keeps its name. Over-matching here
     * marks an extra file as referenced, which is safe; under-matching deletes a
     * live one.
     *
     * @return array Regexes applied to the filename, extension already removed.
     */
    public static function derivative_patterns(): array
    {
        return (array) apply_filters(
            'uams_mra_derivative_patterns',
            [
                '#-\d{1,5}x\d{1,5}(?:-[a-z]{1,4})?$#i',
                '#-scaled$#i',
                '#-rotated$#i',
                '#-e\d{10,}$#i',
            ]
        );
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
        $ids = [];

        foreach (self::resolve_map($candidates) as $matched) {
            foreach ($matched as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Resolve candidates and keep track of which one matched what.
     *
     * Same matching as resolve_paths(), but the caller gets to see which
     * candidate produced which attachments. The render step needs that to say
     * *where* a file it recognised was being served from.
     *
     * @param  array  $candidates  Raw path or URL fragments.
     * @return array base_norm => array of attachment IDs.
     */
    public static function resolve_map(array $candidates): array
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

        $out = [];

        // Chunk to keep the IN() lists inside packet limits.
        foreach (array_chunk(array_keys($bases), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $sql = 'SELECT attachment_id, base_norm FROM '.UAMS_MRA_Store::index_table()." WHERE base_norm IN ({$placeholders})";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ($wpdb->get_results($wpdb->prepare($sql, $chunk)) as $row) {
                $out[$row->base_norm][] = (int) $row->attachment_id;
            }
        }

        return $out;
    }

    /**
     * Filter a list of integers down to those that are real attachment IDs.
     *
     * @param  array  $ids  Candidate IDs.
     */
    public static function filter_attachment_ids(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids))));
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
