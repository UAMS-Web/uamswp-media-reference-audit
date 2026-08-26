<?php
/**
 * Table creation and result queries.
 *
 * @package UAMS_Media_Reference_Audit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage layer.
 */
class UAMS_MRA_Store {

	/**
	 * Forces table names to a specific prefix while scanning sibling sites.
	 *
	 * @var string|null
	 */
	protected static $prefix_override = null;

	/**
	 * Request-lifetime cache for summary().
	 *
	 * @var array|null
	 */
	protected static $summary_cache = null;

	/**
	 * Pin table names to another site's prefix, or pass null to clear.
	 *
	 * @param string|null $prefix Table prefix.
	 */
	public static function set_prefix_override( $prefix ) {
		self::$prefix_override = $prefix ? $prefix : null;
	}

	/**
	 * Active table prefix.
	 *
	 * @return string
	 */
	protected static function prefix() {
		global $wpdb;
		return self::$prefix_override ? self::$prefix_override : $wpdb->prefix;
	}

	/**
	 * Attachment lookup index table name.
	 *
	 * @return string
	 */
	public static function index_table() {
		return self::prefix() . 'uams_mra_index';
	}

	/**
	 * Reference hits table name.
	 *
	 * @return string
	 */
	public static function refs_table() {
		return self::prefix() . 'uams_mra_refs';
	}

	/**
	 * Final per attachment results table name.
	 *
	 * @return string
	 */
	public static function results_table() {
		return self::prefix() . 'uams_mra_results';
	}

	/**
	 * Offsite media hits table name.
	 *
	 * @return string
	 */
	public static function offsite_table() {
		return self::prefix() . 'uams_mra_offsite';
	}

