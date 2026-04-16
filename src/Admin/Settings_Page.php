<?php

namespace CTFB\Admin;

use CTFB\API\Calendly_Client;
use CTFB\Support\Logger;
use CTFB\Support\Token_Helper;
use CTFB\Sync\Formidable_Sync;

class Settings_Page {
	public function register_menu() {
		add_options_page(
			'Calendly to Formidable',
			'Calendly to Formidable',
			'manage_options',
			'calendly-to-formidable-bridge',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'ctfb_settings_group', 'ctfb_options', array( $this, 'sanitize_options' ) );
	}

	public function sanitize_options( $input ) {
		$existing                                = get_option( 'ctfb_options', array() );
		$sanitized                               = array();
		$sanitized['enabled']                    = ! empty( $input['enabled'] ) ? 1 : 0;
		$sanitized['debug_logging']              = ! empty( $input['debug_logging'] ) ? 1 : 0;
		$sanitized['fallback_company_name']      = isset( $input['fallback_company_name'] ) ? sanitize_text_field( $input['fallback_company_name'] ) : '';
		$sanitized['fallback_freight_forwarder'] = ( isset( $input['fallback_freight_forwarder'] ) && 'Yes' === $input['fallback_freight_forwarder'] ) ? 'Yes' : 'No';
		$sanitized['default_country']            = isset( $input['default_country'] ) ? sanitize_text_field( $input['default_country'] ) : '';
		$sanitized['pat']                        = ! empty( $input['pat'] ) ? sanitize_text_field( $input['pat'] ) : ( isset( $existing['pat'] ) ? $existing['pat'] : '' );
		$sanitized['webhook_subscription_uri']   = isset( $existing['webhook_subscription_uri'] ) ? $existing['webhook_subscription_uri'] : '';
		$sanitized['webhook_scope']              = isset( $existing['webhook_scope'] ) ? $existing['webhook_scope'] : 'user';
		$sanitized['webhook_scope_uri']          = isset( $existing['webhook_scope_uri'] ) ? $existing['webhook_scope_uri'] : '';
		$sanitized['webhook_user_uri']           = isset( $existing['webhook_user_uri'] ) ? $existing['webhook_user_uri'] : '';
		$sanitized['webhook_organization_uri']   = isset( $existing['webhook_organization_uri'] ) ? $existing['webhook_organization_uri'] : '';
		$sanitized['webhook_scope_mode']         = isset( $existing['webhook_scope_mode'] ) ? $existing['webhook_scope_mode'] : 'user';
		$sanitized['webhook_user_active']        = isset( $existing['webhook_user_active'] ) ? (int) $existing['webhook_user_active'] : 0;
		$sanitized['webhook_organization_active']= isset( $existing['webhook_organization_active'] ) ? (int) $existing['webhook_organization_active'] : 0;
		$sanitized['webhook_user_subscription_uri'] = isset( $existing['webhook_user_subscription_uri'] ) ? $existing['webhook_user_subscription_uri'] : '';
		$sanitized['webhook_organization_subscription_uri'] = isset( $existing['webhook_organization_subscription_uri'] ) ? $existing['webhook_organization_subscription_uri'] : '';
		$sanitized['allowed_event_types']        = $this->sanitize_allowed_event_types( $input, $existing );
		$sanitized['booking_event_types']        = $this->sanitize_booking_event_types( $input, $existing );
		$sanitized['booking_display_mode']       = ( isset( $input['booking_display_mode'] ) && 'list' === $input['booking_display_mode'] ) ? 'list' : 'calendar';
		$sanitized['assigned_host_name_field_id'] = isset( $input['assigned_host_name_field_id'] ) ? sanitize_text_field( $input['assigned_host_name_field_id'] ) : ( isset( $existing['assigned_host_name_field_id'] ) ? sanitize_text_field( $existing['assigned_host_name_field_id'] ) : '' );
		$sanitized['assigned_host_email_field_id'] = isset( $input['assigned_host_email_field_id'] ) ? sanitize_text_field( $input['assigned_host_email_field_id'] ) : ( isset( $existing['assigned_host_email_field_id'] ) ? sanitize_text_field( $existing['assigned_host_email_field_id'] ) : '' );
		$sanitized['assigned_host_user_uri_field_id'] = isset( $input['assigned_host_user_uri_field_id'] ) ? sanitize_text_field( $input['assigned_host_user_uri_field_id'] ) : ( isset( $existing['assigned_host_user_uri_field_id'] ) ? sanitize_text_field( $existing['assigned_host_user_uri_field_id'] ) : '' );

		$sanitized = $this->preserve_existing_webhook_state( $sanitized, $existing );
		return $sanitized;
	}

	private function preserve_existing_webhook_state( $sanitized, $existing ) {
		$keys = array(
			'webhook_subscription_uri',
			'webhook_scope',
			'webhook_scope_uri',
			'webhook_user_uri',
			'webhook_organization_uri',
			'webhook_scope_mode',
			'webhook_user_active',
			'webhook_organization_active',
			'webhook_user_subscription_uri',
			'webhook_organization_subscription_uri',
		);

		foreach ( $keys as $key ) {
			$existing_value  = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
			$sanitized_value = isset( $sanitized[ $key ] ) ? (string) $sanitized[ $key ] : '';
			if ( '' !== $existing_value && '' === $sanitized_value ) {
				$sanitized[ $key ] = $existing_value;
				Logger::warning(
					'webhook_state_reset_prevented_during_settings_sanitize',
					array(
						'key'            => $key,
						'existing_value' => $existing_value,
					)
				);
			}
		}

		return $sanitized;
	}

	private function sanitize_allowed_event_types( $input, $existing ) {
		$allowed = array();

		if ( isset( $input['allowed_event_types'] ) && is_array( $input['allowed_event_types'] ) ) {
			foreach ( $input['allowed_event_types'] as $uri ) {
				$clean = esc_url_raw( trim( (string) $uri ) );
				if ( '' !== $clean ) {
					$allowed[] = $clean;
				}
			}
		}

		if ( isset( $input['allowed_event_types_manual'] ) ) {
			$manual_lines = preg_split( '/\r\n|\r|\n/', (string) $input['allowed_event_types_manual'] );
			if ( is_array( $manual_lines ) ) {
				foreach ( $manual_lines as $line ) {
					$clean = esc_url_raw( trim( (string) $line ) );
					if ( '' !== $clean ) {
						$allowed[] = $clean;
					}
				}
			}
		}

		if ( empty( $allowed ) && isset( $existing['allowed_event_types'] ) && is_array( $existing['allowed_event_types'] ) && ! isset( $input['allowed_event_types'] ) && ! isset( $input['allowed_event_types_manual'] ) ) {
			$allowed = $existing['allowed_event_types'];
		}

		return array_values( array_unique( $allowed ) );
	}

	private function sanitize_booking_event_types( $input, $existing ) {
		$allowed = array();

		if ( isset( $input['booking_event_types'] ) && is_array( $input['booking_event_types'] ) ) {
			foreach ( $input['booking_event_types'] as $uri ) {
				$clean = esc_url_raw( trim( (string) $uri ) );
				if ( '' !== $clean ) {
					$allowed[] = $clean;
				}
			}
		}

		if ( isset( $input['booking_event_types_manual'] ) ) {
			$manual_lines = preg_split( '/\r\n|\r|\n/', (string) $input['booking_event_types_manual'] );
			if ( is_array( $manual_lines ) ) {
				foreach ( $manual_lines as $line ) {
					$clean = esc_url_raw( trim( (string) $line ) );
					if ( '' !== $clean ) {
						$allowed[] = $clean;
					}
				}
			}
		}

		if ( empty( $allowed ) && isset( $existing['booking_event_types'] ) && is_array( $existing['booking_event_types'] ) && ! isset( $input['booking_event_types'] ) && ! isset( $input['booking_event_types_manual'] ) ) {
			$allowed = $existing['booking_event_types'];
		}

		return array_values( array_unique( $allowed ) );
	}

