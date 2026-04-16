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
		$scope_mode_used = isset( $options['webhook_scope_mode'] ) ? sanitize_text_field( (string) $options['webhook_scope_mode'] ) : ( isset( $options['webhook_scope'] ) ? sanitize_text_field( (string) $options['webhook_scope'] ) : 'user' );
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
			$event_type_name           = $this->extract_scheduled_event_type_name( $resource );
			$webhook_event_uri         = $scheduled_event_uri;
			$webhook_event_name        = $event_type_name;
			$pooling_type              = $this->extract_pooling_type( $resource );
			$shared_booking            = $this->is_shared_booking( $pooling_type, $resource );
			$allowed_event_types       = $this->get_allowed_event_types( $options );

			Logger::debug(
				'webhook_event_type_detected',
				array(
					'event_type_uri'  => $scheduled_event_type_uri,
					'event_type_name' => $event_type_name,
					'event_uri'       => $webhook_event_uri,
					'event_name'      => $webhook_event_name,
					'pooling_type'    => $pooling_type,
					'shared_booking'  => $shared_booking ? 'yes' : 'no',
					'webhook_scope_mode_used' => $scope_mode_used,
				)
			);

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
				Logger::debug(
					'shared_team_booking_inclusion',
					array(
						'booking_status'  => 'skipped',
						'shared_booking'  => $shared_booking ? 'yes' : 'no',
						'event_type_uri'  => $scheduled_event_type_uri,
						'event_type_name' => $event_type_name,
						'pooling_type'    => $pooling_type,
					)
				);
				return array( 'ok' => true, 'message' => 'Event type skipped by filter.' );
			}


			$diag = get_option( 'ctfb_diagnostics', array() );
			if ( ! is_array( $diag ) ) {
				$diag = array();
			}
			$diag['organization_shared_team_support_enabled'] = in_array( $scope_mode_used, array( 'organization', 'both' ), true ) ? 'yes' : 'no';
			update_option( 'ctfb_diagnostics', $diag );

			$host_assignment = $this->extract_host_assignment( $resource );
			$host_detected   = isset( $host_assignment['host_membership_count'] ) && (int) $host_assignment['host_membership_count'] > 0;
			if ( $host_detected ) {
				Logger::debug( 'host_assignment_detected' );
				Logger::debug( 'host_membership_count', array( 'value' => isset( $host_assignment['host_membership_count'] ) ? (int) $host_assignment['host_membership_count'] : 0 ) );
				Logger::debug( 'assigned_host_user_name', array( 'value' => $host_assignment['assigned_host_user_name'] ) );
				Logger::debug( 'assigned_host_user_email', array( 'value' => $host_assignment['assigned_host_user_email'] ) );
				Logger::debug( 'assigned_host_user_uri', array( 'value' => $host_assignment['assigned_host_user_uri'] ) );
			} else {
				Logger::debug( 'host_assignment_missing' );
			}
			$this->store_host_diagnostics( $host_assignment );
			Logger::debug(
				'shared_team_booking_inclusion',
				array(
					'booking_status'  => 'included',
					'shared_booking'  => $shared_booking ? 'yes' : 'no',
					'event_type_uri'  => $scheduled_event_type_uri,
					'event_type_name' => $event_type_name,
					'pooling_type'    => $pooling_type,
				)
			);

			Logger::debug(
				'host_assignment_extracted',
				array(
					'assigned_host_user_uri'   => $host_assignment['assigned_host_user_uri'],
					'assigned_host_user_email' => $host_assignment['assigned_host_user_email'],
					'assigned_host_user_name'  => $host_assignment['assigned_host_user_name'],
				)
			);

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

			$mapped['fields'] = $this->apply_host_field_mapping( $mapped['fields'], $host_assignment, $options );
			$utm_tracking     = $this->extract_tracking_values( $resource );
			$mapped['fields'] = $this->apply_utm_field_mapping( $mapped['fields'], $utm_tracking );
			$this->store_utm_diagnostics( $utm_tracking );

			$diagnostics = isset( $mapped['diagnostics'] ) && is_array( $mapped['diagnostics'] ) ? $mapped['diagnostics'] : array();
			$diagnostics['host_assignment'] = $host_assignment;
			$diagnostics['utm_tracking']    = $utm_tracking;
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

	private function extract_scheduled_event_type_name( $resource ) {
		if ( ! isset( $resource['scheduled_event'] ) || ! is_array( $resource['scheduled_event'] ) ) {
			return '';
		}

		$scheduled_event = $resource['scheduled_event'];
		if ( isset( $scheduled_event['name'] ) && is_string( $scheduled_event['name'] ) ) {
			return sanitize_text_field( $scheduled_event['name'] );
		}

		return '';
	}

	private function extract_pooling_type( $resource ) {
		if ( ! isset( $resource['scheduled_event'] ) || ! is_array( $resource['scheduled_event'] ) ) {
			return '';
		}

		$scheduled_event = $resource['scheduled_event'];
		if ( isset( $scheduled_event['pooling_type'] ) && is_string( $scheduled_event['pooling_type'] ) ) {
			return sanitize_text_field( $scheduled_event['pooling_type'] );
		}

		return '';
	}

	private function is_shared_booking( $pooling_type, $resource ) {
		$normalized_pooling = strtolower( (string) $pooling_type );
		if ( in_array( $normalized_pooling, array( 'round_robin', 'collective' ), true ) ) {
			return true;
		}

		if ( isset( $resource['scheduled_event']['event_memberships'] ) && is_array( $resource['scheduled_event']['event_memberships'] ) && count( $resource['scheduled_event']['event_memberships'] ) > 1 ) {
			return true;
		}

		return false;
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
			'assigned_host_user_uri'   => '',
			'assigned_host_user_email' => '',
			'assigned_host_user_name'  => '',
			'host_membership_count'    => 0,
		);

		if ( ! isset( $resource['scheduled_event'] ) || ! is_array( $resource['scheduled_event'] ) ) {
			return $host;
		}

		$scheduled_event = $resource['scheduled_event'];
		if ( ! isset( $scheduled_event['event_memberships'] ) ) {
			return $host;
		}

		if ( ! is_array( $scheduled_event['event_memberships'] ) ) {
			Logger::warning( 'event_memberships_unexpected_shape', array( 'value_type' => gettype( $scheduled_event['event_memberships'] ) ) );
			return $host;
		}

		$host['host_membership_count'] = count( $scheduled_event['event_memberships'] );
		if ( 0 === $host['host_membership_count'] ) {
			return $host;
		}

		$first_membership = isset( $scheduled_event['event_memberships'][0] ) && is_array( $scheduled_event['event_memberships'][0] ) ? $scheduled_event['event_memberships'][0] : array();
		if ( empty( $first_membership ) ) {
			return $host;
		}

		if ( isset( $first_membership['user'] ) && is_string( $first_membership['user'] ) ) {
			$host['assigned_host_user_uri'] = esc_url_raw( $first_membership['user'] );
		}
		if ( isset( $first_membership['user_email'] ) && is_string( $first_membership['user_email'] ) ) {
			$host['assigned_host_user_email'] = sanitize_email( $first_membership['user_email'] );
		}
		if ( isset( $first_membership['user_name'] ) && is_string( $first_membership['user_name'] ) ) {
			$host['assigned_host_user_name'] = sanitize_text_field( $first_membership['user_name'] );
		}

		return $host;
	}

	private function apply_host_field_mapping( $fields, $host_assignment, $options ) {
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$host_name_field_id     = isset( $options['assigned_host_name_field_id'] ) ? trim( (string) $options['assigned_host_name_field_id'] ) : '';
		$host_email_field_id    = isset( $options['assigned_host_email_field_id'] ) ? trim( (string) $options['assigned_host_email_field_id'] ) : '';
		$host_user_uri_field_id = isset( $options['assigned_host_user_uri_field_id'] ) ? trim( (string) $options['assigned_host_user_uri_field_id'] ) : '';

		Logger::debug( 'host_field_mapping_started' );
		Logger::debug( 'host_field_id_name', array( 'value' => $host_name_field_id ) );
		Logger::debug( 'host_field_id_email', array( 'value' => $host_email_field_id ) );
		Logger::debug( 'host_field_id_user_uri', array( 'value' => $host_user_uri_field_id ) );

		if ( '' !== $host_name_field_id ) {
			$fields[ $host_name_field_id ] = isset( $host_assignment['assigned_host_user_name'] ) ? (string) $host_assignment['assigned_host_user_name'] : '';
		}
		if ( '' !== $host_email_field_id ) {
			$fields[ $host_email_field_id ] = isset( $host_assignment['assigned_host_user_email'] ) ? (string) $host_assignment['assigned_host_user_email'] : '';
		}
		if ( '' !== $host_user_uri_field_id ) {
			$fields[ $host_user_uri_field_id ] = isset( $host_assignment['assigned_host_user_uri'] ) ? (string) $host_assignment['assigned_host_user_uri'] : '';
		}

		Logger::debug(
			'final_host_values_added_to_item_meta',
			array(
				'host_field_id_name'      => $host_name_field_id,
				'host_field_id_email'     => $host_email_field_id,
				'host_field_id_user_uri'  => $host_user_uri_field_id,
				'assigned_host_user_name' => isset( $host_assignment['assigned_host_user_name'] ) ? $host_assignment['assigned_host_user_name'] : '',
				'assigned_host_user_email'=> isset( $host_assignment['assigned_host_user_email'] ) ? $host_assignment['assigned_host_user_email'] : '',
				'assigned_host_user_uri'  => isset( $host_assignment['assigned_host_user_uri'] ) ? $host_assignment['assigned_host_user_uri'] : '',
			)
		);
		Logger::debug( 'host_field_mapping_completed' );

		return $fields;
	}

	private function extract_tracking_values( $resource ) {
		$tracking = array();
		if ( isset( $resource['tracking'] ) && is_array( $resource['tracking'] ) ) {
			$tracking = $resource['tracking'];
		}

		$utm_tracking = array(
			'utm_source'   => $this->sanitize_tracking_value( isset( $tracking['utm_source'] ) ? $tracking['utm_source'] : '' ),
			'utm_medium'   => $this->sanitize_tracking_value( isset( $tracking['utm_medium'] ) ? $tracking['utm_medium'] : '' ),
			'utm_campaign' => $this->sanitize_tracking_value( isset( $tracking['utm_campaign'] ) ? $tracking['utm_campaign'] : '' ),
			'utm_term'     => $this->sanitize_tracking_value( isset( $tracking['utm_term'] ) ? $tracking['utm_term'] : '' ),
			'utm_content'  => $this->sanitize_tracking_value( isset( $tracking['utm_content'] ) ? $tracking['utm_content'] : '' ),
		);

		$utm_tracking['combined_source_id'] = $this->build_combined_source_id( $utm_tracking['utm_source'], $utm_tracking['utm_medium'] );

		Logger::debug( 'tracking_payload_detected', array( 'present' => ! empty( $tracking ) ? 'yes' : 'no' ) );
		Logger::debug( 'utm_source_value', array( 'value' => $utm_tracking['utm_source'] ) );
		Logger::debug( 'utm_medium_value', array( 'value' => $utm_tracking['utm_medium'] ) );
		Logger::debug( 'utm_campaign_value', array( 'value' => $utm_tracking['utm_campaign'] ) );
		Logger::debug( 'utm_term_value', array( 'value' => $utm_tracking['utm_term'] ) );
		Logger::debug( 'utm_content_value', array( 'value' => $utm_tracking['utm_content'] ) );

		return $utm_tracking;
	}

	private function apply_utm_field_mapping( $fields, $utm_tracking ) {
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		if ( ! empty( $utm_tracking['combined_source_id'] ) ) {
			$fields['69'] = $utm_tracking['combined_source_id'];
		}

		if ( ! empty( $utm_tracking['utm_campaign'] ) ) {
			$fields['70'] = $utm_tracking['utm_campaign'];
		}

		if ( ! empty( $utm_tracking['utm_term'] ) ) {
			$fields['143'] = $utm_tracking['utm_term'];
		}

		Logger::debug( 'field_69_source_id_resolved', array( 'value' => isset( $fields['69'] ) ? $fields['69'] : '' ) );
		Logger::debug( 'field_70_campaign_id_resolved', array( 'value' => isset( $fields['70'] ) ? $fields['70'] : '' ) );
		Logger::debug( 'field_143_utmterm_resolved', array( 'value' => isset( $fields['143'] ) ? $fields['143'] : '' ) );

		return $fields;
	}

	private function sanitize_tracking_value( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( trim( (string) $value ) );
	}

	private function build_combined_source_id( $utm_source, $utm_medium ) {
		if ( '' !== $utm_source && '' !== $utm_medium ) {
			return sanitize_text_field( $utm_source . ' / ' . $utm_medium );
		}

		if ( '' !== $utm_source ) {
			return $utm_source;
		}

		if ( '' !== $utm_medium ) {
			return $utm_medium;
		}

		return '';
	}

	private function store_host_diagnostics( $host_assignment ) {
		$diag = get_option( 'ctfb_diagnostics', array() );
		if ( ! is_array( $diag ) ) {
			$diag = array();
		}

		$diag['last_assigned_host_name'] = isset( $host_assignment['assigned_host_user_name'] ) ? sanitize_text_field( (string) $host_assignment['assigned_host_user_name'] ) : '';
		$diag['last_assigned_host_email'] = isset( $host_assignment['assigned_host_user_email'] ) ? sanitize_email( (string) $host_assignment['assigned_host_user_email'] ) : '';
		$diag['last_assigned_host_user_uri'] = isset( $host_assignment['assigned_host_user_uri'] ) ? esc_url_raw( (string) $host_assignment['assigned_host_user_uri'] ) : '';
		update_option( 'ctfb_diagnostics', $diag );
	}

	private function store_utm_diagnostics( $utm_tracking ) {
		$diag = get_option( 'ctfb_diagnostics', array() );
		if ( ! is_array( $diag ) ) {
			$diag = array();
		}

		$diag['last_utm_source']              = isset( $utm_tracking['utm_source'] ) ? sanitize_text_field( (string) $utm_tracking['utm_source'] ) : '';
		$diag['last_utm_medium']              = isset( $utm_tracking['utm_medium'] ) ? sanitize_text_field( (string) $utm_tracking['utm_medium'] ) : '';
		$diag['last_utm_campaign']            = isset( $utm_tracking['utm_campaign'] ) ? sanitize_text_field( (string) $utm_tracking['utm_campaign'] ) : '';
		$diag['last_utm_term']                = isset( $utm_tracking['utm_term'] ) ? sanitize_text_field( (string) $utm_tracking['utm_term'] ) : '';
		$diag['last_utm_content']             = isset( $utm_tracking['utm_content'] ) ? sanitize_text_field( (string) $utm_tracking['utm_content'] ) : '';
		$diag['last_combined_source_id_value'] = isset( $utm_tracking['combined_source_id'] ) ? sanitize_text_field( (string) $utm_tracking['combined_source_id'] ) : '';
		update_option( 'ctfb_diagnostics', $diag );
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
