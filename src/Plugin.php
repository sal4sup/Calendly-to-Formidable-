<?php

namespace CTFB;

use CTFB\Admin\Diagnostics_Page;
use CTFB\Admin\Settings_Page;
use CTFB\API\Webhook_Controller;
use CTFB\Support\Logger;

class Plugin {
	public function run() {
		Logger::info( 'plugin_bootstrap_started' );

		$options = get_option( 'ctfb_options', array() );
		Logger::debug(
			'settings_loaded',
			array(
				'enabled'       => ! empty( $options['enabled'] ) ? '1' : '0',
				'debug_logging' => ! empty( $options['debug_logging'] ) ? '1' : '0',
			)
		);

		$settings   = new Settings_Page();
		$diagnostic = new Diagnostics_Page();
		$webhook    = new Webhook_Controller();
		Logger::debug( 'webhook_controller_initialized' );
		Logger::debug( 'admin_pages_initialized' );

		add_action( 'admin_menu', array( $settings, 'register_menu' ) );
		add_action( 'admin_init', array( $settings, 'register_settings' ) );
		add_action( 'admin_notices', array( $settings, 'activation_notice' ) );
		add_action( 'rest_api_init', array( $webhook, 'register_routes' ) );
		add_action( 'admin_init', array( $diagnostic, 'register_hooks' ) );

		add_action( 'admin_post_ctfb_test_connection', array( $settings, 'handle_test_connection_action' ) );
		add_action( 'admin_post_ctfb_create_webhook', array( $settings, 'handle_create_webhook_action' ) );
		add_action( 'admin_post_ctfb_refresh_webhook', array( $settings, 'handle_refresh_webhook_action' ) );
		add_action( 'admin_post_ctfb_delete_webhook', array( $settings, 'handle_delete_webhook_action' ) );
		add_action( 'admin_post_ctfb_manual_test', array( $settings, 'handle_manual_test_action' ) );
		add_action( 'admin_post_ctfb_clear_log', array( $settings, 'handle_clear_log_action' ) );
		add_action( 'admin_post_ctfb_download_log', array( $settings, 'handle_download_log_action' ) );
		add_action( 'admin_post_ctfb_test_create_webhook_handler', array( $settings, 'handle_test_create_webhook_handler_action' ) );
		add_action( 'admin_post_ctfb_preview_create_webhook_payload', array( $settings, 'handle_preview_create_webhook_payload_action' ) );

		$hooks = array(
			'create'  => has_action( 'admin_post_ctfb_create_webhook', array( $settings, 'handle_create_webhook_action' ) ) ? 'yes' : 'no',
			'refresh' => has_action( 'admin_post_ctfb_refresh_webhook', array( $settings, 'handle_refresh_webhook_action' ) ) ? 'yes' : 'no',
			'delete'  => has_action( 'admin_post_ctfb_delete_webhook', array( $settings, 'handle_delete_webhook_action' ) ) ? 'yes' : 'no',
			'test'    => has_action( 'admin_post_ctfb_test_connection', array( $settings, 'handle_test_connection_action' ) ) ? 'yes' : 'no',
		);
		update_option( 'ctfb_admin_action_hook_status', $hooks );
		Logger::info( 'admin_action_hook_registered', array( 'hook' => 'admin_post_ctfb_create_webhook', 'registered' => $hooks['create'] ) );
		Logger::info( 'admin_action_hook_registered', array( 'hook' => 'admin_post_ctfb_refresh_webhook', 'registered' => $hooks['refresh'] ) );
		Logger::info( 'admin_action_hook_registered', array( 'hook' => 'admin_post_ctfb_delete_webhook', 'registered' => $hooks['delete'] ) );
		Logger::info( 'admin_action_hook_registered', array( 'hook' => 'admin_post_ctfb_test_connection', 'registered' => $hooks['test'] ) );

		Logger::info( 'plugin_bootstrap_completed' );
	}
}
