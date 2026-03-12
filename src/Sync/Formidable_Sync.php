<?php

namespace CTFB\Sync;

use CTFB\Support\Logger;

class Formidable_Sync {
	private $mapper;

	public function __construct() {
		$this->mapper = new Field_Mapper();
	}

	public function process( $payload, $commit = true, $source = 'unknown' ) {
		$options = get_option( 'ctfb_options', array() );
		Logger::debug( 'validation_started', array( 'source' => $source, 'commit' => $commit ? 'yes' : 'no' ) );
		if ( ! empty( $source ) && false !== strpos( $source, 'manual' ) ) {
			Logger::info( 'manual_test_flow', array( 'commit_to_database' => $commit ? 'yes' : 'no' ) );
		}

		if ( empty( $options['enabled'] ) ) {
			Logger::warning( 'webhook_processing_stopped', array( 'reason' => 'sync_disabled' ) );
			return array( 'ok' => false, 'message' => 'Sync is disabled.' );
		}

		$formidable_ready = class_exists( 'FrmEntry' ) && class_exists( 'FrmForm' );
		Logger::debug( 'formidable_dependency_status', array( 'available' => $formidable_ready ? 'yes' : 'no' ) );
		if ( ! $formidable_ready ) {
			return array( 'ok' => false, 'message' => 'Formidable is not active.' );
		}

		$form = \FrmForm::getOne( 4 );
		Logger::debug( 'target_form_existence', array( 'form_id' => 4, 'exists' => $form ? 'yes' : 'no' ) );
		if ( ! $form ) {
			return array( 'ok' => false, 'message' => 'Form ID 4 was not found.' );
		}

		$event_type  = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';
		$resource    = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();
		$invitee_uri = isset( $resource['uri'] ) ? esc_url_raw( $resource['uri'] ) : '';
		$event_uri   = isset( $resource['scheduled_event'] ) ? esc_url_raw( $resource['scheduled_event'] ) : '';
		$email       = isset( $resource['email'] ) ? sanitize_email( $resource['email'] ) : '';
		$hash        = hash( 'sha256', wp_json_encode( $payload ) );

		Logger::debug( 'payload_parsing_started', array( 'event' => $event_type ) );
		if ( 'invitee.canceled' === $event_type ) {
			return $this->handle_cancel( $invitee_uri, $hash, $email );
		}

		if ( empty( $email ) ) {
			Logger::warning( 'missing_required_email' );
			return array( 'ok' => false, 'message' => 'Required email is missing.' );
		}

		Logger::debug( 'duplicate_check_started', array( 'invitee_uri' => $invitee_uri ) );
		$existing = $this->find_mapping( $invitee_uri );
		if ( $existing && $existing->payload_hash === $hash ) {
			Logger::info( 'duplicate_match_found', array( 'entry_id' => absint( $existing->formidable_entry_id ), 'invitee_email' => $email ) );
			return array( 'ok' => true, 'message' => 'Duplicate webhook ignored.' );
		}
		Logger::debug( 'duplicate_match_not_found', array( 'invitee_uri' => $invitee_uri ) );

		$mapped = $this->mapper->map_fields( $payload, $options );
		if ( empty( $mapped['email'] ) ) {
			Logger::warning( 'missing_required_email_after_mapping' );
			return array( 'ok' => false, 'message' => 'Required email is missing.' );
		}

		if ( ! in_array( $mapped['fields']['73'], array( 'Yes', 'No' ), true ) ) {
			Logger::warning( 'invalid_freight_forwarder_value', array( 'value' => $mapped['fields']['73'] ) );
		}
		if ( empty( $mapped['fields']['26'][0] ) ) {
			Logger::warning( 'invalid_checkbox_value' );
		}

		if ( ! $commit ) {
			Logger::info( 'manual_test_dry_run_success' );
			return array( 'ok' => true, 'message' => 'Dry run successful.', 'mapped' => $mapped['fields'] );
		}

		Logger::info( 'formidable_create_started', array( 'invitee_email' => $email ) );
		$entry_id = $existing ? absint( $existing->formidable_entry_id ) : 0;
		if ( $entry_id > 0 && class_exists( 'FrmEntry' ) ) {
			Logger::debug( 'formidable_update_started', array( 'entry_id' => $entry_id ) );
			$updated = \FrmEntry::update( $entry_id, array( 'item_meta' => $mapped['fields'] ) );
			if ( false === $updated ) {
				Logger::error( 'formidable_update_failed', array( 'entry_id' => $entry_id, 'reason' => 'Update returned false' ) );
			}
			Logger::info( 'formidable_update_success', array( 'entry_id' => $entry_id ) );
		} else {
			$entry_id = \FrmEntry::create(
				array(
					'form_id'   => 4,
					'item_meta' => $mapped['fields'],
				)
			);
		}

		if ( empty( $entry_id ) || is_wp_error( $entry_id ) ) {
			$reason = is_wp_error( $entry_id ) ? $entry_id->get_error_message() : 'Unknown create failure';
			Logger::error( 'formidable_create_failed', array( 'reason' => $reason, 'invitee_email' => $email ) );
			return array( 'ok' => false, 'message' => 'Formidable entry create failed: ' . $reason );
		}

		$this->upsert_mapping( $invitee_uri, $event_uri, 'active', $hash, $entry_id );
		update_option( 'ctfb_last_successful_sync', current_time( 'mysql' ) );
		update_option( 'ctfb_last_processed_email', $email );
		Logger::info( 'formidable_create_success', array( 'entry_id' => $entry_id, 'invitee_email' => $email ) );

		return array( 'ok' => true, 'message' => 'Entry synced successfully.', 'entry_id' => $entry_id );
	}

	private function handle_cancel( $invitee_uri, $hash, $email ) {
		$existing = $this->find_mapping( $invitee_uri );
		if ( ! $existing ) {
			Logger::info( 'canceled_ignored_no_mapping', array( 'invitee_email' => $email ) );
			return array( 'ok' => true, 'message' => 'Canceled event ignored: no linked mapping.' );
		}
		Logger::debug( 'existing_mapping_details', array( 'entry_id' => absint( $existing->formidable_entry_id ), 'status' => $existing->calendly_status ) );
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
