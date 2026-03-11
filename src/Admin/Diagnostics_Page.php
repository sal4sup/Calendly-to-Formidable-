<?php

namespace CTFB\Admin;

class Diagnostics_Page {
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'display_action_notice' ) );
	}

	public function display_action_notice() {
		if ( empty( $_GET['ctfb_notice'] ) ) {
			return;
		}
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( wp_unslash( $_GET['ctfb_notice'] ) ) . '</p></div>';
	}
}
