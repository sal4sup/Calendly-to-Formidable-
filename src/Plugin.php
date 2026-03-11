<?php

namespace CalendlyToFormidableBridge;

use CalendlyToFormidableBridge\Admin\Diagnostics_Page;
use CalendlyToFormidableBridge\Admin\Settings_Page;
use CalendlyToFormidableBridge\API\Webhook_Controller;
use CalendlyToFormidableBridge\Support\Logger;

/**
 * Main plugin bootstrap class.
 */
class Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public function run() {
		$logger              = new Logger();
		$settings_page       = new Settings_Page( $logger );
		$diagnostics_page    = new Diagnostics_Page( $logger );
		$webhook_controller  = new Webhook_Controller( $logger );

		add_action( 'admin_menu', array( $settings_page, 'register_menu' ) );
		add_action( 'admin_init', array( $settings_page, 'register_settings' ) );
		add_action( 'admin_post_ctfb_create_webhook', array( $settings_page, 'handle_create_webhook' ) );
		add_action( 'admin_post_ctfb_refresh_webhook', array( $settings_page, 'handle_refresh_webhook' ) );
		add_action( 'admin_post_ctfb_delete_webhook', array( $settings_page, 'handle_delete_webhook' ) );
		add_action( 'admin_post_ctfb_test_payload', array( $diagnostics_page, 'handle_test_payload' ) );
		add_action( 'rest_api_init', array( $webhook_controller, 'register_routes' ) );
		add_action( 'admin_notices', array( $settings_page, 'activation_notice' ) );
	}
}
