<?php
/**
 * Plugin uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ctfb_settings' );
delete_option( 'ctfb_last_sync_info' );

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ctfb_map' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ctfb_logs' );
