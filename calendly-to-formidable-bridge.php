<?php
/**
 * Plugin Name: Calendly to Formidable Bridge
 * Description: Sync Calendly invitees into Formidable Forms entries.
 * Version: 1.0.0
 * Author: Saleem Summour
 * Company: Logitudeworld
 * Text Domain: calendly-to-formidable-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CTFB_PLUGIN_VERSION', '1.0.0' );
define( 'CTFB_PLUGIN_FILE', __FILE__ );
define( 'CTFB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CTFB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTFB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		$prefix   = 'CalendlyToFormidableBridge\\';
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

register_activation_hook( __FILE__, array( 'CalendlyToFormidableBridge\\Support\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CalendlyToFormidableBridge\\Support\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		$plugin = new CalendlyToFormidableBridge\Plugin();
		$plugin->run();
	}
);
