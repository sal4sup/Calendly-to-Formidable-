<?php

namespace CTFB\API;

use CTFB\Support\Logger;
use CTFB\Sync\Formidable_Sync;

class Webhook_Controller {
	public function register_routes() {
		Logger::debug( 'rest_api_init_triggered', array( 'namespace' => 'ctfb/v1' ) );

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
		Logger::info( 'webhook_route_registered', array( 'namespace' => 'ctfb/v1', 'route' => '/webhook' ) );

		register_rest_route(
			'ctfb/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => '__return_true',
			)
		);
		Logger::info( 'ping_route_registered', array( 'namespace' => 'ctfb/v1', 'route' => '/ping' ) );
	}

	public function ping() {
		Logger::info( 'ping_request_received' );
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
			)
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

		Logger::info(
			'webhook_request_received',
			array(
				'method'       => $request->get_method(),
				'uri'          => $request->get_route(),
				'content_type' => $content_type,
				'raw_size'     => $raw_size,
				'body_empty'   => empty( $raw ) ? 'yes' : 'no',
				'source'       => $source,
			)
		);

		if ( ! empty( $raw ) ) {
			Logger::debug( 'webhook_payload_read', array( 'bytes' => $raw_size ) );
		}
		Logger::log_raw_payload( $raw );

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			Logger::error( 'payload_structure_invalid', array( 'json_decode' => 'failed', 'source' => $source ) );
			Logger::info( 'webhook_response_sent', array( 'status' => 400, 'message' => 'Malformed JSON' ) );
			return new \WP_REST_Response( array( 'message' => 'Malformed JSON.' ), 400 );
		}

		$event_type = isset( $data['event'] ) ? sanitize_text_field( $data['event'] ) : '';
		$email      = isset( $data['payload']['email'] ) ? sanitize_email( $data['payload']['email'] ) : '';
		Logger::debug( 'payload_root_keys', array( 'keys' => implode( ',', array_keys( $data ) ) ) );
		Logger::debug( 'payload_structure_valid', array( 'available_payload_keys' => isset( $data['payload'] ) && is_array( $data['payload'] ) ? implode( ',', array_keys( $data['payload'] ) ) : '' ) );
		Logger::debug( 'event_type_detected', array( 'event' => $event_type ) );
		Logger::debug( 'invitee_email_detected', array( 'invitee_email' => $email ) );
		Logger::debug(
			'payload_uris_detected',
			array(
				'scheduled_event_uri' => isset( $data['payload']['scheduled_event'] ) ? $data['payload']['scheduled_event'] : '',
				'invitee_uri'         => isset( $data['payload']['uri'] ) ? $data['payload']['uri'] : '',
			)
		);
		Logger::debug( 'sync_state_checked', array( 'sync_enabled' => $sync_enabled ? 'yes' : 'no' ) );

		if ( ! $sync_enabled ) {
			Logger::warning( 'webhook_processing_stopped', array( 'reason' => 'sync_disabled' ) );
			Logger::info( 'webhook_response_sent', array( 'status' => 422, 'message' => 'Sync is disabled.' ) );
			return new \WP_REST_Response( array( 'message' => 'Sync is disabled.' ), 422 );
		}

		Logger::debug( 'webhook_processing_continues', array( 'source' => $source ) );
		$sync   = new Formidable_Sync();
		$result = $sync->process( $data, true, $source );

		if ( empty( $result['ok'] ) ) {
			update_option( 'ctfb_last_error', $result['message'] );
			Logger::error( 'webhook_processing_failed', array( 'reason' => $result['message'] ) );
			Logger::info( 'webhook_response_sent', array( 'status' => 422, 'message' => $result['message'] ) );
			return new \WP_REST_Response( array( 'message' => $result['message'] ), 422 );
		}

		Logger::info( 'webhook_response_sent', array( 'status' => 200, 'message' => $result['message'] ) );
		return new \WP_REST_Response( array( 'message' => $result['message'] ), 200 );
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
