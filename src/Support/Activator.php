<?php

namespace CalendlyToFormidableBridge\Support;

/**
 * Activation handler.
 */
class Activator {
	/**
	 * Activate plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$settings = get_option( 'ctfb_settings', array() );
		if ( empty( $settings ) ) {
			$settings = array(
				'enable_sync'                          => 0,
				'calendly_token'                       => '',
				'webhook_signing_key'                  => '',
				'debug_logging'                        => 0,
				'fallback_company_name'                => 'Not Provided',
				'fallback_freight_forwarder'           => 'No',
				'default_country'                      => '',
				'webhook_subscription_id'              => '',
				'webhook_scope'                        => '',
				'webhook_scope_uri'                    => '',
			);
			add_option( 'ctfb_settings', $settings );
		}

		$charset = $wpdb->get_charset_collate();
		$map_tbl = $wpdb->prefix . 'ctfb_map';
		$log_tbl = $wpdb->prefix . 'ctfb_logs';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$map_tbl} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invitee_uri text NOT NULL,
			event_uri text NOT NULL,
			entry_id bigint(20) unsigned NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'active',
			payload_hash varchar(64) NOT NULL DEFAULT '',
			event_uuid varchar(120) NOT NULL DEFAULT '',
			last_synced_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid(100)),
			KEY entry_id (entry_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$log_tbl} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			event_type varchar(120) NOT NULL DEFAULT '',
			invitee_email varchar(190) NOT NULL DEFAULT '',
			action_taken varchar(120) NOT NULL DEFAULT '',
			failure_reason text NOT NULL,
			event_uuid varchar(120) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};" );

		set_transient( 'ctfb_activation_notice', 1, 60 );
	}
}
