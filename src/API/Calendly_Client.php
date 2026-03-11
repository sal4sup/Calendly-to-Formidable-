<?php

namespace CTFB\API;

class Calendly_Client {
	const BASE_URL = 'https://api.calendly.com';

	private $token;

	public function __construct( $token ) {
		$this->token = trim( (string) $token );
	}

	public function get_users_me() {
		return $this->request( 'GET', '/users/me' );
	}

	public function create_webhook( $url, $scope, $scope_uri ) {
		$body = array(
			'url'    => esc_url_raw( $url ),
			'events' => array( 'invitee.created', 'invitee.canceled' ),
			'scope'  => $scope,
		);
		if ( ! empty( $scope_uri ) ) {
			$body['organization'] = $scope_uri;
			if ( 'user' === $scope ) {
				unset( $body['organization'] );
				$body['user'] = $scope_uri;
			}
		}
		return $this->request( 'POST', '/webhook_subscriptions', $body );
	}

	public function delete_webhook( $uri ) {
		$path = str_replace( self::BASE_URL, '', (string) $uri );
		return $this->request( 'DELETE', $path );
	}

	public function list_webhooks( $user_uri ) {
		return $this->request( 'GET', '/webhook_subscriptions?scope=user&user=' . rawurlencode( $user_uri ) );
	}

	public function get_scheduled_events( $user_uri, $count = 10 ) {
		$path = '/scheduled_events?user=' . rawurlencode( $user_uri ) . '&count=' . absint( $count ) . '&sort=start_time:desc';
		return $this->request( 'GET', $path );
	}

	public function get_event_invitees( $event_uri, $count = 1 ) {
		$path = '/scheduled_events/' . basename( untrailingslashit( $event_uri ) ) . '/invitees?count=' . absint( $count );
		return $this->request( 'GET', $path );
	}

	public function request( $method, $path, $body = array() ) {
		if ( empty( $this->token ) ) {
			return new \WP_Error( 'ctfb_missing_pat', 'Missing Personal Access Token.' );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code >= 400 ) {
			$msg = isset( $data['message'] ) ? $data['message'] : 'Calendly API request failed.';
			$err = new \WP_Error( 'ctfb_calendly_api_error', $msg, array( 'status' => $code, 'data' => $data ) );
			if ( false !== stripos( $msg, 'scope' ) || false !== stripos( $raw, 'scope' ) ) {
				$err->add_data( array( 'scope_related' => true ) );
			}
			return $err;
		}

		return is_array( $data ) ? $data : array();
	}
}
