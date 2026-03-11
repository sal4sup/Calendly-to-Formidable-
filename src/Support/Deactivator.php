<?php

namespace CTFB\Support;

class Deactivator {
	public static function deactivate() {
		delete_transient( 'ctfb_recent_bookings' );
	}
}
