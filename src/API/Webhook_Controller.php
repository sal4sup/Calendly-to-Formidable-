<?php

namespace CTFB\API;

use CTFB\Support\Logger;
use CTFB\Sync\Formidable_Sync;

class Webhook_Controller {
	public function register_routes() {
		register_rest_route(
			'ctfb/v1',
			'/webhook',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'ctfb/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function ping() {
		Logger::info( 'ping_request_received', array(), true );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Routes are registered',
			),
			200
		);
	}

	public function handle_get( \WP_REST_Request $request ) {
		Logger::info(
			'webhook_get_accessed',
			array(
				'uri'    => $request->get_route(),
				'source' => $this->detect_request_source(),
			),
			true
		);
		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Webhook endpoint exists. Use POST requests for webhook delivery.',
			),
			200
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$options      = get_option( 'ctfb_options', array() );
		$sync_enabled = ! empty( $options['enabled'] );
		$raw          = $request->get_body();
		$raw_size     = strlen( (string) $raw );
		$content_type = $request->get_header( 'content-type' );
		$source       = $this->detect_request_source( $request );

		$this->register_fatal_shutdown_handler();

		Logger::info(
			'webhook_request_received',
			array(
				'method'       => $request->get_method(),
				'uri'          => $request->get_route(),
				'content_type' => $content_type,
				'raw_size'     => $raw_size,
				'body_empty'   => empty( $raw ) ? 'yes' : 'no',
				'source'       => $source,
			),
			true
		);

		if ( ! empty( $raw ) ) {
			update_option( 'ctfb_last_live_webhook_payload_raw', $raw, false );
		}
		Logger::log_raw_payload( $raw );

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			Logger::error( 'payload_structure_invalid', array( 'json_decode' => 'failed', 'source' => $source ) );
			return new \WP_REST_Response( array( 'message' => 'Malformed JSON.' ), 400 );
		}

		$payload    = ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) ? $data['payload'] : array();
		$event_type = isset( $data['event'] ) ? sanitize_text_field( $data['event'] ) : '';
		$email      = isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		$scheduled_event = isset( $payload['scheduled_event'] ) && is_array( $payload['scheduled_event'] ) ? $payload['scheduled_event'] : array();
		$webhook_event_uri = isset( $scheduled_event['uri'] ) ? esc_url_raw( (string) $scheduled_event['uri'] ) : '';
		$webhook_event_name = isset( $scheduled_event['name'] ) ? sanitize_text_field( (string) $scheduled_event['name'] ) : '';
		$webhook_event_type_uri = isset( $scheduled_event['event_type'] ) ? esc_url_raw( (string) $scheduled_event['event_type'] ) : '';
		$host_assignment = isset( $payload['assigned_to'] ) ? $payload['assigned_to'] : '';
		$scope_mode_used = isset( $options['webhook_scope_mode'] ) ? sanitize_text_field( (string) $options['webhook_scope_mode'] ) : ( isset( $options['webhook_scope'] ) ? sanitize_text_field( (string) $options['webhook_scope'] ) : 'user' );
		Logger::info( 'incoming_webhook_event_metadata', array(
			'webhook_event_name' => $webhook_event_name,
			'webhook_event_uri' => $webhook_event_uri,
			'webhook_event_type_uri' => $webhook_event_type_uri,
			'webhook_scope_mode_used' => $scope_mode_used,
			'host_assignment' => is_array( $host_assignment ) ? wp_json_encode( $host_assignment ) : sanitize_text_field( (string) $host_assignment ),
		), true );


		$pooling_type = isset( $scheduled_event['pooling_type'] ) ? strtolower( sanitize_text_field( (string) $scheduled_event['pooling_type'] ) ) : '';
		if ( in_array( $pooling_type, array( 'round_robin', 'collective' ), true ) ) {
			$diag = get_option( 'ctfb_diagnostics', array() );
			if ( ! is_array( $diag ) ) {
				$diag = array();
			}
			$diag['last_shared_team_webhook_received_time'] = current_time( 'mysql' );
			update_option( 'ctfb_diagnostics', $diag );
		}

		if ( ! $sync_enabled ) {
			Logger::warning( 'webhook_processing_stopped', array( 'reason' => 'sync_disabled' ) );
			return new \WP_REST_Response( array( 'message' => 'Sync is disabled.' ), 422 );
		}

		try {
			$sync   = new Formidable_Sync();
			$result = $sync->process( $data, true, $source );

			if ( empty( $result['ok'] ) ) {
				update_option( 'ctfb_last_error', $result['message'] );
				Logger::error( 'webhook_processing_failed', array( 'reason' => $result['message'] ) );
				return new \WP_REST_Response( array( 'message' => $result['message'] ), 422 );
			}

			if ( in_array( $pooling_type, array( 'round_robin', 'collective' ), true ) ) {
				$diag = get_option( 'ctfb_diagnostics', array() );
				if ( ! is_array( $diag ) ) {
					$diag = array();
				}
				$diag['last_shared_team_webhook_processed_time'] = current_time( 'mysql' );
				update_option( 'ctfb_diagnostics', $diag );
			}
			Logger::info( 'formidable_create_completed', array( 'entry_id' => isset( $result['entry_id'] ) ? $result['entry_id'] : 0 ), true );
			return new \WP_REST_Response( array( 'message' => $result['message'] ), 200 );
		} catch ( \Throwable $e ) {
			$this->log_throwable( $e );
			$this->write_failure_row( $email, $event_type, $e->getMessage() );
			return new \WP_REST_Response(
				array(
					'message' => 'Webhook runtime error.',
					'error'   => $e->getMessage(),
				),
				500
			);
		}
	}

	private function register_fatal_shutdown_handler() {
		register_shutdown_function(
			function () {
				$error = error_get_last();
				if ( empty( $error ) || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}

				Logger::error(
					'webhook_fatal_terminated',
					array(
						'fatal_type'    => isset( $error['type'] ) ? (string) $error['type'] : '',
						'fatal_message' => isset( $error['message'] ) ? $error['message'] : '',
						'fatal_file'    => isset( $error['file'] ) ? $error['file'] : '',
						'fatal_line'    => isset( $error['line'] ) ? (string) $error['line'] : '',
						'request_state' => 'fatal_terminated',
					)
				);

				$diag                                = get_option( 'ctfb_diagnostics', array() );
				$diag['last_fatal_processing_error'] = isset( $error['message'] ) ? sanitize_text_field( $error['message'] ) : '';
				$diag['last_fatal_processing_time']  = current_time( 'mysql' );
				update_option( 'ctfb_diagnostics', $diag );
			}
		);
	}

	private function write_failure_row( $email, $event_type, $reason ) {
		Logger::error(
			'webhook_failure_row',
			array(
				'invitee_email' => $email,
				'event_type'    => $event_type,
				'reason'        => $reason,
			)
		);
	}

	private function log_throwable( \Throwable $e ) {
		$trace = $e->getTraceAsString();
		if ( strlen( $trace ) > 1000 ) {
			$trace = substr( $trace, 0, 1000 ) . '...';
		}
		Logger::error(
			'webhook_throwable_caught',
			array(
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
				'trace'   => $trace,
			)
		);

		$diag                           = get_option( 'ctfb_diagnostics', array() );
		$diag['last_throwable_class']   = sanitize_text_field( get_class( $e ) );
		$diag['last_throwable_message'] = sanitize_text_field( $e->getMessage() );
		$diag['last_throwable_time']    = current_time( 'mysql' );
		update_option( 'ctfb_diagnostics', $diag );
	}

	private function detect_request_source( $request = null ) {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$remote_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( false !== stripos( $user_agent, 'calendly' ) ) {
			return 'external_webhook';
		}
		if ( false !== stripos( $user_agent, 'curl' ) || false !== stripos( $user_agent, 'postman' ) || false !== stripos( $user_agent, 'insomnia' ) ) {
			return 'manual_test';
		}
		if ( in_array( $remote_ip, array( '127.0.0.1', '::1' ), true ) ) {
			return 'manual_test';
		}
		if ( $request instanceof \WP_REST_Request && 'GET' === $request->get_method() ) {
			return 'manual_test';
		}
		return empty( $user_agent ) ? 'unknown' : 'external_webhook';
	}
}
