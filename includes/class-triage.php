<?php

/**
 * Per-file triage.
 *
 * Two separate things live here. A *tier* is derived from the scan and says how
 * much the scan can vouch for a file -- it is recomputed on every render and
 * stored nowhere. A *disposition* is a judgement a person made, and has to
 * outlive the scan that prompted it.
 *
 * Dispositions are post meta on the attachment rather than columns on the
 * results table, because UAMS_MRA_Store::reset() truncates that table at the
 * start of every scan. A mark stored there would vanish the moment anyone
 * rescanned, which is exactly when the team most wants it. Post meta also
 * disappears by itself when the attachment is deleted, and is shared across
 * every admin user without any syncing.
 *
 * The two meta keys are in UAMS_MRA_Scanner::skipped_meta_keys(): a note is
 * free text, and a note that quotes an upload URL would otherwise be recorded
 * as a strong reference to *that* file on the next scan.
 */
defined('ABSPATH') || exit;

/**
 * Risk tiers and per-file dispositions.
 */
class UAMS_MRA_Triage
{
    /**
     * Meta key holding the disposition.
     */
    const META_DISPOSITION = '_uams_mra_disposition';

    /**
     * Meta key holding the note.
     */
    const META_NOTE = '_uams_mra_note';

    /**
     * Longest note we store.
     */
    const NOTE_MAX = 200;

    /**
     * Tiers, most cautious first.
     */
    public static function tiers(): array
    {
        return [
            'verify' => [
                'label' => __('Verify', 'uams-mra'),
                'desc' => __('Attached to a post, and the parent page has not been read. The page may display it in a way a database scan cannot see.', 'uams-mra'),
            ],
            'ask' => [
                'label' => __('Ask team', 'uams-mra'),
                'desc' => __('A document, video, or audio file. These get handed out by email, print, and QR code, where no reference would ever reach the database.', 'uams-mra'),
            ],
            'clear' => [
                'label' => __('Low risk', 'uams-mra'),
                'desc' => __('An image that is either unattached, or attached to a page that was opened and does not display it. Check the page yourself if it varies by date or visitor.', 'uams-mra'),
            ],
        ];
    }

    /**
     * Dispositions a person can set.
     */
    public static function dispositions(): array
    {
        return [
            'keep' => __('Keep', 'uams-mra'),
            'ask' => __('Ask', 'uams-mra'),
            'delete' => __('Delete', 'uams-mra'),
        ];
    }

    /**
     * Mime types that usually travel outside the site.
     *
     * Anything listed here lands in the "ask team" tier when it is unattached,
     * because its absence from the database says very little about whether it
     * is still in circulation.
     */
    public static function distributable_mimes(): array
    {
        return (array) apply_filters(
            'uams_mra_distributable_mimes',
            [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'text/csv',
            ]
        );
    }

    /**
     * SQL fragment matching a distributable file.
     *
     * Built once here so the tier a row is shown in and the tier it is counted
     * in can never disagree.
     */
    protected static function distributable_sql(): string
    {
        $mimes = array_map(esc_sql(...), self::distributable_mimes());
        $list = "'".implode("','", $mimes)."'";

        // LEFT() rather than LIKE 'video/%': this fragment is concatenated into
        // queries that go through $wpdb->prepare(), where a literal % is read as
        // a malformed placeholder. Escaping it as %% would then be wrong in
        // counts(), which does not prepare. No % at all is correct in both.
        return "( LEFT( mime, 6 ) = 'video/' OR LEFT( mime, 6 ) = 'audio/' OR mime IN ( {$list} ) )";
    }

    /**
     * WHERE fragment selecting one tier from the results table.
     *
     * @param  string  $tier  Tier key.
     */
    public static function tier_where($tier): string
    {
        // A file whose parent page was fetched and does not display it has had
        // the one check "verify" was asking for. It drops to whatever tier it
        // would sit in with no parent at all, which is the mime question.
        $settled = "( is_attached = 0 OR render_state = 'absent' )";

        return match ($tier) {
            'verify' => "is_attached = 1 AND render_state <> 'absent'",
            'ask' => $settled.' AND '.self::distributable_sql(),
            'clear' => $settled.' AND NOT '.self::distributable_sql(),
            default => '1=1',
        };
    }

    /**
     * Tier for one results row.
     *
     * @param  array  $row  Results row.
     */
    public static function tier($row): string
    {
        $render = isset($row['render_state']) ? (string) $row['render_state'] : '';

        if (! empty($row['is_attached']) && $render !== 'absent') {
            return 'verify';
        }

        $mime = isset($row['mime']) ? (string) $row['mime'] : '';

        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            return 'ask';
        }

