<?php
/**
 * Plugin Name: Calendly to Formidable Bridge
 * Plugin URI: https://logitudeworld.com
 * Description: Sync Calendly bookings into Formidable Forms using a Calendly Personal Access Token.
 * Version: 1.0.0
 * Author: Saleem Summour
 * Author URI: https://logitudeworld.com
 * Text Domain: calendly-to-formidable-bridge
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CTFB_PLUGIN_FILE', __FILE__ );
define( 'CTFB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTFB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CTFB_VERSION', '1.0.0' );

spl_autoload_register(
	function ( $class ) {
		$prefix   = 'CTFB\\';
		$base_dir = CTFB_PLUGIN_DIR . 'src/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'CTFB\\Support\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CTFB\\Support\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		$plugin = new CTFB\Plugin();
		$plugin->run();
	}
);
