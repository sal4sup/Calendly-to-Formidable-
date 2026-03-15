<?php

namespace CTFB\Support;

class Installer {
	public static function default_options() {
		return array(
			'enabled'                   => 1,
			'pat'                       => '',
			'debug_logging'             => 0,
			'fallback_company_name'     => '',
			'fallback_freight_forwarder'=> 'No',
			'default_country'           => '',
			'webhook_subscription_uri'  => '',
			'webhook_scope'             => 'user',
			'webhook_scope_uri'         => '',
			'webhook_user_uri'          => '',
			'webhook_organization_uri'  => '',
			'webhook_scope_mode'        => 'user',
			'webhook_user_active'       => 0,
			'webhook_organization_active' => 0,
			'webhook_user_subscription_uri' => '',
			'webhook_organization_subscription_uri' => '',
		);
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$mappings = "CREATE TABLE {$wpdb->prefix}ctfb_mappings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			calendly_invitee_uri varchar(255) NOT NULL,
			calendly_event_uri varchar(255) NOT NULL,
			calendly_status varchar(32) NOT NULL,
			payload_hash varchar(64) NOT NULL,
			formidable_entry_id bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY calendly_invitee_uri (calendly_invitee_uri)
		) {$charset_collate};";

		$logs = "CREATE TABLE {$wpdb->prefix}ctfb_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(64) NOT NULL,
			invitee_email varchar(190) NOT NULL,
			action_taken varchar(190) NOT NULL,
			failure_reason text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		dbDelta( $mappings );
		dbDelta( $logs );
	}
}