        return in_array($mime, self::distributable_mimes(), true) ? 'ask' : 'clear';
    }

    /**
     * WHERE fragment selecting rows by disposition.
     *
     * @param  string  $disposition  One of the disposition keys, or 'none'.
     */
    public static function disposition_where($disposition): string
    {
        global $wpdb;

        $key = esc_sql(self::META_DISPOSITION);

        if ($disposition === 'none') {
            return "attachment_id NOT IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '{$key}' AND meta_value <> ''
			)";
        }

        $value = esc_sql($disposition);

        return "attachment_id IN (
			SELECT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '{$key}' AND meta_value = '{$value}'
		)";
    }

    /**
     * Read one file's triage.
     *
     * @param  int  $attachment_id  Attachment ID.
     * @return array {d, n}
     */
    public static function get($attachment_id): array
    {
        $attachment_id = (int) $attachment_id;

        return [
            'd' => (string) get_post_meta($attachment_id, self::META_DISPOSITION, true),
            'n' => (string) get_post_meta($attachment_id, self::META_NOTE, true),
        ];
    }

    /**
     * Write one file's triage.
     *
     * An empty disposition or note removes the row rather than storing a blank,
     * so "undecided" stays the absence of a mark.
     *
     * @param  int  $attachment_id  Attachment ID.
     * @param  string  $disposition  Disposition key, or '' to clear.
     * @param  string  $note  Note text, or '' to clear.
     * @return array The stored triage.
     */
    public static function set($attachment_id, $disposition, $note): array
    {
        $attachment_id = (int) $attachment_id;

        $disposition = (string) $disposition;
        if (! array_key_exists($disposition, self::dispositions())) {
            $disposition = '';
        }

        $note = sanitize_text_field((string) $note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            $note = mb_substr($note, 0, self::NOTE_MAX);
        }

        if ($disposition === '') {
            delete_post_meta($attachment_id, self::META_DISPOSITION);
        } else {
            update_post_meta($attachment_id, self::META_DISPOSITION, $disposition);
        }

        if ($note === '') {
            delete_post_meta($attachment_id, self::META_NOTE);
        } else {
            update_post_meta($attachment_id, self::META_NOTE, $note);
        }

        return [
            'd' => $disposition,
            'n' => $note,
        ];
    }

    /**
     * Every attachment currently marked with one disposition.
     *
     * Restricted to rows the last scan produced, so a mark left on a file that
     * has since been deleted or excluded cannot resurface.
     *
     * @param  string  $disposition  Disposition key.
     * @return array Attachment IDs.
     */
    public static function ids_marked($disposition): array
    {
        global $wpdb;

        if (! array_key_exists($disposition, self::dispositions())) {
            return [];
        }

        $table = UAMS_MRA_Store::results_table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT r.attachment_id
				 FROM {$table} r
				 INNER JOIN {$wpdb->postmeta} pm
				         ON pm.post_id = r.attachment_id AND pm.meta_key = %s
				 WHERE pm.meta_value = %s",
                self::META_DISPOSITION,
                $disposition
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(intval(...), (array) $ids);
    }

    /**
     * Counts for the triage cards.
     *
     * Scoped to a view, because the cards sit above a table that is showing one.
     * Counting the whole library there would advertise numbers the list below
     * cannot produce.
     *
     * @param  string  $scope  WHERE fragment limiting the count, or '' for everything.
     */
    public static function counts($scope = ''): array
    {
        global $wpdb;

        $table = UAMS_MRA_Store::results_table();
        $scope = $scope === '' ? '1=1' : $scope;

        $out = [
            'tier' => [],
            'disp' => [
                'keep' => 0,
                'ask' => 0,
                'delete' => 0,
            ],
            'delete_bytes' => 0,
        ];

        foreach (array_keys(self::tiers()) as $tier) {
            $where = self::tier_where($tier);
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the WHERE fragment is assembled from literals in `UAMS_MRA_List_Table::where_for_view()`, `UAMS_MRA_Triage::tier_where()` or a fixed map, and user text reaches the query only through `%s` placeholders in `$params`.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $out['tier'][$tier] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE ( {$scope} ) AND ( {$where} )"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS d, COUNT(*) AS n, SUM(r.bytes) AS b
				 FROM {$table} r
				 INNER JOIN {$wpdb->postmeta} pm
				         ON pm.post_id = r.attachment_id AND pm.meta_key = %s
				 WHERE pm.meta_value <> '' AND ( {$scope} )
				 GROUP BY pm.meta_value",
                self::META_DISPOSITION
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $marked = 0;

        foreach ((array) $rows as $row) {
            $key = (string) $row['d'];
            if (! isset($out['disp'][$key])) {
                continue;
            }

            $out['disp'][$key] = (int) $row['n'];
            $marked += (int) $row['n'];
            if ($key === 'delete') {
                $out['delete_bytes'] = (int) $row['b'];
            }
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ( {$scope} )");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['none'] = max(0, $total - $marked);

        return $out;
    }
}