	/**
	 * Create or upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$index   = self::index_table();
		$refs    = self::refs_table();
		$results = self::results_table();
		$offsite = self::offsite_table();

		$sql = array();

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

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'uams_mra_db_version', UAMS_MRA_VERSION );
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
	protected static function drop_stale_indexes() {
		global $wpdb;

		$offsite = self::offsite_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $offsite ) );

		if ( ! $exists ) {
			return;
		}

		$columns = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$offsite} WHERE Key_name = 'uniq_hit'" ) as $row ) {
			$columns[] = $row->Column_name;
		}

		if ( ! empty( $columns ) && ! in_array( 'source_pk', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$offsite} DROP INDEX uniq_hit" );
		}
	}

	/**
	 * Empty working tables before a fresh scan.
	 */
	public static function reset() {
		global $wpdb;

		self::$summary_cache = null;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . self::index_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::refs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::results_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::offsite_table() );
		// phpcs:enable
	}

	/**
	 * Insert reference rows, ignoring duplicates.
	 *
	 * @param array $rows Each row: attachment_id, source_type, source_id, source_label, site_id, confidence.
	 */
	public static function add_refs( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}

		if ( count( $rows ) > 500 ) {
			foreach ( array_chunk( $rows, 500 ) as $chunk ) {
				self::add_refs( $chunk );
			}
			return;
		}

		$values = array();
		$args   = array();

		foreach ( $rows as $row ) {
			$values[] = '(%d,%s,%d,%s,%d,%d)';
			$args[]   = (int) $row['attachment_id'];
			$args[]   = (string) $row['source_type'];
			$args[]   = (int) $row['source_id'];
			$args[]   = mb_substr( (string) $row['source_label'], 0, 250 );
			$args[]   = isset( $row['site_id'] ) ? (int) $row['site_id'] : 0;
			$args[]   = isset( $row['confidence'] ) ? (int) $row['confidence'] : 1;
		}

		$sql = 'INSERT IGNORE INTO ' . self::refs_table()
			. ' (attachment_id, source_type, source_id, source_label, site_id, confidence) VALUES '
			. implode( ',', $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Reference rows for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit         Max rows.
	 * @return array
	 */
	public static function get_refs( $attachment_id, $limit = 20 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::refs_table() . ' WHERE attachment_id = %d ORDER BY confidence DESC, ref_id ASC LIMIT %d',
				$attachment_id,
				$limit
			)
		);
	}

	/**
	 * Recompute ref counts for specific attachments straight from the refs table.
	 *
	 * The finalize step writes ref_count once, from the refs collected up to that
	 * point. The render step runs after it and can add references, so anything it
	 * finds has to be folded back into the results row or the file would still
	 * read as unreferenced.
	 *
	 * @param array $ids Attachment IDs.
	 */
	public static function recount_refs( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$results = self::results_table();
		$refs    = self::refs_table();

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = "UPDATE {$results} r SET
				r.ref_count = ( SELECT COUNT(*) FROM {$refs} f WHERE f.attachment_id = r.attachment_id ),
				r.ref_count_strict = ( SELECT COUNT(*) FROM {$refs} f WHERE f.attachment_id = r.attachment_id AND f.confidence >= 2 )
				WHERE r.attachment_id IN ({$placeholders})";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $chunk ) );
		}
	}

	/**
	 * Record the outcome of a render check against a set of attachments.
	 *
	 * @param array  $ids   Attachment IDs.
	 * @param string $state One of found, offsite, absent, error, skipped.
	 * @param string $url   URL that was checked, if any.
	 * @param string $note  Extra detail, such as the host an offsite copy came from.
	 */
	public static function set_render_state( array $ids, $state, $url = '', $note = '' ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$results = self::results_table();
		$now     = current_time( 'mysql' );

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = "UPDATE {$results} SET render_state = %s, render_url = %s, render_note = %s, render_checked_at = %s
				WHERE attachment_id IN ({$placeholders})";
			$args         = array_merge(
				array(
					(string) $state,
					mb_substr( (string) $url, 0, 250 ),
					mb_substr( (string) $note, 0, 250 ),
					$now,
				),
				$chunk
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * How the render step landed, for the dashboard.
	 *
	 * @return array state => count
	 */
	public static function render_counts() {
		global $wpdb;
		$t = self::results_table();

		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( "SELECT render_state, COUNT(*) AS n FROM {$t} WHERE render_state <> '' GROUP BY render_state" ) as $row ) {
			$out[ $row->render_state ] = (int) $row->n;
		}

		return $out;
	}

	/**
	 * Insert offsite media hits, ignoring duplicates.
	 *
	 * @param array $rows Each row: url, host, base_norm, source_type, source_id, source_label.
	 */
	public static function add_offsite( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		if ( count( $rows ) > 500 ) {
			foreach ( array_chunk( $rows, 500 ) as $chunk ) {
				self::add_offsite( $chunk );
			}
			return;
		}

		$values = array();
		$args   = array();

		foreach ( $rows as $row ) {
			$url = mb_substr( (string) $row['url'], 0, 500 );

			$values[] = '(%s,%s,%s,%s,%s,%d,%d,%s,%s)';
			$args[]   = $url;
			$args[]   = md5( $url );
			$args[]   = mb_substr( (string) $row['host'], 0, 191 );
			$args[]   = mb_substr( (string) $row['base_norm'], 0, 191 );
			$args[]   = (string) $row['source_type'];
			$args[]   = (int) $row['source_id'];
			$args[]   = isset( $row['source_pk'] ) ? (int) $row['source_pk'] : (int) $row['source_id'];
			$args[]   = mb_substr( (string) ( $row['source_key'] ?? '' ), 0, 191 );
			$args[]   = mb_substr( (string) $row['source_label'], 0, 250 );
		}

		$sql = 'INSERT IGNORE INTO ' . self::offsite_table()
			. ' (url, url_hash, host, base_norm, source_type, source_id, source_pk, source_key, source_label) VALUES '
			. implode( ',', $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Does the offsite report predate the columns a rewrite needs.
	 *
	 * Rows recorded before 1.8.0 name the post or option a URL belongs to but
	 * not the database row it sits in, and dbDelta fills the new column with a
	 * zero rather than going back to find it. Rewriting from those rows would
	 * fail on every one of them, so the actions refuse until a scan has rebuilt
	 * the table.
	 *
	 * @return bool
	 */
	public static function offsite_needs_rescan() {
		global $wpdb;

		$t = self::offsite_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$t} WHERE source_pk = 0 LIMIT 1" );
	}

	/**
	 * Headline counts for the offsite report.
	 *
	 * @return array
	 */
	public static function offsite_summary() {
		global $wpdb;

		$t = self::offsite_table();
		$i = self::index_table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS hits,
				COUNT(DISTINCT url_hash) AS files,
				COUNT(DISTINCT host) AS hosts
			FROM {$t}",
			ARRAY_A
		);

		$with_local = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.url_hash) FROM {$t} o
			 INNER JOIN {$i} x ON x.base_norm = o.base_norm"
		);
		// phpcs:enable

		$out               = is_array( $row ) ? array_map( 'intval', $row ) : array(
			'hits'  => 0,
			'files' => 0,
			'hosts' => 0,
		);
		$out['with_local'] = $with_local;

		return $out;
	}

	/**
	 * Hosts serving offsite media, most references first.
	 *
	 * @return array
	 */
	public static function offsite_hosts() {
		global $wpdb;
		$t = self::offsite_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT host, COUNT(*) AS hits, COUNT(DISTINCT url_hash) AS files
			 FROM {$t} GROUP BY host ORDER BY hits DESC",
			ARRAY_A
		);
	}

	/**
	 * One row per distinct offsite file.
	 *
	 * @param string $host   Limit to one host, or '' for all.
	 * @param int    $limit  Rows.
	 * @param int    $offset Offset.
	 * @return array
	 */
	public static function offsite_files( $host = '', $limit = 100, $offset = 0 ) {
		global $wpdb;

		$t = self::offsite_table();
		$i = self::index_table();

		$where = '';
		$args  = array();

		if ( '' !== $host ) {
			$where  = 'WHERE o.host = %s';
			$args[] = $host;
		}

		$args[] = (int) $limit;
		$args[] = (int) $offset;

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/**
	 * How many distinct offsite files there are, for paging.
	 *
	 * @param string $host Limit to one host, or '' for all.
	 * @return int
	 */
	public static function offsite_file_count( $host = '' ) {
		global $wpdb;
		$t = self::offsite_table();

		if ( '' === $host ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT url_hash) FROM {$t}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT url_hash) FROM {$t} WHERE host = %s", $host ) );
	}

	/**
	 * Every place one offsite URL is stored.
	 *
	 * @param string $url_hash Hash from offsite_files().
	 * @param int    $limit    Rows.
	 * @return array
	 */
	public static function offsite_sources( $url_hash, $limit = 25 ) {
		global $wpdb;
		$t = self::offsite_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_type, source_id, source_label FROM {$t}
				 WHERE url_hash = %s ORDER BY source_type ASC, source_id ASC LIMIT %d",
				$url_hash,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Summary counts for the dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;

		// Cached for the request. The view resolver, the cards, and the triage
		// counts all want it, and it is one aggregate over the whole table.
		if ( null !== self::$summary_cache ) {
			return self::$summary_cache;
		}

		$t = self::results_table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
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
		// phpcs:enable

		self::$summary_cache = is_array( $row ) ? array_map( 'intval', $row ) : array();

		return self::$summary_cache;
	}
}
