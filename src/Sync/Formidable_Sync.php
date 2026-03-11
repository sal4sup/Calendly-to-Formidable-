<?php

namespace CalendlyToFormidableBridge\Sync;

use CalendlyToFormidableBridge\Support\Logger;

/**
 * Sync payload to Formidable.
 */
class Formidable_Sync {
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Mapper.
	 *
	 * @var Field_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
		$this->mapper = new Field_Mapper();
	}

	/**
	 * Process a Calendly webhook payload.
	 *
	 * @param array $event Event payload.
	 * @param bool  $commit Whether to write.
	 * @return array
	 */
	public function process_event( $event, $commit = true ) {
		$event_type = isset( $event['event'] ) ? sanitize_text_field( $event['event'] ) : '';
		$payload    = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
		$email      = sanitize_email( isset( $payload['email'] ) ? $payload['email'] : '' );
		$uuid       = sanitize_text_field( isset( $event['event_uuid'] ) ? $event['event_uuid'] : '' );

		if ( ! $this->is_formidable_ready() ) {
			return $this->result( false, 'failed', 'Formidable Forms is not available or form/fields are missing.', 0, $email, $event_type, $uuid );
		}

		if ( 'invitee.canceled' === $event_type ) {
			return $this->handle_canceled( $event, $commit );
		}

		$settings = get_option( 'ctfb_settings', array() );
		$mapped   = $this->mapper->map( $event, $settings );
		if ( empty( $mapped['23'] ) ) {
			return $this->result( false, 'failed', 'Invitee email is missing.', 0, $email, $event_type, $uuid );
		}

		$terms = $this->resolve_terms_option();
		if ( '' === $terms ) {
			return $this->result( false, 'failed', 'Terms field option not found.', 0, $email, $event_type, $uuid );
		}
		$mapped['26'] = $terms;

		$invitee_uri = isset( $payload['uri'] ) ? esc_url_raw( $payload['uri'] ) : '';
		$event_uri   = isset( $payload['scheduled_event'] ) ? esc_url_raw( $payload['scheduled_event'] ) : '';
		$hash        = hash( 'sha256', wp_json_encode( $payload ) );

		$existing = $this->find_mapping( $invitee_uri, $event_uri, $uuid );

		if ( ! $commit ) {
			$action = empty( $existing ) ? 'would_create' : 'would_update';
			return $this->result( true, $action, '', 0, $email, $event_type, $uuid );
		}

		$entry_id = 0;
		$action   = 'created';

		if ( ! empty( $existing ) ) {
			$entry_id = (int) $existing['entry_id'];
			$update   = array(
				'id'         => $entry_id,
				'item_meta'  => $mapped,
			);
			$res = \FrmEntry::update( $update );
			if ( is_wp_error( $res ) || false === $res ) {
				return $this->result( false, 'failed', 'Failed to update existing Formidable entry.', $entry_id, $email, $event_type, $uuid );
			}
			$action = 'updated';
		} else {
			$args = array(
				'form_id'    => 4,
				'item_meta'  => $mapped,
			);
			$entry_id = \FrmEntry::create( $args );
			if ( ! is_numeric( $entry_id ) || $entry_id <= 0 ) {
				return $this->result( false, 'failed', 'Failed to create Formidable entry.', 0, $email, $event_type, $uuid );
			}
		}

		$this->save_mapping( $invitee_uri, $event_uri, $entry_id, 'active', $hash, $uuid );

		return $this->result( true, $action, '', $entry_id, $email, $event_type, $uuid );
	}

	/**
	 * Check dependencies.
	 *
	 * @return bool
	 */
	public function is_formidable_ready() {
		if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmForm' ) ) {
			return false;
		}
		$form = \FrmForm::getOne( 4 );
		if ( empty( $form ) ) {
			return false;
		}
		$required = array( 22, 23, 24, 26, 73 );
		foreach ( $required as $field_id ) {
			$field = \FrmField::getOne( $field_id );
			if ( empty( $field ) || (int) $field->form_id !== 4 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Handle cancel event.
	 *
	 * @param array $event Event.
	 * @param bool  $commit Commit.
	 * @return array
	 */
	private function handle_canceled( $event, $commit ) {
		$payload     = isset( $event['payload'] ) ? $event['payload'] : array();
		$invitee_uri = isset( $payload['uri'] ) ? esc_url_raw( $payload['uri'] ) : '';
		$event_uri   = isset( $payload['scheduled_event'] ) ? esc_url_raw( $payload['scheduled_event'] ) : '';
		$email       = sanitize_email( isset( $payload['email'] ) ? $payload['email'] : '' );
		$uuid        = sanitize_text_field( isset( $event['event_uuid'] ) ? $event['event_uuid'] : '' );
		$existing    = $this->find_mapping( $invitee_uri, $event_uri, $uuid );
		if ( empty( $existing ) ) {
			return $this->result( true, 'ignored', 'Cancel event has no known mapping.', 0, $email, 'invitee.canceled', $uuid );
		}
		if ( $commit ) {
			$this->save_mapping( $invitee_uri, $event_uri, (int) $existing['entry_id'], 'canceled', hash( 'sha256', wp_json_encode( $payload ) ), $uuid );
		}
		return $this->result( true, 'updated_status', '', (int) $existing['entry_id'], $email, 'invitee.canceled', $uuid );
	}

	/**
	 * Find internal mapping.
	 */
	private function find_mapping( $invitee_uri, $event_uri, $event_uuid ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ctfb_map';
		if ( '' !== $event_uuid ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_uuid = %s", $event_uuid ), ARRAY_A );
			if ( ! empty( $row ) ) {
				return $row;
			}
		}
		if ( '' !== $invitee_uri ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE invitee_uri = %s", $invitee_uri ), ARRAY_A );
			if ( ! empty( $row ) ) {
				return $row;
			}
		}
		if ( '' !== $event_uri ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_uri = %s", $event_uri ), ARRAY_A );
			if ( ! empty( $row ) ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * Save mapping.
	 */
	private function save_mapping( $invitee_uri, $event_uri, $entry_id, $status, $payload_hash, $event_uuid ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'ctfb_map';
		$existing = $this->find_mapping( $invitee_uri, $event_uri, $event_uuid );
		$data     = array(
			'invitee_uri'    => $invitee_uri,
			'event_uri'      => $event_uri,
			'entry_id'       => (int) $entry_id,
			'status'         => sanitize_text_field( $status ),
			'payload_hash'   => sanitize_text_field( $payload_hash ),
			'event_uuid'     => sanitize_text_field( $event_uuid ),
			'last_synced_at' => current_time( 'mysql', 1 ),
		);
		if ( empty( $existing ) ) {
			$wpdb->insert( $table, $data, array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		} else {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		}
	}

	/**
	 * Resolve terms checkbox option.
	 *
	 * @return string
	 */
	private function resolve_terms_option() {
		$default = 'I agree to Logitude’s <a href="https://logitudeworld.com/terms-and-conditions/">Тerms of use </a> and <a href="https://logitudeworld.com/privacy-policy/">Privacy Policy</a>';
		if ( ! class_exists( 'FrmField' ) ) {
			return $default;
		}
		$field = \FrmField::getOne( 26 );
		if ( empty( $field ) || empty( $field->options ) || ! is_array( $field->options ) ) {
			return $default;
		}
		if ( isset( $field->options['options'] ) && is_array( $field->options['options'] ) && ! empty( $field->options['options'][0] ) ) {
			return (string) $field->options['options'][0];
		}
		return $default;
	}

	/**
	 * Build result and log it.
	 */
	private function result( $success, $action, $reason, $entry_id, $email, $event_type, $event_uuid ) {
		$data = array(
			'success'      => $success,
			'action'       => $action,
			'reason'       => $reason,
			'entry_id'     => (int) $entry_id,
			'email'        => $email,
			'event_type'   => $event_type,
			'event_uuid'   => $event_uuid,
		);
		$this->logger->log_sync_attempt(
			array(
				'event_type'     => $event_type,
				'invitee_email'  => $email,
				'action_taken'   => $action,
				'failure_reason' => $reason,
				'event_uuid'     => $event_uuid,
			)
		);
		return $data;
	}
}
