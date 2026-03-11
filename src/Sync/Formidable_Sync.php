<?php

namespace CTFB\Sync;

use CTFB\Support\Logger;

class Formidable_Sync {
	private $mapper;

	public function __construct() {
		$this->mapper = new Field_Mapper();
	}

	public function process( $payload, $commit = true ) {
		$options = get_option( 'ctfb_options', array() );
		if ( empty( $options['enabled'] ) ) {
			return array( 'ok' => false, 'message' => 'Sync is disabled.' );
		}
		if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmForm' ) ) {
			return array( 'ok' => false, 'message' => 'Formidable is not active.' );
		}
		$form = \FrmForm::getOne( 4 );
		if ( ! $form ) {
			return array( 'ok' => false, 'message' => 'Form ID 4 was not found.' );
		}

		$event_type = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';
		$resource   = isset( $payload['payload'] ) ? $payload['payload'] : array();
		$invitee_uri= isset( $resource['uri'] ) ? esc_url_raw( $resource['uri'] ) : '';
		$event_uri  = isset( $resource['scheduled_event'] ) ? esc_url_raw( $resource['scheduled_event'] ) : '';
		$email      = isset( $resource['email'] ) ? sanitize_email( $resource['email'] ) : '';
		$hash       = hash( 'sha256', wp_json_encode( $payload ) );

		if ( 'invitee.canceled' === $event_type ) {
			return $this->handle_cancel( $invitee_uri, $hash, $email );
		}

		if ( empty( $email ) ) {
			return array( 'ok' => false, 'message' => 'Required email is missing.' );
		}

		$existing = $this->find_mapping( $invitee_uri );
		if ( $existing && $existing->payload_hash === $hash ) {
			Logger::log( $event_type, $email, 'duplicate_skipped', 'Duplicate webhook payload.' );
			return array( 'ok' => true, 'message' => 'Duplicate webhook ignored.' );
		}

		$mapped = $this->mapper->map_fields( $payload, $options );
		if ( empty( $mapped['email'] ) ) {
			return array( 'ok' => false, 'message' => 'Required email is missing.' );
		}

		if ( ! $commit ) {
			return array( 'ok' => true, 'message' => 'Dry run successful.', 'mapped' => $mapped['fields'] );
		}

		$entry_id = $existing ? absint( $existing->formidable_entry_id ) : 0;
		if ( $entry_id > 0 && class_exists( 'FrmEntry' ) ) {
			\FrmEntry::update( $entry_id, array( 'item_meta' => $mapped['fields'] ) );
		} else {
			$entry_id = \FrmEntry::create(
				array(
					'form_id'   => 4,
					'item_meta' => $mapped['fields'],
				)
			);
		}

		$this->upsert_mapping( $invitee_uri, $event_uri, 'active', $hash, $entry_id );
		update_option( 'ctfb_last_successful_sync', current_time( 'mysql' ) );
		update_option( 'ctfb_last_processed_email', $email );
		Logger::log( $event_type, $email, 'entry_synced', '' );

		return array( 'ok' => true, 'message' => 'Entry synced successfully.', 'entry_id' => $entry_id );
	}

	private function handle_cancel( $invitee_uri, $hash, $email ) {
		$existing = $this->find_mapping( $invitee_uri );
		if ( ! $existing ) {
			Logger::log( 'invitee.canceled', $email, 'canceled_ignored', 'No existing mapping found.' );
			return array( 'ok' => true, 'message' => 'Canceled event ignored: no linked mapping.' );
		}
		$this->upsert_mapping( $invitee_uri, $existing->calendly_event_uri, 'canceled', $hash, $existing->formidable_entry_id );
		return array( 'ok' => true, 'message' => 'Booking status updated to canceled.' );
	}

	private function find_mapping( $invitee_uri ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ctfb_mappings WHERE calendly_invitee_uri = %s LIMIT 1", $invitee_uri ) );
	}

	private function upsert_mapping( $invitee_uri, $event_uri, $status, $hash, $entry_id ) {
		global $wpdb;
		$existing = $this->find_mapping( $invitee_uri );
		$data     = array(
			'calendly_invitee_uri' => $invitee_uri,
			'calendly_event_uri'   => $event_uri,
			'calendly_status'      => $status,
			'payload_hash'         => $hash,
			'formidable_entry_id'  => absint( $entry_id ),
			'updated_at'           => current_time( 'mysql', 1 ),
		);
		if ( $existing ) {
			$wpdb->update( $wpdb->prefix . 'ctfb_mappings', $data, array( 'id' => $existing->id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', 1 );
			$wpdb->insert( $wpdb->prefix . 'ctfb_mappings', $data );
		}
	}
}
