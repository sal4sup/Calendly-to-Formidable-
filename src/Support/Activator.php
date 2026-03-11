<?php

namespace CTFB\Support;

class Activator {
	public static function activate() {
		Installer::create_tables();
		if ( ! get_option( 'ctfb_options' ) ) {
			add_option( 'ctfb_options', Installer::default_options() );
		}
		update_option( 'ctfb_activation_notice', 1 );
	}
}
