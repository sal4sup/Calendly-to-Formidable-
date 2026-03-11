<?php

namespace CTFB;

use CTFB\Admin\Diagnostics_Page;
use CTFB\Admin\Settings_Page;
use CTFB\API\Webhook_Controller;

class Plugin {
	public function run() {
		$settings   = new Settings_Page();
		$diagnostic = new Diagnostics_Page();
		$webhook    = new Webhook_Controller();

		add_action( 'admin_menu', array( $settings, 'register_menu' ) );
		add_action( 'admin_init', array( $settings, 'register_settings' ) );
		add_action( 'admin_notices', array( $settings, 'activation_notice' ) );
		add_action( 'admin_post_ctfb_action', array( $settings, 'handle_actions' ) );
		add_action( 'rest_api_init', array( $webhook, 'register_routes' ) );
		add_action( 'admin_init', array( $diagnostic, 'register_hooks' ) );
	}
}
