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
	 * Create or upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$index   = self::index_table();
		$refs    = self::refs_table();
		$results = self::results_table();

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
			PRIMARY KEY  (attachment_id),
			KEY ref_count (ref_count),
			KEY is_attached (is_attached),
			KEY bytes (bytes)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'uams_mra_db_version', UAMS_MRA_VERSION );
	}

	/**
	 * Empty working tables before a fresh scan.
	 */
	public static function reset() {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . self::index_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::refs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::results_table() );
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
	 * Summary counts for the dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
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

		return is_array( $row ) ? array_map( 'intval', $row ) : array();
	}
}