	public function activation_notice() {
		if ( get_option( 'ctfb_activation_notice' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Calendly to Formidable Bridge activated.</p></div>';
			delete_option( 'ctfb_activation_notice' );
		}
	}

	public function handle_test_connection_action() {
		$this->log_action_entry( 'admin_action_test_connection_entered', 'ctfb_test_connection' );
		$this->verify_admin_action_security( 'ctfb_test_connection' );
		$this->update_trace( 'last_test_connection_action_entered_time', current_time( 'mysql' ) );

		$options = get_option( 'ctfb_options', array() );
		$client  = new Calendly_Client( isset( $options['pat'] ) ? $options['pat'] : '' );
		$notice  = $this->perform_test_connection( $client, $options );
		$this->redirect_with_notice( $notice, false );
	}

	public function handle_create_webhook_action() {
		$this->log_action_entry( 'admin_action_create_webhook_entered', 'ctfb_create_webhook' );
		$this->verify_admin_action_security( 'ctfb_create_webhook' );
		$this->update_trace( 'last_create_webhook_action_entered_time', current_time( 'mysql' ) );
		$result = $this->perform_create_webhook_flow( false );
		$this->update_trace( 'last_create_webhook_handler_result', $result['message'] );
		$this->redirect_with_notice( $result['message'], ! empty( $result['is_error'] ) );
	}

	public function handle_refresh_webhook_action() {
		$this->log_action_entry( 'admin_action_refresh_webhook_entered', 'ctfb_refresh_webhook' );
		$this->verify_admin_action_security( 'ctfb_refresh_webhook' );
		$this->update_trace( 'last_refresh_webhook_action_entered_time', current_time( 'mysql' ) );
		$result = $this->perform_create_webhook_flow( true );
		$this->redirect_with_notice( $result['message'], ! empty( $result['is_error'] ) );
	}

	public function handle_delete_webhook_action() {
		$this->log_action_entry( 'admin_action_delete_webhook_entered', 'ctfb_delete_webhook' );
		$this->verify_admin_action_security( 'ctfb_delete_webhook' );
		$this->update_trace( 'last_delete_webhook_action_entered_time', current_time( 'mysql' ) );

		$options = get_option( 'ctfb_options', array() );
		$client  = new Calendly_Client( isset( $options['pat'] ) ? $options['pat'] : '' );
		$notice  = $this->perform_delete_webhook( $client, $options );
		$this->redirect_with_notice( $notice, false !== stripos( $notice, 'error' ) );
	}

	public function handle_manual_test_action() {
		$this->log_action_entry( 'admin_action_manual_test_entered', 'ctfb_manual_test' );
		$this->verify_admin_action_security( 'ctfb_manual_test' );

		$payload = isset( $_POST['manual_payload'] ) ? wp_unslash( $_POST['manual_payload'] ) : '';
		$data    = json_decode( $payload, true );
		Logger::info( 'manual_test_flow', array( 'commit_to_database' => ! empty( $_POST['commit_to_database'] ) ? 'yes' : 'no' ), true );
		Logger::log_raw_payload( $payload );
		if ( ! is_array( $data ) ) {
			Logger::error( 'manual_test_invalid_json' );
			$this->redirect_with_notice( 'Manual payload is not valid JSON.', true );
		}

		$commit = ! empty( $_POST['commit_to_database'] );
		$sync   = new Formidable_Sync();
		$result = $sync->process( $data, $commit, 'manual_test' );
		$this->redirect_with_notice( $result['message'], empty( $result['ok'] ) );
	}


	public function handle_replay_last_live_webhook_action() {
		$this->log_action_entry( 'admin_action_replay_last_live_webhook_entered', 'ctfb_replay_last_live_webhook' );
		$this->verify_admin_action_security( 'ctfb_replay_last_live_webhook' );

		$raw = get_option( 'ctfb_last_live_webhook_payload_raw', '' );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			$this->redirect_with_notice( 'No stored live webhook payload found.', true );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			Logger::error( 'replay_last_live_webhook_invalid_json' );
			$this->redirect_with_notice( 'Stored live payload is not valid JSON.', true );
		}

		Logger::info( 'replay_last_live_webhook_started', array(), true );
		Logger::log_raw_payload( $raw );
		$commit = ! empty( $_POST['commit_to_database'] );
		$sync   = new Formidable_Sync();
		$result = $sync->process( $data, $commit, 'manual_replay_last_live' );
		$this->redirect_with_notice( $result['message'], empty( $result['ok'] ) );
	}

	public function handle_clear_log_action() {
		$this->log_action_entry( 'admin_action_clear_log_entered', 'ctfb_clear_log' );
		$this->verify_admin_action_security( 'ctfb_clear_log' );
		$cleared = Logger::clear_log_file();
		$this->redirect_with_notice( $cleared ? 'Debug log file cleared.' : 'Debug log file could not be cleared.', ! $cleared );
	}

	public function handle_download_log_action() {
		$this->log_action_entry( 'admin_action_download_log_entered', 'ctfb_download_log' );
		$this->verify_admin_action_security( 'ctfb_download_log' );
		$this->download_log_file();
	}

	public function handle_test_create_webhook_handler_action() {
		$this->log_action_entry( 'admin_action_test_create_webhook_handler_entered', 'ctfb_test_create_webhook_handler' );
		$this->verify_admin_action_security( 'ctfb_test_create_webhook_handler' );
		Logger::info( 'create_webhook_handler_self_test_reached' );
		$this->redirect_with_notice( 'Create Webhook handler self-test succeeded.', false );
	}

	public function handle_preview_create_webhook_payload_action() {
		$this->log_action_entry( 'admin_action_preview_create_webhook_payload_entered', 'ctfb_preview_create_webhook_payload' );
		$this->verify_admin_action_security( 'ctfb_preview_create_webhook_payload' );

		$options = get_option( 'ctfb_options', array() );
		$context = $this->derive_webhook_context( $options );
		if ( ! empty( $context['error'] ) ) {
			$this->redirect_with_notice( $context['error'], true );
		}

		$payload = $this->build_webhook_payload( $context['scope_mode'], $context['user_uri'], $context['organization_uri'] );
		Logger::info( 'preview_final_webhook_payload_generated', array( 'payload' => $payload ), true );
		update_option( 'ctfb_preview_create_webhook_payload', $payload );
		$this->redirect_with_notice( 'Webhook payload preview generated. See Diagnostics section.', false );
	}

