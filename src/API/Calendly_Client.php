<?php

namespace CalendlyToFormidableBridge\API;

use CalendlyToFormidableBridge\Support\Logger;

/**
 * Calendly API client.
 */
class Calendly_Client {
	/**
	 * API base.
	 */
	const API_BASE = 'https://api.calendly.com';

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
	 * Get current user resource.
	 *
	 * @param string $token PAT.
	 * @return array
	 */
	public function get_current_user( $token ) {
		return $this->request( 'GET', '/users/me', $token );
	}

	/**
	 * List webhook subscriptions.
	 *
	 * @param string $token PAT.
	 * @param string $scope Scope URI.
	 * @return array
	 */
	public function list_webhooks( $token, $scope ) {
		$endpoint = '/webhook_subscriptions?scope=' . rawurlencode( 'user' ) . '&organization=' . rawurlencode( $scope );
		return $this->request( 'GET', $endpoint, $token );
	}

	/**
	 * Create webhook.
	 *
	 * @param string $token PAT.
	 * @param string $url URL.
	 * @param string $scope Scope.
	 * @param string $scope_uri Scope URI.
	 * @return array
	 */
	public function create_webhook( $token, $url, $scope, $scope_uri ) {
		$body = array(
			'url'    => esc_url_raw( $url ),
			'events' => array( 'invitee.created', 'invitee.canceled' ),
			'scope'  => $scope,
		);
		if ( 'organization' === $scope ) {
			$body['organization'] = $scope_uri;
		}
		if ( 'user' === $scope ) {
			$body['user'] = $scope_uri;
		}
		return $this->request( 'POST', '/webhook_subscriptions', $token, $body );
	}

	/**
	 * Delete webhook.
	 *
	 * @param string $token PAT.
	 * @param string $subscription_uri Subscription URI.
	 * @return array
	 */
	public function delete_webhook( $token, $subscription_uri ) {
		$path = str_replace( self::API_BASE, '', $subscription_uri );
		return $this->request( 'DELETE', $path, $token );
	}

	/**
	 * Request helper.
	 *
	 * @param string $method Method.
	 * @param string $path Path.
	 * @param string $token PAT.
	 * @param array  $body Body.
	 * @return array
	 */
	private function request( $method, $path, $token, $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $token ),
				'Content-Type'  => 'application/json',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->debug( 'Calendly request failed', array( 'error' => $response->get_error_message() ) );
			return array( 'success' => false, 'message' => $response->get_error_message(), 'data' => array() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code < 200 || $code > 299 ) {
			$msg = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : 'Calendly API error';
			$this->logger->debug( 'Calendly API non-2xx response', array( 'status' => $code ) );
			return array( 'success' => false, 'message' => $msg, 'data' => $data );
		}

		return array( 'success' => true, 'message' => '', 'data' => $data );
	}
}
