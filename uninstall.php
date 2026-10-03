<?php

/**
 * Uninstall cleanup.
 *
 * Drops the three working tables and the three options the plugin creates, and
 * removes the triage meta from every attachment. The tables and options are
 * derived from a scan and rebuild themselves; the triage marks do NOT -- they
 * are human judgement, and this deletes them.
 *
 * That is deliberate. WordPress asks "delete these files and data?" before
 * running this file, and orphaned post meta would be unreachable afterwards on
 * a host with no database access. Export the CSV first if the marks matter: it
 * carries the tier, decision, and note for every file.
 *
 * This file runs without the plugin bootstrap, so the table suffixes and option
 * names are repeated as literals rather than read from UAMS_MRA_Store. When a
 * new table or option is added, it has to be added here too.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$uams_mra_tables = ['uams_mra_index', 'uams_mra_refs', 'uams_mra_results'];
$uams_mra_options = ['uams_mra_settings', 'uams_mra_scan_state', 'uams_mra_db_version'];
$uams_mra_meta = ['_uams_mra_disposition', '_uams_mra_note'];

/**
 * Remove one site's tables and options.
 *
 * @param  array  $tables  Table names without the site prefix.
 * @param  array  $options  Option names.
 * @param  array  $meta  Post meta keys.
 */
function uams_mra_uninstall_site(array $tables, array $options, array $meta): void
{
    global $wpdb;

    foreach ($tables as $table) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.$table);
    }

    foreach ($options as $option) {
        delete_option($option);
    }

    foreach ($meta as $key) {
        delete_post_meta_by_key($key);
    }
}

if (is_multisite()) {
    // Tables are created per site, so a network uninstall has to walk them all.
    $uams_mra_sites = get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    );

    foreach ($uams_mra_sites as $uams_mra_site_id) {
        switch_to_blog($uams_mra_site_id);
        uams_mra_uninstall_site($uams_mra_tables, $uams_mra_options, $uams_mra_meta);
        restore_current_blog();
    }
} else {
    uams_mra_uninstall_site($uams_mra_tables, $uams_mra_options, $uams_mra_meta);
}
