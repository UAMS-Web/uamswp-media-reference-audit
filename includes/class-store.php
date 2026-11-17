<?php

/**
 * Table creation and result queries.
 */
defined('ABSPATH') || exit;

/**
 * A read of the offsite media table failed.
 */
class UAMS_MRA_Offsite_Read_Exception extends RuntimeException {}

/**
 * Storage layer.
 */
class UAMS_MRA_Store
{
    /**
     * Forces table names to a specific prefix while scanning sibling sites.
     *
     * @var string|null
     */
    protected static $prefix_override;

    /**
     * Request-lifetime cache for summary().
     *
     * @var array<string, int>|null
     */
    protected static $summary_cache;

    /**
     * Pin table names to another site's prefix, or pass null to clear.
     *
     * @param  string|null  $prefix  Table prefix.
     */
    public static function set_prefix_override($prefix): void
    {
        self::$prefix_override = $prefix ?: null;
    }

    /**
     * Active table prefix.
     *
     * @return string
     */
    protected static function prefix()
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        return self::$prefix_override ?: $wpdb->prefix;
    }

    /**
     * Attachment lookup index table name.
     */
    public static function index_table(): string
    {
        return self::prefix().'uams_mra_index';
    }

    /**
     * Reference hits table name.
     */
    public static function refs_table(): string
    {
        return self::prefix().'uams_mra_refs';
    }

    /**
     * Final per attachment results table name.
     */
    public static function results_table(): string
    {
        return self::prefix().'uams_mra_results';
    }

    /**
     * Offsite media hits table name.
     */
    public static function offsite_table(): string
    {
        return self::prefix().'uams_mra_offsite';
    }

    /**
     * Create or upgrade tables.
     */
    public static function install(): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $index = self::index_table();
        $refs = self::refs_table();
        $results = self::results_table();
        $offsite = self::offsite_table();

        $sql = [];

        $sql[] = "CREATE TABLE {$index} (
			attachment_id bigint(20) unsigned NOT NULL,
			path_norm varchar(255) NOT NULL DEFAULT '',
			base_norm varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (attachment_id),
			KEY base_norm (base_norm)
		) {$charset};";

        $sql[] = "CREATE TABLE {$refs} (
			ref_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			source_type varchar(32) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_label varchar(255) NOT NULL DEFAULT '',
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			confidence tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (ref_id),
			UNIQUE KEY uniq_ref (attachment_id,source_type,source_id,site_id),
			KEY attachment_id (attachment_id)
		) {$charset};";

        $sql[] = "CREATE TABLE {$results} (
			attachment_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_status varchar(20) NOT NULL DEFAULT '',
			is_attached tinyint(1) NOT NULL DEFAULT 0,
			ref_count int(11) NOT NULL DEFAULT 0,
			ref_count_strict int(11) NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			mime varchar(100) NOT NULL DEFAULT '',
			post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			title varchar(255) NOT NULL DEFAULT '',
			file varchar(255) NOT NULL DEFAULT '',
			scanned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			render_state varchar(20) NOT NULL DEFAULT '',
			render_url varchar(255) NOT NULL DEFAULT '',
			render_note varchar(255) NOT NULL DEFAULT '',
			render_checked_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (attachment_id),
			KEY ref_count (ref_count),
			KEY is_attached (is_attached),
			KEY bytes (bytes),
			KEY render_state (render_state)
		) {$charset};";

        // One row per (URL, place it is stored). The hash carries the unique key
        // because a media URL routinely runs past what MySQL will index.
        $sql[] = "CREATE TABLE {$offsite} (
			offsite_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(500) NOT NULL DEFAULT '',
			url_hash char(32) NOT NULL DEFAULT '',
			host varchar(191) NOT NULL DEFAULT '',
			base_norm varchar(191) NOT NULL DEFAULT '',
			source_type varchar(32) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_pk bigint(20) unsigned NOT NULL DEFAULT 0,
			source_key varchar(191) NOT NULL DEFAULT '',
			source_label varchar(255) NOT NULL DEFAULT '',
			local_id bigint(20) unsigned NOT NULL DEFAULT 0,
			verdict varchar(20) NOT NULL DEFAULT '',
			remote_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			local_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			target_url varchar(500) NOT NULL DEFAULT '',
			checked_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			applied tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (offsite_id),
			UNIQUE KEY uniq_hit (url_hash,source_type,source_pk),
			KEY host (host),
			KEY base_norm (base_norm),
			KEY verdict (verdict),
			KEY applied (applied)
		) {$charset};";

        self::drop_stale_indexes();

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option('uams_mra_db_version', UAMS_MRA_VERSION);
    }

    /**
     * Drop indexes whose definition changed, so dbDelta can rebuild them.
     *
     * dbDelta matches an index by name and leaves it alone if one already
     * exists, even when the columns underneath have changed. The offsite table's
     * uniqueness moved from the object a row belongs to (source_id) to the row
     * itself (source_pk); leaving the old key in place would let INSERT IGNORE
     * silently drop the second and later URLs found in different meta rows of
     * the same post.
     */
    protected static function drop_stale_indexes(): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        $offsite = self::offsite_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $offsite));

        if (! $exists) {
            return;
        }

        $columns = [];

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ((array) $wpdb->get_results("SHOW INDEX FROM {$offsite} WHERE Key_name = 'uniq_hit'") as $row) {
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $columns[] = $row->Column_name;
        }

        if ($columns !== [] && ! in_array('source_pk', $columns, true)) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE {$offsite} DROP INDEX uniq_hit");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Empty working tables before a fresh scan.
     */
    public static function reset(): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        self::$summary_cache = null;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('TRUNCATE TABLE '.self::index_table());
        $wpdb->query('TRUNCATE TABLE '.self::refs_table());
        $wpdb->query('TRUNCATE TABLE '.self::results_table());
        $wpdb->query('TRUNCATE TABLE '.self::offsite_table());
        // phpcs:enable
    }

    /**
     * Insert reference rows, ignoring duplicates.
     *
     * @param  array  $rows  Each row: attachment_id, source_type, source_id, source_label, site_id, confidence.
     * @param  list<array{attachment_id: int|string, source_type: string, source_id: int|string, source_label: string, site_id?: int|string, confidence?: int|string}>  $rows
     */
    public static function add_refs(array $rows): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        if ($rows === []) {
            return;
        }

        if (count($rows) > 500) {
            foreach (array_chunk($rows, 500) as $chunk) {
                self::add_refs($chunk);
            }

            return;
        }

        /** @var list<literal-string> $values */
        $values = [];
        $args = [];

        foreach ($rows as $row) {
            $values[] = '(%d,%s,%d,%s,%d,%d)';
            $args[] = (int) $row['attachment_id'];
            $args[] = (string) $row['source_type'];
            $args[] = (int) $row['source_id'];
            $args[] = mb_substr((string) $row['source_label'], 0, 250);
            $args[] = isset($row['site_id']) ? (int) $row['site_id'] : 0;
            $args[] = isset($row['confidence']) ? (int) $row['confidence'] : 1;
        }

        /** @var literal-string $refs_table */
        $refs_table = self::refs_table();
        $sql = 'INSERT IGNORE INTO '.$refs_table
            .' (attachment_id, source_type, source_id, source_label, site_id, confidence) VALUES '
            .implode(',', $values);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the query holds the refs table name and the fixed `%d`/`%s` tuples, and every value is bound through `prepare()`.
        /** @var string $statement */
        $statement = $wpdb->prepare($sql, $args);
        $wpdb->query($statement);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Reference rows for one attachment.
     *
     * @param  int  $attachment_id  Attachment ID.
     * @param  int  $limit  Max rows.
     * @return array<int, object>|null
     */
    public static function get_refs($attachment_id, $limit = 20)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        /** @var literal-string $refs_table */
        $refs_table = self::refs_table();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM '.$refs_table.' WHERE attachment_id = %d ORDER BY confidence DESC, ref_id ASC LIMIT %d',
                $attachment_id,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Recompute ref counts for specific attachments straight from the refs table.
     *
     * The finalize step writes ref_count once, from the refs collected up to that
     * point. The render step runs after it and can add references, so anything it
     * finds has to be folded back into the results row or the file would still
     * read as unreferenced.
     *
     * @param  array  $ids  Attachment IDs.
     * @param  array<int, int|string>  $ids
     */
    public static function recount_refs(array $ids): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids))));
        if ($ids === []) {
            return;
        }

        /** @var literal-string $results */
        $results = self::results_table();
        /** @var literal-string $refs */
        $refs = self::refs_table();

        foreach (array_chunk($ids, 200) as $chunk) {
            /** @var literal-string $placeholders */
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            /** @var literal-string $sql */
            $sql = "UPDATE {$results} r SET
				r.ref_count = ( SELECT COUNT(*) FROM {$refs} f WHERE f.attachment_id = r.attachment_id ),
				r.ref_count_strict = ( SELECT COUNT(*) FROM {$refs} f WHERE f.attachment_id = r.attachment_id AND f.confidence >= 2 )
				WHERE r.attachment_id IN ({$placeholders})";
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the query holds the results and refs table names and one `%d` per bound ID.
            /** @var string $statement */
            $statement = $wpdb->prepare($sql, $chunk);
            $wpdb->query($statement);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * Record the outcome of a render check against a set of attachments.
     *
     * @param  array  $ids  Attachment IDs.
     * @param  string  $state  One of found, offsite, absent, error, skipped.
     * @param  string  $url  URL that was checked, if any.
     * @param  string  $note  Extra detail, such as the host an offsite copy came from.
     * @param  array<int, int|string>  $ids
     */
    public static function set_render_state(array $ids, $state, $url = '', $note = ''): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids))));
        if ($ids === []) {
            return;
        }

        /** @var literal-string $results */
        $results = self::results_table();
        $now = current_time('mysql');

        foreach (array_chunk($ids, 200) as $chunk) {
            /** @var literal-string $placeholders */
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            /** @var literal-string $sql */
            $sql = "UPDATE {$results} SET render_state = %s, render_url = %s, render_note = %s, render_checked_at = %s
				WHERE attachment_id IN ({$placeholders})";
            $args = array_merge(
                [
                    (string) $state,
                    mb_substr((string) $url, 0, 250),
                    mb_substr((string) $note, 0, 250),
                    $now,
                ],
                $chunk
            );
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the query holds the results table name and one `%d` per bound ID.
            /** @var string $statement */
            $statement = $wpdb->prepare($sql, $args);
            $wpdb->query($statement);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * How the render step landed, for the dashboard.
     *
     * @return array<string, int> state => count
     */
    public static function render_counts(): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::results_table();

        /** @var array<string, int> $out */
        $out = [];
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        /** @var list<object{render_state: string, n: string}> $state_rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $state_rows = $wpdb->get_results("SELECT render_state, COUNT(*) AS n FROM {$t} WHERE render_state <> '' GROUP BY render_state");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ($state_rows as $row) {
            $out[$row->render_state] = (int) $row->n;
        }

        return $out;
    }

    /**
     * Insert offsite media hits, ignoring duplicates.
     *
     * @param  array  $rows  Each row: url, host, base_norm, source_type, source_id, source_label.
     * @param  list<array<string, int|string>>  $rows
     */
    public static function add_offsite(array $rows): void
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        if ($rows === []) {
            return;
        }

        if (count($rows) > 500) {
            foreach (array_chunk($rows, 500) as $chunk) {
                self::add_offsite($chunk);
            }

            return;
        }

        /** @var list<literal-string> $values */
        $values = [];
        $args = [];

        foreach ($rows as $row) {
            $url = mb_substr((string) $row['url'], 0, 500);

            $values[] = '(%s,%s,%s,%s,%s,%d,%d,%s,%s)';
            $args[] = $url;
            $args[] = md5($url);
            $args[] = mb_substr((string) $row['host'], 0, 191);
            $args[] = mb_substr((string) $row['base_norm'], 0, 191);
            $args[] = (string) $row['source_type'];
            $args[] = (int) $row['source_id'];
            $args[] = isset($row['source_pk']) ? (int) $row['source_pk'] : (int) $row['source_id'];
            $args[] = mb_substr((string) ($row['source_key'] ?? ''), 0, 191);
            $args[] = mb_substr((string) $row['source_label'], 0, 250);
        }

        /** @var literal-string $offsite_table */
        $offsite_table = self::offsite_table();
        $sql = 'INSERT IGNORE INTO '.$offsite_table
            .' (url, url_hash, host, base_norm, source_type, source_id, source_pk, source_key, source_label) VALUES '
            .implode(',', $values);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the query holds the offsite table name and the fixed `%s`/`%d` tuples, and every value is bound through `prepare()`.
        /** @var string $statement */
        $statement = $wpdb->prepare($sql, $args);
        $wpdb->query($statement);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Does the offsite report predate the columns a rewrite needs.
     *
     * Rows recorded before 1.8.0 name the post or option a URL belongs to but
     * not the database row it sits in, and dbDelta fills the new column with a
     * zero rather than going back to find it. Rewriting from those rows would
     * fail on every one of them, so the actions refuse until a scan has rebuilt
     * the table.
     */
    public static function offsite_needs_rescan(): bool
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::offsite_table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (bool) $wpdb->get_var("SELECT 1 FROM {$t} WHERE source_pk = 0 LIMIT 1");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Headline counts for the offsite report.
     *
     * @return array<string, int>
     */
    public static function offsite_summary(): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::offsite_table();
        $i = self::index_table();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        $row = $wpdb->get_row(
            "SELECT
				COUNT(*) AS hits,
				COUNT(DISTINCT url_hash) AS files,
				COUNT(DISTINCT host) AS hosts
			FROM {$t}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        $with_local = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT o.url_hash) FROM {$t} o
			 INNER JOIN {$i} x ON x.base_norm = o.base_norm"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable

        /** @var array<string, string>|null $row */
        $out = is_array($row) ? array_map(intval(...), $row) : [
            'hits' => 0,
            'files' => 0,
            'hosts' => 0,
        ];
        $out['with_local'] = $with_local;

        return $out;
    }

    /**
     * Hosts serving offsite media, most references first.
     *
     * @return list<array{host: string, hits: numeric-string, files: numeric-string}>
     *
     * @throws UAMS_MRA_Offsite_Read_Exception When the read fails.
     */
    public static function offsite_hosts(): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::offsite_table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        /** @var list<array<string, mixed>>|null $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT host, COUNT(*) AS hits, COUNT(DISTINCT url_hash) AS files
			 FROM {$t} GROUP BY host ORDER BY hits DESC",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // The queries above select exactly these columns.
        /** @var list<array{host: string, hits: numeric-string, files: numeric-string}> $checked */
        $checked = self::offsite_rows($rows, 'the offsite hosts');

        return $checked;
    }

    /**
     * Pass a successful read through, or stop on a failed one.
     *
     * With `ARRAY_A`, `$wpdb->get_results()` returns an empty list when the query fails, and null only when the
     * query text is empty (for example when `prepare()` refuses its arguments). Neither can be told from a table with
     * no rows, so the database error is read as well.
     *
     * @param  list<array<string, mixed>>|null  $rows  What `get_results()` returned.
     * @param  string  $what  What was being read, for the message.
     * @return list<array<string, mixed>>
     *
     * @throws UAMS_MRA_Offsite_Read_Exception When the read failed.
     */
    private static function offsite_rows($rows, string $what): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        if ($rows === null || $wpdb->last_error !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is never printed as it stands: the screen and the export each pass it through `esc_html()`.
            throw new UAMS_MRA_Offsite_Read_Exception(sprintf('Could not read %s: %s', $what, $wpdb->last_error !== '' ? $wpdb->last_error : 'the query was empty'));
        }

        return $rows;
    }

    /**
     * One row per distinct offsite file.
     *
     * @param  string  $host  Limit to one host, or '' for all.
     * @param  int  $limit  Rows.
     * @param  int  $offset  Offset.
     * @return list<array{url: string, url_hash: string, host: string, base_norm: string, hits: numeric-string, source_types: string, local_ids: string|null}>
     *
     * @throws UAMS_MRA_Offsite_Read_Exception When the read fails.
     */
    public static function offsite_files($host = '', $limit = 100, $offset = 0): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::offsite_table();
        /** @var literal-string $i */
        $i = self::index_table();

        /** @var literal-string $where */
        $where = '';
        $args = [];

        if ($host !== '') {
            $where = 'WHERE o.host = %s';
            $args[] = $host;
        }

        $args[] = (int) $limit;
        $args[] = (int) $offset;

        /** @var literal-string $sql */
        $sql = "SELECT o.url, o.url_hash, o.host, o.base_norm,
					COUNT(*) AS hits,
					GROUP_CONCAT(DISTINCT o.source_type ORDER BY o.source_type SEPARATOR ', ') AS source_types,
					(
						SELECT GROUP_CONCAT(x.attachment_id ORDER BY x.attachment_id SEPARATOR ',')
						FROM {$i} x WHERE x.base_norm = o.base_norm
					) AS local_ids
				FROM {$t} o
				{$where}
				GROUP BY o.url, o.url_hash, o.host, o.base_norm
				ORDER BY hits DESC, o.url ASC
				LIMIT %d OFFSET %d";

        /** @var list<array<string, mixed>>|null $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        // The queries above select exactly these columns.
        /** @var list<array{url: string, url_hash: string, host: string, base_norm: string, hits: numeric-string, source_types: string, local_ids: string|null}> $checked */
        $checked = self::offsite_rows($rows, 'the offsite files');

        return $checked;
    }

    /**
     * How many distinct offsite files there are, for paging.
     *
     * @param  string  $host  Limit to one host, or '' for all.
     */
    public static function offsite_file_count($host = ''): int
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::offsite_table();

        if ($host === '') {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var("SELECT COUNT(DISTINCT url_hash) FROM {$t}");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT url_hash) FROM {$t} WHERE host = %s", $host));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Every place one offsite URL is stored.
     *
     * @param  string  $url_hash  Hash from offsite_files().
     * @param  int  $limit  Rows.
     * @return list<array{source_type: string, source_id: numeric-string, source_label: string}>
     *
     * @throws UAMS_MRA_Offsite_Read_Exception When the read fails.
     */
    public static function offsite_sources($url_hash, $limit = 25): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $t */
        $t = self::offsite_table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        /** @var list<array<string, mixed>>|null $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_type, source_id, source_label FROM {$t}
				 WHERE url_hash = %s ORDER BY source_type ASC, source_id ASC LIMIT %d",
                $url_hash,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // The queries above select exactly these columns.
        /** @var list<array{source_type: string, source_id: numeric-string, source_label: string}> $checked */
        $checked = self::offsite_rows($rows, 'where an offsite file is stored');

        return $checked;
    }

    /**
     * Summary counts for the dashboard.
     *
     * @return array<string, int>
     */
    public static function summary()
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        // Cached for the request. The view resolver, the cards, and the triage
        // counts all want it, and it is one aggregate over the whole table.
        if (self::$summary_cache !== null) {
            return self::$summary_cache;
        }

        /** @var literal-string $t */
        $t = self::results_table();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        $row = $wpdb->get_row(
            "SELECT
				COUNT(*) AS total,
				SUM(bytes) AS total_bytes,
				SUM(CASE WHEN is_attached = 0 THEN 1 ELSE 0 END) AS unattached,
				SUM(CASE WHEN ref_count = 0 THEN 1 ELSE 0 END) AS unreferenced,
				SUM(CASE WHEN ref_count = 0 THEN bytes ELSE 0 END) AS unreferenced_bytes,
				SUM(CASE WHEN ref_count = 0 AND is_attached = 0 THEN 1 ELSE 0 END) AS orphaned,
				SUM(CASE WHEN ref_count = 0 AND is_attached = 0 THEN bytes ELSE 0 END) AS orphaned_bytes,
				SUM(CASE WHEN ref_count > 0 AND ref_count_strict = 0 THEN 1 ELSE 0 END) AS weak_only
			FROM {$t}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable

        /** @var array<string, string>|null $row */
        self::$summary_cache = is_array($row) ? array_map(intval(...), $row) : [];

        return self::$summary_cache;
    }
}
