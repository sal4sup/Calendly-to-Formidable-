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
			)
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$raw  = $request->get_body();
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			Logger::log( 'webhook', '', 'malformed_json', 'Malformed webhook JSON payload.' );
			return new \WP_REST_Response( array( 'message' => 'Malformed JSON.' ), 400 );
		}

		$sync   = new Formidable_Sync();
		$result = $sync->process( $data, true );
		if ( empty( $result['ok'] ) ) {
			update_option( 'ctfb_last_error', $result['message'] );
			return new \WP_REST_Response( array( 'message' => $result['message'] ), 422 );
		}

		return new \WP_REST_Response( array( 'message' => $result['message'] ), 200 );
	}
}
