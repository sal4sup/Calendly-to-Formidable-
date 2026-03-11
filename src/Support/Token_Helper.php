<?php

namespace CTFB\Support;

class Token_Helper {
	public static function decode_payload( $token ) {
		if ( empty( $token ) || ! is_string( $token ) ) {
			return array();
		}

		$parts = explode( '.', trim( $token ) );
		if ( count( $parts ) < 2 ) {
			return array();
		}

		$payload = strtr( $parts[1], '-_', '+/' );
		$padding = strlen( $payload ) % 4;
		if ( $padding > 0 ) {
			$payload .= str_repeat( '=', 4 - $padding );
		}
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return array();
		}
		$data = json_decode( $decoded, true );
		return is_array( $data ) ? $data : array();
	}

	public static function get_user_uuid_from_pat( $token ) {
		$payload = self::decode_payload( $token );
		if ( ! empty( $payload['user_uuid'] ) ) {
			return sanitize_text_field( $payload['user_uuid'] );
		}
		if ( ! empty( $payload['sub'] ) && preg_match( '/^[a-f0-9\-]{8,}$/i', $payload['sub'] ) ) {
			return sanitize_text_field( $payload['sub'] );
		}
		return '';
	}

	public static function get_user_uri_from_pat( $token ) {
		$uuid = self::get_user_uuid_from_pat( $token );
		return $uuid ? 'https://api.calendly.com/users/' . rawurlencode( $uuid ) : '';
	}

	public static function get_scopes_from_pat( $token ) {
		$payload = self::decode_payload( $token );
		if ( ! empty( $payload['scope'] ) && is_string( $payload['scope'] ) ) {
			return array_filter( array_map( 'trim', explode( ' ', $payload['scope'] ) ) );
		}
		if ( ! empty( $payload['scopes'] ) && is_array( $payload['scopes'] ) ) {
			return array_map( 'sanitize_text_field', $payload['scopes'] );
		}
		return array();
	}
}
