<?php

namespace CalendlyToFormidableBridge\Support;

/**
 * Debug logger and sync log writer.
 */
class Logger {
	const OPTION_KEY = 'ctfb_settings';

	/**
	 * Write a debug message when enabled.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function debug( $message, $context = array() ) {
		$settings = get_option( self::OPTION_KEY, array() );
		$enabled  = ! empty( $settings['debug_logging'] );

		if ( ! $enabled ) {
			return;
		}

		$this->write_to_error_log( $message, $context );
	}

	/**
	 * Write to PHP error log.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function write_to_error_log( $message, $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$line = '[CTFB] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $this->sanitize_context( $context ) );
		}

		error_log( $line );
	}

	/**
	 * Store sync attempt in db table.
	 *
	 * @param array $data Log data.
	 * @return void
	 */
	public function log_sync_attempt( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ctfb_logs';
		$row   = array(
			'created_at'      => current_time( 'mysql', 1 ),
			'event_type'      => isset( $data['event_type'] ) ? sanitize_text_field( $data['event_type'] ) : '',
			'invitee_email'   => isset( $data['invitee_email'] ) ? sanitize_email( $data['invitee_email'] ) : '',
			'action_taken'    => isset( $data['action_taken'] ) ? sanitize_text_field( $data['action_taken'] ) : '',
			'failure_reason'  => isset( $data['failure_reason'] ) ? sanitize_text_field( $data['failure_reason'] ) : '',
			'event_uuid'      => isset( $data['event_uuid'] ) ? sanitize_text_field( $data['event_uuid'] ) : '',
		);

		$wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );

		update_option( 'ctfb_last_sync_info', array(
			'last_success_at'   => ( isset( $data['action_taken'] ) && false !== strpos( $data['action_taken'], 'created' ) ) || ( isset( $data['action_taken'] ) && false !== strpos( $data['action_taken'], 'updated' ) ) ? current_time( 'mysql' ) : get_option( 'ctfb_last_sync_info', array() )['last_success_at'] ?? '',
			'last_error'        => isset( $data['failure_reason'] ) ? sanitize_text_field( $data['failure_reason'] ) : '',
			'last_email'        => isset( $data['invitee_email'] ) ? sanitize_email( $data['invitee_email'] ) : '',
		) );
	}

	/**
	 * Hide sensitive values.
	 *
	 * @param array $context Data.
	 * @return array
	 */
	private function sanitize_context( $context ) {
		$sanitized = array();
		foreach ( $context as $key => $value ) {
			if ( false !== stripos( (string) $key, 'token' ) || false !== stripos( (string) $key, 'signing' ) ) {
				$sanitized[ $key ] = '***';
				continue;
			}
			$sanitized[ $key ] = is_scalar( $value ) ? $value : wp_json_encode( $value );
		}
		return $sanitized;
	}
}
