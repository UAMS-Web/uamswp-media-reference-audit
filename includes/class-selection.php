<?php

declare(strict_types=1);

/**
 * Building a working set of attachments.
 *
 * Two ways in. Either everything matching the filter currently on screen --
 * view, tier, decision, search -- or a list pasted from somewhere else: a
 * content audit spreadsheet, a Statamic inventory, an email from whoever owns
 * the files. Both land in the same place, so the review screen and the archive
 * job never need to know which one produced them.
 *
 * The set is stored per user rather than site-wide: two people triaging at once
 * should not overwrite each other's working set.
 */
defined('ABSPATH') || exit;

/**
 * Resolves and stores the set of files being acted on.
 */
class UAMS_MRA_Selection
{
    /**
     * User meta holding the current working set.
     */
    const META_KEY = '_uams_mra_selection';

    /**
     * Most files one set may hold.
     *
     * Not a technical limit so much as a guard against someone selecting an
     * entire library and then being handed a delete button for it.
     */
    const MAX = 5000;

    /**
     * Every attachment matching the filter on screen.
     *
     * @return array Attachment IDs.
     */
    public static function ids_matching_filter(): array
    {
        global $wpdb;

        $table = UAMS_MRA_Store::results_table();
        $query = UAMS_MRA_List_Table::build_query();
        $where = $query['where'];

        $sql = "SELECT attachment_id FROM {$table} WHERE {$where} ORDER BY bytes DESC LIMIT ".(self::MAX + 1);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $query['params']
            ? $wpdb->get_col($wpdb->prepare($sql, $query['params']))
            : $wpdb->get_col($sql);

        return array_map('intval', (array) $ids);
    }

    /**
     * Turn pasted text into attachment IDs.
     *
     * Accepts IDs, filenames, and URLs, one per line or comma separated, which
     * is what actually comes out of a spreadsheet column or a pasted list. What
     * could not be matched comes back so the screen can say so rather than
     * silently acting on a shorter list than the person pasted.
     *
     * @param  string  $text  Pasted text.
     * @return array {ids, unmatched}
     */
    public static function resolve_text($text): array
    {
        $tokens = preg_split('#[\r\n,;]+#', (string) $text);
        $ids = [];
        $missed = [];

        foreach ((array) $tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $id = self::resolve_token($token);

            if ($id) {
                $ids[] = $id;
            } else {
                $missed[] = $token;
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'unmatched' => $missed,
        ];
    }

    /**
     * Resolve one pasted token to an attachment ID.
     *
     * @param  string  $token  ID, URL, or filename.
     * @return int Attachment ID, or 0.
     */
    protected static function resolve_token($token): int
    {
        if (ctype_digit($token)) {
            return get_post_type((int) $token) === 'attachment' ? (int) $token : 0;
        }

        if (preg_match('#^https?://#i', $token)) {
            $id = attachment_url_to_postid($token);
            if ($id) {
                return (int) $id;
            }

            // A CDN or a rewritten host will not resolve, but the filename still can.
            $path = (string) wp_parse_url($token, PHP_URL_PATH);
            $token = $path ?: $token;
        }

        return self::id_by_filename(basename($token));
    }

    /**
     * Find an attachment by filename.
     *
     * Matches against the scan results rather than postmeta, so it costs one
     * indexed query and covers exactly the files the audit knows about.
     *
     * @param  string  $filename  Basename.
     * @return int Attachment ID, or 0.
     */
    protected static function id_by_filename($filename): int
    {
        global $wpdb;

        $filename = trim(rawurldecode($filename));

        if ($filename === '') {
            return 0;
        }

        $table = UAMS_MRA_Store::results_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attachment_id FROM {$table} WHERE file = %s OR file LIKE %s ORDER BY attachment_id ASC LIMIT 1",
                $filename,
                '%/'.$wpdb->esc_like($filename)
            )
        );

        return (int) $id;
    }

    /**
     * Store the working set for this user.
     *
     * @param  array  $ids  Attachment IDs.
     * @return array The stored IDs.
     */
    public static function store(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (count($ids) > self::MAX) {
            $ids = array_slice($ids, 0, self::MAX);
        }

        update_user_meta(get_current_user_id(), self::META_KEY, $ids);

        return $ids;
    }

    /**
     * Read the working set.
     *
     * @return array Attachment IDs.
     */
    public static function get(): array
    {
        $ids = get_user_meta(get_current_user_id(), self::META_KEY, true);

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * Forget the working set.
     */
    public static function clear(): void
    {
        delete_user_meta(get_current_user_id(), self::META_KEY);
    }

    /**
     * Results rows for the working set, largest first.
     *
     * @param  array  $ids  Attachment IDs.
     * @return array Rows.
     */
    public static function rows(array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $table = UAMS_MRA_Store::results_table();
        $ids = array_map('intval', $ids);
        $in = implode(',', $ids);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE attachment_id IN ( {$in} ) ORDER BY bytes DESC",
            ARRAY_A
        );

        return (array) $rows;
    }

    /**
     * Totals for a set of rows.
     *
     * @param  array  $rows  Results rows.
     */
    public static function totals(array $rows): array
    {
        $out = [
            'count' => count($rows),
            'bytes' => 0,
            'referenced' => 0,
            'attached' => 0,
            'missing' => 0,
        ];

        foreach ($rows as $row) {
            $out['bytes'] += (int) $row['bytes'];

            if ((int) $row['ref_count'] > 0) {
                $out['referenced']++;
            }

            if ((int) $row['is_attached']) {
                $out['attached']++;
            }

            if (! (int) $row['bytes']) {
                $out['missing']++;
            }
        }

        return $out;
    }
}
