<?php

namespace CalendlyToFormidableBridge\API;

use CalendlyToFormidableBridge\Support\Logger;
use CalendlyToFormidableBridge\Sync\Formidable_Sync;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST webhook endpoint.
 */
class Webhook_Controller {
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'ctfb/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle webhook request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$raw      = (string) $request->get_body();
		$payload  = json_decode( $raw, true );
		$settings = get_option( 'ctfb_settings', array() );

		if ( ! is_array( $payload ) ) {
			$this->logger->debug( 'Invalid JSON payload rejected.' );
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid JSON payload.' ), 400 );
		}

		if ( ! empty( $settings['webhook_signing_key'] ) ) {
			$sig = (string) $request->get_header( 'calendly-webhook-signature' );
			if ( ! $this->verify_signature( $sig, $raw, $settings['webhook_signing_key'] ) ) {
				$this->logger->debug( 'Webhook signature verification failed.' );
				return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid signature.' ), 401 );
			}
		}

		if ( empty( $settings['enable_sync'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'Sync is disabled.' ), 202 );
		}

		$sync   = new Formidable_Sync( $this->logger );
		$result = $sync->process_event( $payload, true );
		$code   = $result['success'] ? 200 : 422;

		return new WP_REST_Response( array( 'ok' => (bool) $result['success'], 'action' => $result['action'], 'reason' => $result['reason'] ), $code );
	}

	/**
	 * Verify Calendly signature.
	 *
	 * @param string $header Header.
	 * @param string $body Body.
	 * @param string $secret Secret.
	 * @return bool
	 */
	private function verify_signature( $header, $body, $secret ) {
		if ( '' === trim( $header ) ) {
			return false;
		}
		$parts = explode( ',', $header );
		$data  = array();
		foreach ( $parts as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 === count( $pair ) ) {
				$data[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}
		if ( empty( $data['t'] ) || empty( $data['v1'] ) ) {
			return false;
		}
		$signed   = $data['t'] . '.' . $body;
		$expected = hash_hmac( 'sha256', $signed, $secret );
		return hash_equals( $expected, $data['v1'] );
	}
}
