<?php
/**
 * Uninstall handler for Calendly to Formidable Bridge.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ctfb_mappings" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ctfb_logs" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'ctfb_options' );
delete_option( 'ctfb_diagnostics' );
delete_option( 'ctfb_activation_notice' );
