<?php
/**
 * Uninstall cleanup.
 *
 * Drops the three working tables and the three options the plugin creates.
 * Nothing here is worth preserving: every row is derived from a scan and is
 * rebuilt from scratch the next time one runs.
 *
 * This file runs without the plugin bootstrap, so the table suffixes and option
 * names are repeated as literals rather than read from UAMS_MRA_Store. When a
 * new table or option is added, it has to be added here too.
 *
 * @package UAMS_Media_Reference_Audit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$uams_mra_tables  = array( 'uams_mra_index', 'uams_mra_refs', 'uams_mra_results' );
$uams_mra_options = array( 'uams_mra_settings', 'uams_mra_scan_state', 'uams_mra_db_version' );

/**
 * Remove one site's tables and options.
 *
 * @param array $tables  Table names without the site prefix.
 * @param array $options Option names.
 */
function uams_mra_uninstall_site( array $tables, array $options ) {
	global $wpdb;

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
	}

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	// Tables are created per site, so a network uninstall has to walk them all.
	$uams_mra_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $uams_mra_sites as $uams_mra_site_id ) {
		switch_to_blog( $uams_mra_site_id );
		uams_mra_uninstall_site( $uams_mra_tables, $uams_mra_options );
		restore_current_blog();
	}
} else {
	uams_mra_uninstall_site( $uams_mra_tables, $uams_mra_options );
}
