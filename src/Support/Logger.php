<?php

namespace CTFB\Support;

class Logger {
	public static function log( $event_type, $invitee_email, $action_taken, $failure_reason = '' ) {
		$options = get_option( 'ctfb_options', array() );
		if ( empty( $options['debug_logging'] ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ctfb_logs',
			array(
				'event_type'    => sanitize_text_field( $event_type ),
				'invitee_email' => sanitize_email( $invitee_email ),
				'action_taken'  => sanitize_text_field( $action_taken ),
				'failure_reason'=> sanitize_textarea_field( $failure_reason ),
				'created_at'    => current_time( 'mysql', 1 ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[CTFB] ' . $event_type . ' :: ' . $action_taken ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
