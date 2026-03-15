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

		try {
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

			Logger::debug( 'process_event_after_target_form_check' );

			$event_type = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';
			$resource   = ( isset( $payload['payload'] ) && is_array( $payload['payload'] ) ) ? $payload['payload'] : array();
			if ( isset( $payload['payload'] ) && ! is_array( $payload['payload'] ) ) {
				Logger::warning( 'payload_unexpected_shape', array( 'payload_type' => gettype( $payload['payload'] ) ) );
			}

			$invitee_uri = isset( $resource['uri'] ) && is_string( $resource['uri'] ) ? esc_url_raw( $resource['uri'] ) : '';
			if ( isset( $resource['uri'] ) && ! is_string( $resource['uri'] ) ) {
				Logger::warning( 'invitee_uri_unexpected_shape', array( 'value_type' => gettype( $resource['uri'] ) ) );
			}

			$scheduled_event_uri = '';
			if ( isset( $resource['scheduled_event'] ) && is_array( $resource['scheduled_event'] ) ) {
				$scheduled_event_uri = isset( $resource['scheduled_event']['uri'] ) && is_string( $resource['scheduled_event']['uri'] ) ? esc_url_raw( $resource['scheduled_event']['uri'] ) : '';
				if ( isset( $resource['scheduled_event']['uri'] ) && ! is_string( $resource['scheduled_event']['uri'] ) ) {
					Logger::warning( 'scheduled_event_uri_unexpected_shape', array( 'value_type' => gettype( $resource['scheduled_event']['uri'] ) ) );
				}
			} elseif ( isset( $resource['scheduled_event'] ) && is_string( $resource['scheduled_event'] ) ) {
				$scheduled_event_uri = esc_url_raw( $resource['scheduled_event'] );
				Logger::warning( 'scheduled_event_legacy_string_shape_detected' );
			} elseif ( isset( $resource['scheduled_event'] ) ) {
				Logger::warning( 'scheduled_event_unexpected_shape', array( 'value_type' => gettype( $resource['scheduled_event'] ) ) );
			}

			$email = isset( $resource['email'] ) && is_string( $resource['email'] ) ? sanitize_email( $resource['email'] ) : '';
			if ( isset( $resource['email'] ) && ! is_string( $resource['email'] ) ) {
				Logger::warning( 'email_unexpected_shape', array( 'value_type' => gettype( $resource['email'] ) ) );
			}

			$scheduled_event_type_uri = $this->extract_scheduled_event_type_uri( $resource );
			$allowed_event_types      = $this->get_allowed_event_types( $options );
			if ( ! $this->is_event_type_allowed( $scheduled_event_type_uri, $allowed_event_types ) ) {
				Logger::info(
					'webhook_event_type_filter',
					array(
						'detected_event_type_uri' => $scheduled_event_type_uri,
						'allowed_event_type_uris' => $allowed_event_types,
						'filter_result'           => 'skipped',
					)
					,
					true
				);
				return array( 'ok' => true, 'message' => 'Event type skipped by filter.' );
			}

			$host_assignment = $this->extract_host_assignment( $resource );
			Logger::debug(
				'host_assignment_detected',
				array(
					'value' => ! empty( $host_assignment['host_user_uri'] ) ? 'yes' : 'no',
				)
			);
			Logger::debug( 'host_user_name', array( 'value' => $host_assignment['host_user_name'] ) );
			Logger::debug( 'host_user_email', array( 'value' => $host_assignment['host_user_email'] ) );
			Logger::debug( 'host_user_uri', array( 'value' => $host_assignment['host_user_uri'] ) );

			if ( isset( $resource['tracking'] ) && ! is_array( $resource['tracking'] ) ) {
				Logger::warning( 'tracking_unexpected_shape', array( 'value_type' => gettype( $resource['tracking'] ) ) );
			}

			Logger::debug( 'scheduled_event_uri_extracted', array( 'scheduled_event_uri' => $scheduled_event_uri ) );
			Logger::debug( 'invitee_uri_extracted', array( 'invitee_uri' => $invitee_uri ) );

			$hash = hash( 'sha256', wp_json_encode( $payload ) );

			if ( 'invitee.canceled' === $event_type ) {
				return $this->handle_cancel( $invitee_uri, $hash, $email );
			}

			if ( empty( $email ) ) {
				Logger::warning( 'missing_required_email' );
				return array( 'ok' => false, 'message' => 'Required email is missing.' );
			}

			Logger::debug(
				'duplicate_check_started',
				array(
					'scheduled_event_uri' => $scheduled_event_uri,
					'invitee_uri'         => $invitee_uri,
					'payload_email'       => $email,
					'event_type'          => $event_type,
				)
			);
			$existing = $this->find_mapping( $invitee_uri );
			if ( $existing && $existing->payload_hash === $hash ) {
				Logger::info( 'duplicate_match_found', array( 'entry_id' => absint( $existing->formidable_entry_id ), 'invitee_email' => $email ) );
				Logger::debug( 'duplicate_check_completed', array( 'result' => 'duplicate' ) );
				return array( 'ok' => true, 'message' => 'Duplicate webhook ignored.' );
			}
			Logger::debug( 'duplicate_match_not_found', array( 'invitee_uri' => $invitee_uri ) );
			Logger::debug( 'duplicate_check_completed', array( 'result' => 'not_found' ) );

			Logger::debug( 'item_meta_build_started' );
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

			$diagnostics = isset( $mapped['diagnostics'] ) && is_array( $mapped['diagnostics'] ) ? $mapped['diagnostics'] : array();
			$diagnostics['host_assignment'] = $host_assignment;
			Logger::debug(
				'final_item_meta_payload_prepared',
				array(
					'item_meta'                 => $mapped['fields'],
					'resolved_required_values'  => isset( $diagnostics['required_values'] ) ? $diagnostics['required_values'] : array(),
					'resolved_fallback_values'  => isset( $diagnostics['fallback_values'] ) ? $diagnostics['fallback_values'] : array(),
					'field_26_checkbox_value'   => isset( $mapped['fields']['26'] ) ? $mapped['fields']['26'] : array(),
					'field_73_forwarder_value'  => isset( $mapped['fields']['73'] ) ? $mapped['fields']['73'] : '',
					'field_24_company_value'    => isset( $mapped['fields']['24'] ) ? $mapped['fields']['24'] : '',
					'field_23_email_value'      => isset( $mapped['fields']['23'] ) ? $mapped['fields']['23'] : '',
					'field_26_value_source'     => isset( $diagnostics['field_26_source'] ) ? $diagnostics['field_26_source'] : '',
				)
			);

			if ( ! $commit ) {
				Logger::info( 'manual_test_dry_run_success' );
				return array( 'ok' => true, 'message' => 'Dry run successful.', 'mapped' => $mapped['fields'] );
			}

			Logger::debug( 'formidable_create_started', array( 'invitee_email' => $email ) );
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
				if ( ! is_wp_error( $entry_id ) ) {
					Logger::error( 'formidable_create_unexpected_result', array( 'result_type' => gettype( $entry_id ) ) );
				}
				return array( 'ok' => false, 'message' => 'Formidable entry create failed: ' . $reason );
			}

			Logger::info( 'formidable_create_success', array( 'entry_id' => $entry_id, 'invitee_email' => $email ) );
			Logger::debug( 'formidable_create_completed', array( 'entry_id' => $entry_id ) );

			$this->upsert_mapping( $invitee_uri, $scheduled_event_uri, 'active', $hash, $entry_id );
			update_option( 'ctfb_last_successful_sync', current_time( 'mysql' ) );
			update_option( 'ctfb_last_processed_email', $email );

			return array( 'ok' => true, 'message' => 'Entry synced successfully.', 'entry_id' => $entry_id );
		} catch ( \Throwable $e ) {
			$this->log_throwable( $e, $payload );
			return array( 'ok' => false, 'message' => 'Webhook processing failed due to runtime error.' );
		}
	}

	private function extract_scheduled_event_type_uri( $resource ) {
		if ( ! isset( $resource['scheduled_event'] ) || ! is_array( $resource['scheduled_event'] ) ) {
			if ( isset( $resource['scheduled_event'] ) && ! is_array( $resource['scheduled_event'] ) ) {
				Logger::warning( 'scheduled_event_for_event_type_unexpected_shape', array( 'value_type' => gettype( $resource['scheduled_event'] ) ) );
			}
			return '';
		}

		$scheduled_event = $resource['scheduled_event'];
		if ( isset( $scheduled_event['event_type'] ) && is_string( $scheduled_event['event_type'] ) ) {
			return esc_url_raw( $scheduled_event['event_type'] );
		}

		if ( isset( $scheduled_event['event_type'] ) && ! is_string( $scheduled_event['event_type'] ) ) {
			Logger::warning( 'scheduled_event_event_type_unexpected_shape', array( 'value_type' => gettype( $scheduled_event['event_type'] ) ) );
		}

		return '';
	}

	private function get_allowed_event_types( $options ) {
		if ( empty( $options['allowed_event_types'] ) || ! is_array( $options['allowed_event_types'] ) ) {
			return array();
		}

		$allowed = array();
		foreach ( $options['allowed_event_types'] as $uri ) {
			$clean = esc_url_raw( trim( (string) $uri ) );
			if ( '' !== $clean ) {
				$allowed[] = $clean;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	private function is_event_type_allowed( $event_type_uri, $allowed_event_types ) {
		if ( empty( $allowed_event_types ) ) {
			return true;
		}

		if ( empty( $event_type_uri ) ) {
			return false;
		}

		return in_array( $event_type_uri, $allowed_event_types, true );
	}

	private function extract_host_assignment( $resource ) {
		$host = array(
			'host_user_uri'   => '',
			'host_user_email' => '',
			'host_user_name'  => '',
		);

		if ( ! isset( $resource['scheduled_event'] ) || ! is_array( $resource['scheduled_event'] ) ) {
			return $host;
		}

		$scheduled_event = $resource['scheduled_event'];
		if ( ! isset( $scheduled_event['event_memberships'] ) || ! is_array( $scheduled_event['event_memberships'] ) ) {
			if ( isset( $scheduled_event['event_memberships'] ) ) {
				Logger::warning( 'event_memberships_unexpected_shape', array( 'value_type' => gettype( $scheduled_event['event_memberships'] ) ) );
			}
			return $host;
		}

		$first_membership = isset( $scheduled_event['event_memberships'][0] ) && is_array( $scheduled_event['event_memberships'][0] ) ? $scheduled_event['event_memberships'][0] : array();
		if ( empty( $first_membership ) ) {
			return $host;
		}

		if ( isset( $first_membership['user'] ) && is_string( $first_membership['user'] ) ) {
			$host['host_user_uri'] = esc_url_raw( $first_membership['user'] );
		}
		if ( isset( $first_membership['user_email'] ) && is_string( $first_membership['user_email'] ) ) {
			$host['host_user_email'] = sanitize_email( $first_membership['user_email'] );
		}
		if ( isset( $first_membership['user_name'] ) && is_string( $first_membership['user_name'] ) ) {
			$host['host_user_name'] = sanitize_text_field( $first_membership['user_name'] );
		}

		return $host;
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

	private function log_throwable( \Throwable $e, $payload ) {
		$trace_string = $e->getTraceAsString();
		if ( strlen( $trace_string ) > 1000 ) {
			$trace_string = substr( $trace_string, 0, 1000 ) . '...';
		}
		Logger::error(
			'webhook_processing_throwable',
			array(
				'throwable_class'   => get_class( $e ),
				'throwable_message' => $e->getMessage(),
				'throwable_file'    => $e->getFile(),
				'throwable_line'    => $e->getLine(),
				'throwable_trace'   => $trace_string,
			)
		);

		$diag                           = get_option( 'ctfb_diagnostics', array() );
		$diag['last_throwable_class']   = sanitize_text_field( get_class( $e ) );
		$diag['last_throwable_message'] = sanitize_text_field( $e->getMessage() );
		$diag['last_throwable_time']    = current_time( 'mysql' );
		update_option( 'ctfb_diagnostics', $diag );

		$this->store_failure_row( 'throwable_error', $payload, $e->getMessage() );
	}

	private function store_failure_row( $event_type, $payload, $message ) {
		$resource = ( isset( $payload['payload'] ) && is_array( $payload['payload'] ) ) ? $payload['payload'] : array();
		$email    = isset( $resource['email'] ) ? sanitize_email( $resource['email'] ) : '';
		Logger::error( $event_type, array( 'invitee_email' => $email, 'reason' => $message ) );
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
