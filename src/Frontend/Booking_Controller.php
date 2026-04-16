<?php

namespace CTFB\Frontend;

use CTFB\API\Calendly_Client;
use CTFB\Support\Logger;
use CTFB\Support\Token_Helper;

class Booking_Controller {

	const CACHE_TTL = 300; // 5 minutes

	public function register_routes() {
		register_rest_route(
			'ctfb/v1',
			'/booking/event-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_event_types' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ctfb/v1',
			'/booking/available-times',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_available_times' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event_type' => array(
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'start_date' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'ctfb/v1',
			'/booking/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function get_event_types( \WP_REST_Request $request ) {
		$options = get_option( 'ctfb_options', array() );
		$pat     = isset( $options['pat'] ) ? $options['pat'] : '';

		if ( empty( $pat ) ) {
			Logger::error( 'booking_event_types_failed', array( 'reason' => 'missing_pat' ) );
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Booking is not configured.' ),
				500
			);
		}

		$user_uri = isset( $options['webhook_user_uri'] ) ? $options['webhook_user_uri'] : '';
		if ( empty( $user_uri ) ) {
			$user_uri = Token_Helper::get_user_uri_from_pat( $pat );
		}
		if ( empty( $user_uri ) ) {
			Logger::error( 'booking_event_types_failed', array( 'reason' => 'missing_user_uri' ) );
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Booking is not configured. Please test the connection in the admin settings.' ),
				500
			);
		}

		$client = new Calendly_Client( $pat );
		$result = $client->get_event_types( $user_uri );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'booking_event_types_failed', array( 'error' => $result->get_error_message() ) );
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Could not load meeting types.' ),
				500
			);
		}

		$allowed    = $this->get_booking_event_types( $options );
		$collection = isset( $result['collection'] ) ? $result['collection'] : array();

		$event_types = array();
		foreach ( $collection as $et ) {
			$active = isset( $et['active'] ) ? $et['active'] : false;
			if ( ! $active ) {
				continue;
			}

			$uri = isset( $et['uri'] ) ? esc_url_raw( $et['uri'] ) : '';
			if ( ! empty( $allowed ) && ! in_array( $uri, $allowed, true ) ) {
				continue;
			}

			$event_types[] = array(
				'uri'      => $uri,
				'name'     => isset( $et['name'] ) ? sanitize_text_field( $et['name'] ) : '',
				'slug'     => isset( $et['slug'] ) ? sanitize_text_field( $et['slug'] ) : '',
				'duration' => isset( $et['duration'] ) ? absint( $et['duration'] ) : 0,
				'kind'     => isset( $et['kind'] ) ? sanitize_text_field( $et['kind'] ) : '',
			);
		}

		return new \WP_REST_Response(
			array( 'success' => true, 'event_types' => $event_types ),
			200
		);
	}

	private function get_booking_event_types( $options ) {
		$allowed = array();
		if ( empty( $options['booking_event_types'] ) || ! is_array( $options['booking_event_types'] ) ) {
			return $allowed;
		}
		foreach ( $options['booking_event_types'] as $uri ) {
			$clean = esc_url_raw( trim( (string) $uri ) );
			if ( '' !== $clean ) {
				$allowed[] = $clean;
			}
		}
		return array_values( array_unique( $allowed ) );
	}

	public function get_available_times( \WP_REST_Request $request ) {
		$options = get_option( 'ctfb_options', array() );
		$pat     = isset( $options['pat'] ) ? $options['pat'] : '';

		if ( empty( $pat ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Booking is not configured.' ),
				500
			);
		}

		$event_type = $request->get_param( 'event_type' );
		$start_date = $request->get_param( 'start_date' );

		if ( empty( $event_type ) || empty( $start_date ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Missing required parameters.' ),
				400
			);
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Invalid date format.' ),
				400
			);
		}

		$end_date = gmdate( 'Y-m-d', strtotime( $start_date . ' +7 days' ) );

		$response_data = self::fetch_available_times_cached( $pat, $event_type, $start_date, $end_date );
		$status        = ! empty( $response_data['success'] ) ? 200 : 500;

		return new \WP_REST_Response( $response_data, $status );
	}

	public static function fetch_available_times_cached( $pat, $event_type, $start_date, $end_date ) {
		$cache_key = 'ctfb_slots_' . md5( $event_type . $start_date . $end_date );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$start_time = $start_date . 'T00:00:00.000000Z';
		$end_time   = $end_date . 'T00:00:00.000000Z';

		$client = new Calendly_Client( $pat );
		$result = $client->get_event_type_available_times( $event_type, $start_time, $end_time );

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'booking_available_times_failed',
				array(
					'event_type' => $event_type,
					'error'      => $result->get_error_message(),
				)
			);
			return array(
				'success' => false,
				'message' => 'Could not load available times.',
				'slots'   => array(),
			);
		}

		$slots      = array();
		$collection = isset( $result['collection'] ) ? $result['collection'] : array();

		foreach ( $collection as $slot ) {
			$status = isset( $slot['status'] ) ? $slot['status'] : '';
			if ( 'available' !== $status ) {
				continue;
			}
			$slots[] = array(
				'start_time' => isset( $slot['start_time'] ) ? sanitize_text_field( $slot['start_time'] ) : '',
			);
		}

		$response_data = array(
			'success'    => true,
			'slots'      => $slots,
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);

		set_transient( $cache_key, $response_data, self::CACHE_TTL );

		return $response_data;
	}

	public function create_booking( \WP_REST_Request $request ) {
		$options = get_option( 'ctfb_options', array() );
		$pat     = isset( $options['pat'] ) ? $options['pat'] : '';

		if ( empty( $pat ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Booking is not configured.' ),
				500
			);
		}

		$body = $request->get_json_params();

		$event_type_uri = isset( $body['event_type'] ) ? esc_url_raw( $body['event_type'] ) : '';
		$start_time     = isset( $body['start_time'] ) ? sanitize_text_field( $body['start_time'] ) : '';
		$name           = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		$email          = isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '';
		$company        = isset( $body['company'] ) ? sanitize_text_field( $body['company'] ) : '';
		$phone          = isset( $body['phone'] ) ? sanitize_text_field( $body['phone'] ) : '';
		$country        = isset( $body['country'] ) ? sanitize_text_field( $body['country'] ) : '';
		$forwarder      = isset( $body['freight_forwarder'] ) ? sanitize_text_field( $body['freight_forwarder'] ) : 'No';
		$honeypot       = isset( $body['website'] ) ? $body['website'] : '';

		if ( ! empty( $honeypot ) ) {
			Logger::debug( 'booking_spam_detected', array( 'honeypot_filled' => 'yes' ) );
			return new \WP_REST_Response(
				array( 'success' => true, 'booking_url' => '' ),
				200
			);
		}

		$errors = array();
		if ( empty( $name ) ) {
			$errors[] = 'Name is required.';
		}
		if ( empty( $email ) || ! is_email( $email ) ) {
			$errors[] = 'A valid email is required.';
		}
		if ( empty( $event_type_uri ) ) {
			$errors[] = 'Meeting type is required.';
		}
		if ( empty( $start_time ) ) {
			$errors[] = 'Time slot is required.';
		}

		if ( ! empty( $errors ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => implode( ' ', $errors ) ),
				400
			);
		}

		$rate_key = 'ctfb_booking_' . md5( $email );
		$recent   = get_transient( $rate_key );
		if ( false !== $recent ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Please wait a moment before booking again.' ),
				429
			);
		}

		$client = new Calendly_Client( $pat );
		$result = $client->create_scheduling_link( $event_type_uri );

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'booking_create_failed',
				array(
					'email' => $email,
					'error' => $result->get_error_message(),
				)
			);
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Could not create booking link. Please try again.' ),
				500
			);
		}

		$booking_url = isset( $result['resource']['booking_url'] ) ? $result['resource']['booking_url'] : '';
		if ( empty( $booking_url ) ) {
			Logger::error( 'booking_create_failed', array( 'reason' => 'empty_booking_url' ) );
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => 'Could not create booking link.' ),
				500
			);
		}

		$date_part   = substr( $start_time, 0, 10 );
		$month_part  = substr( $start_time, 0, 7 );
		$query_args  = array(
			'name'  => $name,
			'email' => $email,
			'month' => $month_part,
			'date'  => $date_part,
			'a1'    => $company,
			'a2'    => $phone,
			'a3'    => $country,
			'a4'    => $forwarder,
		);
		$booking_url = add_query_arg( $query_args, $booking_url );

		set_transient( $rate_key, 1, 60 );

		Logger::debug(
			'booking_link_created',
			array(
				'email'      => $email,
				'event_type' => $event_type_uri,
				'start_time' => $start_time,
			)
		);

		return new \WP_REST_Response(
			array( 'success' => true, 'booking_url' => $booking_url ),
			200
		);
	}
}