	private function verify_admin_action_security( $nonce_action ) {
		$can_manage = current_user_can( 'manage_options' );
		Logger::debug( 'admin_action_before_capability_check', array( 'action' => $nonce_action, 'can_manage_options' => $can_manage ? 'yes' : 'no' ) );
		if ( ! $can_manage ) {
			Logger::error( 'admin_action_capability_check_failed', array( 'action' => $nonce_action ) );
			wp_die( 'Unauthorized.' );
		}
		Logger::debug( 'admin_action_after_capability_check', array( 'action' => $nonce_action ) );

		Logger::debug( 'admin_action_before_nonce_validation', array( 'action' => $nonce_action ) );
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			Logger::error( 'admin_action_nonce_validation_failed', array( 'action' => $nonce_action, 'reason' => empty( $nonce ) ? 'missing_nonce' : 'invalid_nonce' ) );
			wp_die( 'Nonce verification failed.' );
		}
		Logger::debug( 'admin_action_after_nonce_validation', array( 'action' => $nonce_action ) );
	}

	private function perform_test_connection( $client, $options ) {
		if ( empty( $options['pat'] ) ) {
			update_option( 'ctfb_diagnostics', array( 'last_api_error' => 'Missing Personal Access Token.', 'last_api_check' => current_time( 'mysql' ) ) );
			return 'Missing Personal Access Token.';
		}
		$uuid = Token_Helper::get_user_uuid_from_pat( $options['pat'] );
		if ( empty( $uuid ) ) {
			update_option( 'ctfb_diagnostics', array( 'last_api_error' => 'Malformed PAT: user_uuid claim is missing.', 'last_api_check' => current_time( 'mysql' ) ) );
			return 'Malformed PAT: user_uuid claim is missing.';
		}
		Logger::debug( 'attempting_get_users_me' );
		$trace    = array();
		$response = $client->get_users_me( $trace );
		Logger::debug( 'users_me_response_success_or_failure', array( 'result' => is_wp_error( $response ) ? 'failure' : 'success' ) );
		$diag = array(
			'last_api_check' => current_time( 'mysql' ),
			'pat_user_uuid'  => $uuid,
			'pat_scopes'     => implode( ', ', Token_Helper::get_scopes_from_pat( $options['pat'] ) ),
		);
		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			if ( false !== stripos( $msg, 'scope' ) ) {
				$msg = 'The token is present, but the selected Calendly API operation requires additional scopes.';
			}
			$diag['last_api_error'] = $msg;
			update_option( 'ctfb_diagnostics', $diag );
			return $msg;
		}

		$resource                  = isset( $response['resource'] ) ? $response['resource'] : array();
		$diag['connection_status'] = 'ok';
		$diag['last_api_error']    = '';
		$diag['user_name']         = isset( $resource['name'] ) ? $resource['name'] : '';
		$diag['user_email']        = isset( $resource['email'] ) ? $resource['email'] : '';
		$diag['organization_uri']  = isset( $resource['current_organization'] ) ? $resource['current_organization'] : '';
		update_option( 'ctfb_diagnostics', $diag );
		return 'Connection successful.';
	}

	private function perform_create_webhook_flow( $refresh ) {
		Logger::info( 'create_webhook_handler_started', array( 'refresh' => $refresh ? 'yes' : 'no' ), true );
		$options = get_option( 'ctfb_options', array() );
		Logger::debug( 'settings_loaded_for_create_webhook' );

		$context = $this->derive_webhook_context( $options );
		if ( ! empty( $context['error'] ) ) {
			Logger::error( 'create_webhook_context_resolution_failed', array( 'error' => $context['error'] ) );
			$this->update_webhook_diagnostics( 'failed', $context['error'] );
			$this->update_trace( 'last_create_webhook_api_error', $context['error'] );
			$this->update_trace( 'last_create_webhook_api_status', '0' );
			return array( 'is_error' => true, 'message' => $context['error'] );
		}

		$client      = new Calendly_Client( $context['token'] );
		$scope_mode  = $context['scope_mode'];
		$scope_order = array();
		if ( 'both' === $scope_mode ) {
			$scope_order = array( 'user', 'organization' );
		} elseif ( in_array( $scope_mode, array( 'user', 'organization' ), true ) ) {
			$scope_order = array( $scope_mode );
		}

		if ( empty( $scope_order ) ) {
			return array( 'is_error' => true, 'message' => 'Could not determine webhook scope mode.' );
		}

		if ( $refresh ) {
			$this->delete_known_webhooks_for_scopes( $client, $options, $scope_order );
		}

		$created_resources = array();
		foreach ( $scope_order as $scope ) {
			$payload = $this->build_webhook_payload( $scope, $context['user_uri'], $context['organization_uri'] );
			Logger::info( 'create_webhook_api_call_started', array( 'scope' => $scope ), true );
			Logger::info( 'create_webhook_request_payload_prepared', array( 'payload' => $payload ), true );

			$trace = array();
			$response = $client->create_webhook( $payload['url'], $scope, $context['user_uri'], $context['organization_uri'], $trace );
			Logger::info( 'create_webhook_api_response_received', array(
				'scope'             => $scope,
				'request_url'       => isset( $trace['request_url'] ) ? $trace['request_url'] : '',
				'request_method'    => isset( $trace['request_method'] ) ? $trace['request_method'] : 'POST',
				'auth_header'       => isset( $trace['has_auth_header'] ) ? $trace['has_auth_header'] : 'yes',
				'request_json_body' => isset( $trace['request_body'] ) ? $trace['request_body'] : '',
				'http_status_code'  => isset( $trace['http_status'] ) ? $trace['http_status'] : 0,
				'response_body'     => isset( $trace['response_body'] ) ? $trace['response_body'] : '',
				'parsed_result'     => isset( $trace['parsed_response'] ) ? $trace['parsed_response'] : '',
			), true );

			if ( is_wp_error( $response ) ) {
				$error_msg = $response->get_error_message();
				$this->update_webhook_diagnostics( 'failed', $error_msg );
				return array( 'is_error' => true, 'message' => 'Webhook creation failed: ' . $error_msg );
			}

			$created_resources[ $scope ] = isset( $response['resource'] ) && is_array( $response['resource'] ) ? $response['resource'] : array();
		}

		$webhook_state = $this->extract_webhook_state_from_creation_response( $created_resources, $context );
		$persisted     = $this->persist_webhook_state( $webhook_state );
		$this->update_webhook_diagnostics( 'success', '' );
		Logger::info( 'create_webhook_api_success', array( 'scope_mode' => $scope_mode, 'user_uri' => $persisted['webhook_user_subscription_uri'], 'organization_uri' => $persisted['webhook_organization_subscription_uri'] ), true );

		$message = $refresh ? 'Webhook refreshed successfully.' : 'Webhook created successfully.';
		return array( 'is_error' => false, 'message' => $message );
	}
	private function derive_webhook_context( $options ) {
		$token = isset( $options['pat'] ) ? $options['pat'] : '';
		if ( empty( $token ) ) {
			return array( 'error' => 'Missing Personal Access Token.' );
		}
		$user_uri = Token_Helper::get_user_uri_from_pat( $token );
		if ( empty( $user_uri ) ) {
			return array( 'error' => 'Malformed PAT: user_uuid claim is missing.' );
		}
		$client  = new Calendly_Client( $token );
		$org_uri = $this->get_organization_uri( $options, $client );
		if ( empty( $org_uri ) ) {
			return array( 'error' => 'Could not determine Calendly organization URI. Run Test Connection and try again.' );
		}
		$scope_mode = $this->determine_webhook_scope_mode( $options );
		return array(
			'token'            => $token,
			'user_uri'         => $user_uri,
			'organization_uri' => $org_uri,
			'scope_mode'       => $scope_mode,
		);
	}
	private function build_webhook_payload( $scope, $user_uri, $organization_uri ) {
		$payload = array(
			'url'    => rest_url( 'ctfb/v1/webhook' ),
			'events' => array( 'invitee.created', 'invitee.canceled' ),
			'scope'  => $scope,
		);
		if ( 'user' === $scope ) {
			$payload['user'] = $user_uri;
		} else {
			$payload['organization'] = $organization_uri;
		}
		return $payload;
	}
	private function extract_webhook_state_from_creation_response( $resources_by_scope, $context ) {
		$user_resource = isset( $resources_by_scope['user'] ) ? $resources_by_scope['user'] : array();
		$org_resource  = isset( $resources_by_scope['organization'] ) ? $resources_by_scope['organization'] : array();
		$user_subscription_uri = isset( $user_resource['uri'] ) ? esc_url_raw( (string) $user_resource['uri'] ) : '';
		$org_subscription_uri  = isset( $org_resource['uri'] ) ? esc_url_raw( (string) $org_resource['uri'] ) : '';
		$scope_mode            = isset( $context['scope_mode'] ) ? sanitize_text_field( (string) $context['scope_mode'] ) : 'user';
		$primary_uri           = '';
		if ( 'organization' === $scope_mode ) {
			$primary_uri = $org_subscription_uri;
		} elseif ( 'both' === $scope_mode ) {
			$primary_uri = ! empty( $org_subscription_uri ) ? $org_subscription_uri : $user_subscription_uri;
		} else {
			$primary_uri = $user_subscription_uri;
		}
		return array(
			'webhook_subscription_uri'              => $primary_uri,
			'webhook_scope'                         => $scope_mode,
			'webhook_scope_uri'                     => 'organization' === $scope_mode ? $context['organization_uri'] : $context['user_uri'],
			'webhook_user_uri'                      => $context['user_uri'],
			'webhook_organization_uri'              => $context['organization_uri'],
			'webhook_scope_mode'                    => $scope_mode,
			'webhook_user_active'                   => ! empty( $user_subscription_uri ) ? 1 : 0,
			'webhook_organization_active'           => ! empty( $org_subscription_uri ) ? 1 : 0,
			'webhook_user_subscription_uri'         => $user_subscription_uri,
			'webhook_organization_subscription_uri' => $org_subscription_uri,
		);
	}
	private function persist_webhook_state( $webhook_state ) {
		$options = get_option( 'ctfb_options', array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$keys = array(
			'webhook_subscription_uri',
			'webhook_scope',
			'webhook_scope_uri',
			'webhook_user_uri',
			'webhook_organization_uri',
			'webhook_scope_mode',
			'webhook_user_active',
			'webhook_organization_active',
			'webhook_user_subscription_uri',
			'webhook_organization_subscription_uri',
		);
		foreach ( $keys as $key ) {
			$options[ $key ] = isset( $webhook_state[ $key ] ) ? $webhook_state[ $key ] : '';
		}
		update_option( 'ctfb_options', $options );
		return get_option( 'ctfb_options', array() );
	}
	private function log_webhook_state_persistence_mismatch( $expected, $persisted ) {
		$keys = array(
			'webhook_subscription_uri',
			'webhook_scope',
			'webhook_scope_uri',
			'webhook_user_uri',
			'webhook_organization_uri',
			'webhook_scope_mode',
			'webhook_user_active',
			'webhook_organization_active',
			'webhook_user_subscription_uri',
			'webhook_organization_subscription_uri',
		);

		foreach ( $keys as $key ) {
			$expected_value  = isset( $expected[ $key ] ) ? (string) $expected[ $key ] : '';
			$persisted_value = isset( $persisted[ $key ] ) ? (string) $persisted[ $key ] : '';
			if ( '' !== $expected_value && '' === $persisted_value ) {
				Logger::error(
					'webhook_state_persistence_mismatch_detected',
					array(
						'key'            => $key,
						'expected_value' => $expected_value,
						'persisted_value' => $persisted_value,
					)
				);
			}
		}
	}

	private function determine_webhook_scope_mode( $options ) {
		$allowed = $this->get_allowed_event_types_from_options( $options );
		if ( empty( $allowed ) ) {
			return 'both';
		}
		$event_types = $this->get_available_event_types( $options );
		$list = isset( $event_types['event_types'] ) && is_array( $event_types['event_types'] ) ? $event_types['event_types'] : array();
		$has_user = false;
		$has_org = false;
		foreach ( $list as $item ) {
			$uri = isset( $item['uri'] ) ? esc_url_raw( (string) $item['uri'] ) : '';
			if ( empty( $uri ) || ! in_array( $uri, $allowed, true ) ) {
				continue;
			}
			$source = isset( $item['source'] ) ? sanitize_text_field( (string) $item['source'] ) : '';
			if ( false !== strpos( $source, 'organization' ) ) {
				$has_org = true;
			}
			if ( false !== strpos( $source, 'user' ) ) {
				$has_user = true;
			}
		}
		if ( $has_user && $has_org ) {
			return 'both';
		}
		if ( $has_org ) {
			return 'organization';
		}
		return 'user';
	}

	private function delete_known_webhooks_for_scopes( $client, $options, $scopes ) {
		foreach ( $scopes as $scope ) {
			$key = 'user' === $scope ? 'webhook_user_subscription_uri' : 'webhook_organization_subscription_uri';
			if ( ! empty( $options[ $key ] ) ) {
				$trace = array();
				$client->delete_webhook( $options[ $key ], $trace );
			}
		}
		if ( ! empty( $options['webhook_subscription_uri'] ) ) {
			$trace = array();
			$client->delete_webhook( $options['webhook_subscription_uri'], $trace );
		}
	}

	private function update_webhook_diagnostics( $result, $error ) {
		$diag                               = get_option( 'ctfb_diagnostics', array() );
		$diag['last_webhook_creation_result'] = sanitize_text_field( $result );
		$diag['last_webhook_creation_error']  = sanitize_text_field( $error );
		$diag['last_webhook_creation_time']   = current_time( 'mysql' );
		update_option( 'ctfb_diagnostics', $diag );
	}

	private function perform_delete_webhook( $client, $options ) {
		$targets = array();
		if ( ! empty( $options['webhook_subscription_uri'] ) ) {
			$targets[] = $options['webhook_subscription_uri'];
		}
		if ( ! empty( $options['webhook_user_subscription_uri'] ) ) {
			$targets[] = $options['webhook_user_subscription_uri'];
		}
		if ( ! empty( $options['webhook_organization_subscription_uri'] ) ) {
			$targets[] = $options['webhook_organization_subscription_uri'];
		}
		$targets = array_values( array_unique( $targets ) );
		if ( empty( $targets ) ) {
			return 'No stored webhook subscription URI to delete.';
		}
		foreach ( $targets as $uri ) {
			$trace = array();
			$client->delete_webhook( $uri, $trace );
		}
		$options['webhook_subscription_uri'] = '';
		$options['webhook_user_subscription_uri'] = '';
		$options['webhook_organization_subscription_uri'] = '';
		$options['webhook_user_active'] = 0;
		$options['webhook_organization_active'] = 0;
		update_option( 'ctfb_options', $options );
		return 'Webhook deleted successfully.';
	}

	public function render_page() {
		$options      = get_option( 'ctfb_options', array() );
		$diagnostics  = get_option( 'ctfb_diagnostics', array() );
		$allowed_event_type_uris = $this->get_allowed_event_types_from_options( $options );
		$booking_event_type_uris = $this->get_booking_event_types_from_options( $options );
		$event_type_response     = $this->get_available_event_types( $options );
		$event_type_options      = isset( $event_type_response['event_types'] ) && is_array( $event_type_response['event_types'] ) ? $event_type_response['event_types'] : array();
		$event_type_error        = isset( $event_type_response['error'] ) ? $event_type_response['error'] : '';
		$bookings     = $this->get_recent_bookings( $options );
		$debug_mode   = ! empty( $options['debug_logging'] );
		$log_path     = Logger::get_log_file_path();
		$log_exists   = file_exists( $log_path );
		$log_lines    = $debug_mode ? Logger::read_tail_lines( 50 ) : array();
		$trace_status = get_option( 'ctfb_admin_action_trace', array() );
		$payload_prev = get_option( 'ctfb_preview_create_webhook_payload', array() );

		$urls = array(
			'test_connection'  => wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_test_connection' ), 'ctfb_test_connection' ),
			'create_webhook'   => wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_create_webhook' ), 'ctfb_create_webhook' ),
			'refresh_webhook'  => wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_refresh_webhook' ), 'ctfb_refresh_webhook' ),
			'delete_webhook'   => wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_delete_webhook' ), 'ctfb_delete_webhook' ),
			'replay_last_live' => wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_replay_last_live_webhook' ), 'ctfb_replay_last_live_webhook' ),
		);
		?>
		<div class="wrap">
			<h1>Calendly to Formidable Bridge</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ctfb_settings_group' ); ?>
				<table class="form-table">
					<tr><th>Enable Sync</th><td><label><input type="checkbox" name="ctfb_options[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?> /> Enable Calendly webhook sync to Formidable.</label></td></tr>
					<tr><th>Debug logging</th><td><label><input type="checkbox" name="ctfb_options[debug_logging]" value="1" <?php checked( $debug_mode ); ?> /> Enable advanced diagnostics and debug logging.</label></td></tr>
					<tr><th>Calendly Personal Access Token</th><td><input type="password" name="ctfb_options[pat]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( isset( $options['pat'] ) && $options['pat'] ? $this->mask_pat( $options['pat'] ) : '' ); ?>" /></td></tr>
					<tr><th>Fallback Company Name</th><td><input type="text" name="ctfb_options[fallback_company_name]" value="<?php echo esc_attr( isset( $options['fallback_company_name'] ) ? $options['fallback_company_name'] : '' ); ?>" class="regular-text" /></td></tr>
					<tr><th>Fallback Freight Forwarder</th><td><select name="ctfb_options[fallback_freight_forwarder]"><option value="No" <?php selected( isset( $options['fallback_freight_forwarder'] ) ? $options['fallback_freight_forwarder'] : 'No', 'No' ); ?>>No</option><option value="Yes" <?php selected( isset( $options['fallback_freight_forwarder'] ) ? $options['fallback_freight_forwarder'] : 'No', 'Yes' ); ?>>Yes</option></select></td></tr>
					<tr><th>Default Country</th><td><input type="text" name="ctfb_options[default_country]" value="<?php echo esc_attr( isset( $options['default_country'] ) ? $options['default_country'] : '' ); ?>" class="regular-text" /></td></tr>
					<tr><th>Assigned Host Name Field ID</th><td><input type="text" name="ctfb_options[assigned_host_name_field_id]" value="<?php echo esc_attr( isset( $options['assigned_host_name_field_id'] ) ? $options['assigned_host_name_field_id'] : '' ); ?>" class="regular-text" /><p class="description">Formidable field ID where the assigned host name will be stored.</p></td></tr>
					<tr><th>Assigned Host Email Field ID</th><td><input type="text" name="ctfb_options[assigned_host_email_field_id]" value="<?php echo esc_attr( isset( $options['assigned_host_email_field_id'] ) ? $options['assigned_host_email_field_id'] : '' ); ?>" class="regular-text" /><p class="description">Formidable field ID where the assigned host email will be stored.</p></td></tr>
					<tr><th>Assigned Host User URI Field ID</th><td><input type="text" name="ctfb_options[assigned_host_user_uri_field_id]" value="<?php echo esc_attr( isset( $options['assigned_host_user_uri_field_id'] ) ? $options['assigned_host_user_uri_field_id'] : '' ); ?>" class="regular-text" /><p class="description">Formidable field ID where the assigned host user URI will be stored.</p></td></tr>
					<tr>
						<th>Allowed Event Types</th>
						<td>
							<?php if ( empty( $event_type_error ) && ! empty( $event_type_options ) ) : ?>
								<select name="ctfb_options[allowed_event_types][]" multiple="multiple" style="min-width: 420px; min-height: 140px;">
									<?php foreach ( $event_type_options as $event_type_option ) : ?>
										<?php $uri = isset( $event_type_option['uri'] ) ? $event_type_option['uri'] : ''; ?>
										<?php $label = isset( $event_type_option['label'] ) ? $event_type_option['label'] : ( isset( $event_type_option['name'] ) ? $event_type_option['name'] : $uri ); ?>
										<option value="<?php echo esc_attr( $uri ); ?>" <?php selected( in_array( $uri, $allowed_event_type_uris, true ) ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Leave empty to allow all event types.</p>
							<?php else : ?>
								<p><strong>Could not load event types from Calendly API.</strong></p>
								<p><?php echo esc_html( $event_type_error ); ?></p>
								<textarea name="ctfb_options[allowed_event_types_manual]" rows="6" style="min-width: 420px;"><?php echo esc_textarea( implode( "\n", $allowed_event_type_uris ) ); ?></textarea>
								<p class="description">Enter one event type URI per line. Leave empty to allow all event types.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Booking Display Mode</th>
						<td>
							<?php $display_mode = isset( $options['booking_display_mode'] ) ? $options['booking_display_mode'] : 'calendar'; ?>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="ctfb_options[booking_display_mode]" value="calendar" <?php checked( $display_mode, 'calendar' ); ?> />
								<strong>Calendar</strong> &mdash; Monthly grid, user picks a day then sees time slots
							</label>
							<label style="display:block;">
								<input type="radio" name="ctfb_options[booking_display_mode]" value="list" <?php checked( $display_mode, 'list' ); ?> />
								<strong>List</strong> &mdash; Week-based list of available slots, no calendar grid
							</label>
						</td>
					</tr>
					<tr>
						<th>Frontend Booking Event Types</th>
						<td>
							<?php if ( empty( $event_type_error ) && ! empty( $event_type_options ) ) : ?>
								<select name="ctfb_options[booking_event_types][]" multiple="multiple" style="min-width: 420px; min-height: 140px;">
									<?php foreach ( $event_type_options as $event_type_option ) : ?>
										<?php $uri = isset( $event_type_option['uri'] ) ? $event_type_option['uri'] : ''; ?>
										<?php $label = isset( $event_type_option['label'] ) ? $event_type_option['label'] : ( isset( $event_type_option['name'] ) ? $event_type_option['name'] : $uri ); ?>
										<option value="<?php echo esc_attr( $uri ); ?>" <?php selected( in_array( $uri, $booking_event_type_uris, true ) ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Select which meeting types appear on the <code>[ctfb_booking]</code> shortcode. Leave empty to show all active types.</p>
							<?php else : ?>
								<p><strong>Could not load event types from Calendly API.</strong></p>
								<textarea name="ctfb_options[booking_event_types_manual]" rows="4" style="min-width: 420px;"><?php echo esc_textarea( implode( "\n", $booking_event_type_uris ) ); ?></textarea>
								<p class="description">Enter one event type URI per line. Leave empty to show all active types.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr><th>Webhook endpoint URL</th><td><code><?php echo esc_html( rest_url( 'ctfb/v1/webhook' ) ); ?></code></td></tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<p><em><?php echo $debug_mode ? esc_html( 'Advanced diagnostics are enabled.' ) : esc_html( 'Advanced diagnostics are hidden while debug logging is disabled.' ); ?></em></p>

			<h2>Actions</h2>
			<p><a class="button" href="<?php echo esc_url( $urls['test_connection'] ); ?>">Test Connection</a></p>
			<p><a class="button" href="<?php echo esc_url( $urls['create_webhook'] ); ?>">Create Webhook</a></p>
			<p><a class="button" href="<?php echo esc_url( $urls['refresh_webhook'] ); ?>">Refresh Webhook</a></p>
			<p><a class="button" href="<?php echo esc_url( $urls['delete_webhook'] ); ?>">Delete Webhook</a></p>

			<h2>Recent Bookings</h2>
			<table class="widefat striped"><thead><tr><th>Invitee Name</th><th>Invitee Email</th><th>Event Name</th><th>Start Time</th><th>Status</th></tr></thead><tbody>
			<?php foreach ( $bookings as $row ) : ?>
				<tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( $row['email'] ); ?></td><td><?php echo esc_html( $row['event'] ); ?></td><td><?php echo esc_html( $row['start'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2>Basic Diagnostics</h2>
			<table class="widefat striped">
				<tr><td>Formidable active</td><td><?php echo class_exists( 'FrmEntry' ) ? 'Yes' : 'No'; ?></td></tr>
				<tr><td>Form ID 4 ready</td><td><?php echo class_exists( 'FrmForm' ) && \FrmForm::getOne( 4 ) ? 'Yes' : 'No'; ?></td></tr>
				<tr><td>Webhook endpoint URL</td><td><?php echo esc_html( rest_url( 'ctfb/v1/webhook' ) ); ?></td></tr>
				<tr><td>Stored webhook subscription URI or ID</td><td><?php echo esc_html( isset( $options['webhook_subscription_uri'] ) ? $options['webhook_subscription_uri'] : '' ); ?></td></tr>
				<tr><td>Stored webhook scope</td><td><?php echo esc_html( isset( $options['webhook_scope'] ) ? $options['webhook_scope'] : '' ); ?></td></tr>
				<tr><td>Stored webhook user URI</td><td><?php echo esc_html( isset( $options['webhook_user_uri'] ) ? $options['webhook_user_uri'] : '' ); ?></td></tr>
				<tr><td>Stored webhook organization URI</td><td><?php echo esc_html( isset( $options['webhook_organization_uri'] ) ? $options['webhook_organization_uri'] : '' ); ?></td></tr>
				<tr><td>Webhook scope mode</td><td><?php echo esc_html( isset( $options['webhook_scope_mode'] ) ? $options['webhook_scope_mode'] : '' ); ?></td></tr>
				<tr><td>User webhook active</td><td><?php echo ! empty( $options['webhook_user_active'] ) ? 'Yes' : 'No'; ?></td></tr>
				<tr><td>Organization webhook active</td><td><?php echo ! empty( $options['webhook_organization_active'] ) ? 'Yes' : 'No'; ?></td></tr>
				<tr><td>Active user webhook subscription URI</td><td><?php echo esc_html( isset( $options['webhook_user_subscription_uri'] ) ? $options['webhook_user_subscription_uri'] : '' ); ?></td></tr>
				<tr><td>Active organization webhook subscription URI</td><td><?php echo esc_html( isset( $options['webhook_organization_subscription_uri'] ) ? $options['webhook_organization_subscription_uri'] : '' ); ?></td></tr>
				<tr><td>Organization shared/team support enabled</td><td><?php echo esc_html( isset( $diagnostics['organization_shared_team_support_enabled'] ) ? $diagnostics['organization_shared_team_support_enabled'] : '' ); ?></td></tr>
				<tr><td>Last shared/team webhook received time</td><td><?php echo esc_html( isset( $diagnostics['last_shared_team_webhook_received_time'] ) ? $diagnostics['last_shared_team_webhook_received_time'] : '' ); ?></td></tr>
				<tr><td>Last shared/team webhook processed time</td><td><?php echo esc_html( isset( $diagnostics['last_shared_team_webhook_processed_time'] ) ? $diagnostics['last_shared_team_webhook_processed_time'] : '' ); ?></td></tr>
				<tr><td>Recent Bookings deduplication key used</td><td><?php echo esc_html( isset( $diagnostics['recent_bookings_deduplication_key_used'] ) ? $diagnostics['recent_bookings_deduplication_key_used'] : '' ); ?></td></tr>
				<tr><td>Connection status</td><td><?php echo esc_html( isset( $diagnostics['connection_status'] ) ? $diagnostics['connection_status'] : '' ); ?></td></tr>
				<tr><td>Last API check time</td><td><?php echo esc_html( isset( $diagnostics['last_api_check'] ) ? $diagnostics['last_api_check'] : '' ); ?></td></tr>
				<tr><td>Last API error</td><td><?php echo esc_html( isset( $diagnostics['last_api_error'] ) ? $diagnostics['last_api_error'] : '' ); ?></td></tr>
				<tr><td>Allowed event types count</td><td><?php echo esc_html( count( $allowed_event_type_uris ) ); ?></td></tr>
				<tr><td>Allowed event type URIs</td><td><?php echo esc_html( implode( ', ', $allowed_event_type_uris ) ); ?></td></tr>
				<tr><td>Assigned Host Name Field ID</td><td><?php echo esc_html( isset( $options['assigned_host_name_field_id'] ) ? $options['assigned_host_name_field_id'] : '' ); ?></td></tr>
				<tr><td>Assigned Host Email Field ID</td><td><?php echo esc_html( isset( $options['assigned_host_email_field_id'] ) ? $options['assigned_host_email_field_id'] : '' ); ?></td></tr>
				<tr><td>Assigned Host User URI Field ID</td><td><?php echo esc_html( isset( $options['assigned_host_user_uri_field_id'] ) ? $options['assigned_host_user_uri_field_id'] : '' ); ?></td></tr>
				<tr><td>Last assigned host name</td><td><?php echo esc_html( isset( $diagnostics['last_assigned_host_name'] ) ? $diagnostics['last_assigned_host_name'] : '' ); ?></td></tr>
				<tr><td>Last assigned host email</td><td><?php echo esc_html( isset( $diagnostics['last_assigned_host_email'] ) ? $diagnostics['last_assigned_host_email'] : '' ); ?></td></tr>
				<tr><td>Last assigned host user URI</td><td><?php echo esc_html( isset( $diagnostics['last_assigned_host_user_uri'] ) ? $diagnostics['last_assigned_host_user_uri'] : '' ); ?></td></tr>
				<tr><td>Last UTM source</td><td><?php echo esc_html( isset( $diagnostics['last_utm_source'] ) ? $diagnostics['last_utm_source'] : '' ); ?></td></tr>
				<tr><td>Last UTM medium</td><td><?php echo esc_html( isset( $diagnostics['last_utm_medium'] ) ? $diagnostics['last_utm_medium'] : '' ); ?></td></tr>
				<tr><td>Last UTM campaign</td><td><?php echo esc_html( isset( $diagnostics['last_utm_campaign'] ) ? $diagnostics['last_utm_campaign'] : '' ); ?></td></tr>
				<tr><td>Last UTM term</td><td><?php echo esc_html( isset( $diagnostics['last_utm_term'] ) ? $diagnostics['last_utm_term'] : '' ); ?></td></tr>
				<tr><td>Last UTM content</td><td><?php echo esc_html( isset( $diagnostics['last_utm_content'] ) ? $diagnostics['last_utm_content'] : '' ); ?></td></tr>
				<tr><td>Last combined source-id value</td><td><?php echo esc_html( isset( $diagnostics['last_combined_source_id_value'] ) ? $diagnostics['last_combined_source_id_value'] : '' ); ?></td></tr>
				<tr><td>Event type sources used</td><td><?php echo esc_html( isset( $diagnostics['event_type_sources_used'] ) ? $diagnostics['event_type_sources_used'] : '' ); ?></td></tr>
				<tr><td>Organization event types loaded count</td><td><?php echo esc_html( isset( $diagnostics['organization_event_types_loaded_count'] ) ? (int) $diagnostics['organization_event_types_loaded_count'] : 0 ); ?></td></tr>
				<tr><td>User event types loaded count</td><td><?php echo esc_html( isset( $diagnostics['user_event_types_loaded_count'] ) ? (int) $diagnostics['user_event_types_loaded_count'] : 0 ); ?></td></tr>
				<tr><td>Merged unique event types count</td><td><?php echo esc_html( isset( $diagnostics['merged_unique_event_types_count'] ) ? (int) $diagnostics['merged_unique_event_types_count'] : 0 ); ?></td></tr>
				<tr><td>Last successful sync time</td><td><?php echo esc_html( get_option( 'ctfb_last_successful_sync', '' ) ); ?></td></tr>
				<tr><td>Last processed email</td><td><?php echo esc_html( get_option( 'ctfb_last_processed_email', '' ) ); ?></td></tr>
				<tr><td>Last error</td><td><?php echo esc_html( get_option( 'ctfb_last_error', '' ) ); ?></td></tr>
			</table>

			<h2>Recent Sync Attempts</h2>
			<?php $this->render_logs_table(); ?>

			<h2>Manual Payload Test</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=ctfb_manual_test' ) ); ?>">
				<?php wp_nonce_field( 'ctfb_manual_test' ); ?>
				<textarea name="manual_payload" rows="8" style="width:100%;"></textarea>
				<p><label><input type="checkbox" name="commit_to_database" value="1" /> Commit to database</label></p>
				<?php submit_button( 'Run Manual Test' ); ?>
			</form>

			<?php if ( $debug_mode ) : ?>
				<h2>Advanced Diagnostics</h2>
				<table class="widefat striped">
					<tr><td>Ping endpoint URL</td><td><code><?php echo esc_html( rest_url( 'ctfb/v1/ping' ) ); ?></code></td></tr>
					<tr><td>Debug log file path</td><td><?php echo esc_html( $log_path ); ?></td></tr>
					<tr><td>Debug log file exists</td><td><?php echo $log_exists ? 'Yes' : 'No'; ?></td></tr>
					<tr><td>Debug log writable</td><td><?php echo $log_exists && is_writable( $log_path ) ? 'Yes' : 'No'; ?></td></tr>
					<tr><td>Debug log size</td><td><?php echo $log_exists ? esc_html( size_format( filesize( $log_path ) ) ) : ''; ?></td></tr>
					<tr><td>Debug log last modified</td><td><?php echo $log_exists ? esc_html( gmdate( 'Y-m-d H:i:s', filemtime( $log_path ) ) . ' UTC' ) : ''; ?></td></tr>
					<tr><td>Last webhook creation result</td><td><?php echo esc_html( isset( $diagnostics['last_webhook_creation_result'] ) ? $diagnostics['last_webhook_creation_result'] : '' ); ?></td></tr>
					<tr><td>Last webhook creation error</td><td><?php echo esc_html( isset( $diagnostics['last_webhook_creation_error'] ) ? $diagnostics['last_webhook_creation_error'] : '' ); ?></td></tr>
					<tr><td>Last fatal processing error</td><td><?php echo esc_html( isset( $diagnostics['last_fatal_processing_error'] ) ? $diagnostics['last_fatal_processing_error'] : '' ); ?></td></tr>
					<tr><td>Last fatal processing time</td><td><?php echo esc_html( isset( $diagnostics['last_fatal_processing_time'] ) ? $diagnostics['last_fatal_processing_time'] : '' ); ?></td></tr>
					<tr><td>Throwable class</td><td><?php echo esc_html( isset( $diagnostics['last_throwable_class'] ) ? $diagnostics['last_throwable_class'] : '' ); ?></td></tr>
					<tr><td>Throwable message</td><td><?php echo esc_html( isset( $diagnostics['last_throwable_message'] ) ? $diagnostics['last_throwable_message'] : '' ); ?></td></tr>
					<tr><td>Admin Action Trace</td><td><?php echo esc_html( wp_json_encode( $trace_status ) ); ?></td></tr>
				</table>

				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_test_create_webhook_handler' ), 'ctfb_test_create_webhook_handler' ) ); ?>">Test Create Webhook Handler</a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_preview_create_webhook_payload' ), 'ctfb_preview_create_webhook_payload' ) ); ?>">Create Webhook Payload Preview</a>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=ctfb_replay_last_live_webhook' ) ); ?>" style="margin:12px 0;">
					<?php wp_nonce_field( 'ctfb_replay_last_live_webhook' ); ?>
					<label><input type="checkbox" name="commit_to_database" value="1" /> Commit replay to database</label>
					<?php submit_button( 'Replay Last Live Webhook', 'secondary', 'submit', false ); ?>
				</form>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_clear_log' ), 'ctfb_clear_log' ) ); ?>">Clear Debug Log</a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ctfb_download_log' ), 'ctfb_download_log' ) ); ?>">Download Debug Log</a>
				</p>

				<?php if ( ! empty( $payload_prev ) ) : ?>
					<h3>Create Webhook Payload Preview</h3>
					<textarea rows="8" style="width:100%;" readonly="readonly"><?php echo esc_textarea( wp_json_encode( $payload_prev, JSON_PRETTY_PRINT ) ); ?></textarea>
				<?php endif; ?>

				<?php $last_raw_payload = get_option( 'ctfb_last_live_webhook_payload_raw', '' ); ?>
				<?php if ( ! empty( $last_raw_payload ) ) : ?>
					<h3>Raw Payload Preview</h3>
					<textarea rows="8" style="width:100%;" readonly="readonly"><?php echo esc_textarea( $last_raw_payload ); ?></textarea>
				<?php endif; ?>

				<h3>Debug Log Preview (Last 50 Lines)</h3>
				<textarea readonly="readonly" rows="12" style="width:100%;"><?php echo esc_textarea( implode( "\n", $log_lines ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_logs_table() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ctfb_logs ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Email</th><th>Action</th><th>Reason</th><th>Created</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->event_type ) . '</td><td>' . esc_html( $row->invitee_email ) . '</td><td>' . esc_html( $row->action_taken ) . '</td><td>' . esc_html( $row->failure_reason ) . '</td><td>' . esc_html( $row->created_at ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function get_recent_bookings( $options ) {
		$allowed_event_types = $this->get_allowed_event_types_from_options( $options );
		if ( empty( $options['pat'] ) ) {
			return array();
		}
		$client           = new Calendly_Client( $options['pat'] );
		$user_uri         = Token_Helper::get_user_uri_from_pat( $options['pat'] );
		$organization_uri = $this->get_organization_uri( $options, $client );
		$events_by_uri    = array();
		$dedupe_key       = 'scheduled_event_uri';

		if ( ! empty( $user_uri ) ) {
			$user_events = $client->get_scheduled_events( $user_uri, 25 );
			if ( ! is_wp_error( $user_events ) && ! empty( $user_events['collection'] ) && is_array( $user_events['collection'] ) ) {
				foreach ( $user_events['collection'] as $event ) {
					$event_uri = isset( $event['uri'] ) ? esc_url_raw( (string) $event['uri'] ) : '';
					if ( '' === $event_uri ) {
						continue;
					}
					$event['ctfb_event_source'] = 'user';
					$events_by_uri[ $event_uri ] = $event;
				}
			}
		}

		if ( ! empty( $organization_uri ) ) {
			$org_events = $client->get_scheduled_events_by_organization( $organization_uri, 25 );
			if ( ! is_wp_error( $org_events ) && ! empty( $org_events['collection'] ) && is_array( $org_events['collection'] ) ) {
				foreach ( $org_events['collection'] as $event ) {
					$event_uri = isset( $event['uri'] ) ? esc_url_raw( (string) $event['uri'] ) : '';
					if ( '' === $event_uri ) {
						continue;
					}
					$event['ctfb_event_source'] = 'organization';
					$events_by_uri[ $event_uri ] = $event;
				}
			}
		}

		$diag = get_option( 'ctfb_diagnostics', array() );
		if ( ! is_array( $diag ) ) {
			$diag = array();
		}
		$diag['recent_bookings_deduplication_key_used'] = $dedupe_key;
		update_option( 'ctfb_diagnostics', $diag );

		if ( empty( $events_by_uri ) ) {
			return array();
		}

		$rows = array();
		foreach ( $events_by_uri as $event ) {
			$event_uri      = isset( $event['uri'] ) ? esc_url_raw( (string) $event['uri'] ) : '';
			$event_name     = isset( $event['name'] ) ? sanitize_text_field( (string) $event['name'] ) : '';
			$event_type_uri = isset( $event['event_type'] ) ? esc_url_raw( (string) $event['event_type'] ) : '';
			$event_source   = isset( $event['ctfb_event_source'] ) ? sanitize_text_field( (string) $event['ctfb_event_source'] ) : 'unknown';
			$pooling_type   = isset( $event['pooling_type'] ) ? sanitize_text_field( (string) $event['pooling_type'] ) : '';
			$included       = true;
			$reason         = 'included';

			if ( ! empty( $allowed_event_types ) ) {
				if ( empty( $event_type_uri ) ) {
					$included = false;
					$reason   = 'missing_event_type';
				} elseif ( ! in_array( $event_type_uri, $allowed_event_types, true ) ) {
					$included = false;
					$reason   = 'not_allowed_event_type';
				}
			}

			Logger::debug( 'recent_booking_row_evaluated', array(
				'booking_event_uri'      => $event_uri,
				'booking_event_name'     => $event_name,
				'booking_event_type_uri' => $event_type_uri,
				'event_name'             => $event_name,
				'event_uri'              => $event_uri,
				'event_type_uri'         => $event_type_uri,
				'source'                 => $event_source,
				'event_source'           => $event_source,
				'pooling_type'           => $pooling_type,
				'included'               => $included ? 'yes' : 'no',
				'inclusion_decision'     => $reason,
			) );

			if ( ! $included ) {
				continue;
			}

			$inv     = $client->get_event_invitees( $event_uri, 1 );
			$invitee = ( ! is_wp_error( $inv ) && ! empty( $inv['collection'][0] ) ) ? $inv['collection'][0] : array();
			$rows[]  = array(
				'name'   => isset( $invitee['name'] ) ? $invitee['name'] : '',
				'email'  => isset( $invitee['email'] ) ? $invitee['email'] : '',
				'event'  => $event_name,
				'start'  => isset( $event['start_time'] ) ? $event['start_time'] : '',
				'status' => isset( $invitee['status'] ) ? $invitee['status'] : 'active',
			);
		}

		usort( $rows, function ( $a, $b ) { return strcmp( (string) $b['start'], (string) $a['start'] ); } );
		return array_slice( $rows, 0, 10 );
	}

	private function get_allowed_event_types_from_options( $options ) {
		$allowed = array();
		if ( empty( $options['allowed_event_types'] ) || ! is_array( $options['allowed_event_types'] ) ) {
			return $allowed;
		}

		foreach ( $options['allowed_event_types'] as $uri ) {
			$clean = esc_url_raw( trim( (string) $uri ) );
			if ( '' !== $clean ) {
				$allowed[] = $clean;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	private function get_booking_event_types_from_options( $options ) {
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

	private function get_available_event_types( $options ) {
		if ( empty( $options['pat'] ) ) {
			return array(
				'event_types' => array(),
				'error'       => 'Personal Access Token is required to load event types.',
				'diagnostics' => array(),
			);
		}

		$client           = new Calendly_Client( $options['pat'] );
		$user_uri         = Token_Helper::get_user_uri_from_pat( $options['pat'] );
		$organization_uri = $this->get_organization_uri( $options, $client );

		if ( empty( $user_uri ) && empty( $organization_uri ) ) {
			return array(
				'event_types' => array(),
				'error'       => 'Could not determine user or organization URI from Personal Access Token.',
				'diagnostics' => array(),
			);
		}

		$user_collection = array();
		$org_collection  = array();
		$error_messages  = array();

		if ( ! empty( $user_uri ) ) {
			$user_response = $client->get_event_types( $user_uri, 100 );
			if ( is_wp_error( $user_response ) ) {
				$error_messages[] = 'User event types: ' . $user_response->get_error_message();
			} else {
				$user_collection = isset( $user_response['collection'] ) && is_array( $user_response['collection'] ) ? $user_response['collection'] : array();
			}
		}

		if ( ! empty( $organization_uri ) ) {
			$org_response = $client->get_event_types_by_organization( $organization_uri, 100 );
			if ( is_wp_error( $org_response ) ) {
				$error_messages[] = 'Organization event types: ' . $org_response->get_error_message();
			} else {
				$org_collection = isset( $org_response['collection'] ) && is_array( $org_response['collection'] ) ? $org_response['collection'] : array();
			}
		}

		$merged_by_uri = array();
		foreach ( $user_collection as $item ) {
			$uri = isset( $item['uri'] ) ? esc_url_raw( (string) $item['uri'] ) : '';
			if ( '' === $uri ) {
				continue;
			}
			$item['ctfb_source']   = 'user';
			$merged_by_uri[ $uri ] = $item;
		}

		foreach ( $org_collection as $item ) {
			$uri = isset( $item['uri'] ) ? esc_url_raw( (string) $item['uri'] ) : '';
			if ( '' === $uri ) {
				continue;
			}
			if ( ! isset( $merged_by_uri[ $uri ] ) ) {
				$item['ctfb_source']   = 'organization';
				$merged_by_uri[ $uri ] = $item;
				continue;
			}

			$merged_by_uri[ $uri ]                = array_merge( $merged_by_uri[ $uri ], $item );
			$merged_by_uri[ $uri ]['ctfb_source'] = 'user+organization';
		}

		$event_types = array();
		foreach ( $merged_by_uri as $uri => $item ) {
			$name = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : $uri;
			$kind = $this->get_event_type_kind_label( $item );
			$source_label = isset( $item['ctfb_source'] ) ? sanitize_text_field( (string) $item['ctfb_source'] ) : 'unknown';
			$event_types[] = array(
				'uri'          => $uri,
				'name'         => $name,
				'label'        => $name . ' — ' . $kind . ' — ' . $source_label,
				'kind'         => $kind,
				'pooling_type' => isset( $item['pooling_type'] ) ? sanitize_text_field( (string) $item['pooling_type'] ) : '',
				'source'       => isset( $item['ctfb_source'] ) ? sanitize_text_field( (string) $item['ctfb_source'] ) : 'unknown',
			);
		}

		usort(
			$event_types,
			function ( $a, $b ) {
				return strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$diagnostics = array(
			'event_type_sources_used'               => trim( implode( ', ', array_filter( array( ! empty( $user_uri ) ? 'user' : '', ! empty( $organization_uri ) ? 'organization' : '' ) ) ), ', ' ),
			'user_event_types_loaded_count'         => count( $user_collection ),
			'organization_event_types_loaded_count' => count( $org_collection ),
			'merged_unique_event_types_count'       => count( $event_types ),
		);

		$this->persist_shared_support_diagnostics( $diagnostics );

		foreach ( $event_types as $event_type ) {
			Logger::debug(
				'event_type_loaded',
				array(
					'event_type_uri'  => $event_type['uri'],
					'event_type_name' => $event_type['name'],
					'pooling_type'    => $event_type['pooling_type'],
					'event_source'    => $event_type['source'],
				)
			);
		}

		$error = implode( ' | ', $error_messages );

		if ( empty( $event_types ) && '' === $error ) {
			$error = 'No event types were returned by Calendly for the available scopes.';
		}

		return array(
			'event_types' => $event_types,
			'error'       => $error,
			'diagnostics' => $diagnostics,
		);
	}


	private function get_organization_uri( $options, $client ) {
		$organization_uri = '';
		$me               = $client->get_users_me();
		if ( ! is_wp_error( $me ) && ! empty( $me['resource']['current_organization'] ) ) {
			$organization_uri = esc_url_raw( (string) $me['resource']['current_organization'] );
		}

		if ( '' === $organization_uri && ! empty( $options['webhook_organization_uri'] ) ) {
			$organization_uri = esc_url_raw( (string) $options['webhook_organization_uri'] );
		}

		return $organization_uri;
	}

	private function get_event_type_kind_label( $item ) {
		$pooling_type = isset( $item['pooling_type'] ) ? strtolower( sanitize_text_field( (string) $item['pooling_type'] ) ) : '';
		if ( 'round_robin' === $pooling_type ) {
			return 'Round Robin';
		}
		if ( 'collective' === $pooling_type ) {
			return 'Collective';
		}

		$kind = isset( $item['kind'] ) ? strtolower( sanitize_text_field( (string) $item['kind'] ) ) : '';
		if ( false !== strpos( $kind, 'collective' ) ) {
			return 'Collective';
		}
		if ( false !== strpos( $kind, 'group' ) ) {
			return 'Group';
		}

		return 'One-on-One';
	}

	private function persist_shared_support_diagnostics( $shared_diagnostics ) {
		$diag = get_option( 'ctfb_diagnostics', array() );
		if ( ! is_array( $diag ) ) {
			$diag = array();
		}

		$diag['event_type_sources_used']               = isset( $shared_diagnostics['event_type_sources_used'] ) ? $shared_diagnostics['event_type_sources_used'] : '';
		$diag['organization_event_types_loaded_count'] = isset( $shared_diagnostics['organization_event_types_loaded_count'] ) ? (int) $shared_diagnostics['organization_event_types_loaded_count'] : 0;
		$diag['user_event_types_loaded_count']         = isset( $shared_diagnostics['user_event_types_loaded_count'] ) ? (int) $shared_diagnostics['user_event_types_loaded_count'] : 0;
		$diag['merged_unique_event_types_count']       = isset( $shared_diagnostics['merged_unique_event_types_count'] ) ? (int) $shared_diagnostics['merged_unique_event_types_count'] : 0;
		$sources = isset( $shared_diagnostics['event_type_sources_used'] ) ? (string) $shared_diagnostics['event_type_sources_used'] : '';
		$diag['organization_shared_team_support_enabled'] = false !== strpos( strtolower( $sources ), 'organization' ) ? 'yes' : 'no';

		update_option( 'ctfb_diagnostics', $diag );
	}

	private function download_log_file() {
		$path = Logger::get_log_file_path();
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			$this->redirect_with_notice( 'Debug log file is not available.', true );
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ctfb-debug.txt"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	private function log_action_entry( $event_name, $action_name ) {
		Logger::info(
			$event_name,
			array(
				'current_user_id'      => get_current_user_id(),
				'request_uri'          => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
				'action_name'          => $action_name,
				'nonce_present'        => isset( $_REQUEST['_wpnonce'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'can_manage_options'   => current_user_can( 'manage_options' ) ? 'yes' : 'no',
			)
		);
	}

	private function redirect_with_notice( $message, $is_error ) {
		$type         = $is_error ? 'error' : 'success';
		$redirect_url = admin_url( 'options-general.php?page=calendly-to-formidable-bridge&ctfb_notice=' . rawurlencode( $message ) );
		Logger::info( 'redirect_with_notice', array( 'redirect_type' => $type, 'redirect_message' => $message, 'redirect_url' => $redirect_url ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	private function update_trace( $key, $value ) {
		$trace         = get_option( 'ctfb_admin_action_trace', array() );
		$trace[ $key ] = $value;
		update_option( 'ctfb_admin_action_trace', $trace );
	}

	private function mask_pat( $token ) {
		$token = trim( (string) $token );
		if ( strlen( $token ) < 8 ) {
			return 'Saved';
		}
		return substr( $token, 0, 4 ) . str_repeat( '*', max( strlen( $token ) - 8, 4 ) ) . substr( $token, -4 );
	}
}
