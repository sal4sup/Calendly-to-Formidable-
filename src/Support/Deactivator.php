<?php

namespace CalendlyToFormidableBridge\Support;

/**
 * Deactivation handler.
 */
class Deactivator {
	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'ctfb_activation_notice' );
	}
}
