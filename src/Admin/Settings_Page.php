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
		return $sanitized;
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

		$payload = $this->build_webhook_payload( $context['user_uri'], $context['organization_uri'] );
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

		$client  = new Calendly_Client( $context['token'] );
		$payload = $this->build_webhook_payload( $context['user_uri'], $context['organization_uri'] );

		if ( $refresh && ! empty( $options['webhook_subscription_uri'] ) ) {
			$delete_trace = array();
			$client->delete_webhook( $options['webhook_subscription_uri'], $delete_trace );
		}

		Logger::info( 'create_webhook_api_call_started', array(), true );
		Logger::info( 'create_webhook_request_payload_prepared', array( 'payload' => $payload ), true );

		$trace    = array();
		$response = $client->create_webhook( $payload['url'], 'user', $context['user_uri'], $context['organization_uri'], $trace );
		Logger::info(
			'create_webhook_api_response_received',
			array(
				'request_url'       => isset( $trace['request_url'] ) ? $trace['request_url'] : '',
				'request_method'    => isset( $trace['request_method'] ) ? $trace['request_method'] : 'POST',
				'auth_header'       => isset( $trace['has_auth_header'] ) ? $trace['has_auth_header'] : 'yes',
				'request_json_body' => isset( $trace['request_body'] ) ? $trace['request_body'] : '',
				'http_status_code'  => isset( $trace['http_status'] ) ? $trace['http_status'] : 0,
				'response_body'     => isset( $trace['response_body'] ) ? $trace['response_body'] : '',
				'parsed_result'     => isset( $trace['parsed_response'] ) ? $trace['parsed_response'] : '',
			),
			true
		);

		if ( is_wp_error( $response ) ) {
			$http_status = isset( $trace['http_status'] ) ? (string) $trace['http_status'] : '0';
			$parsed_body = isset( $trace['parsed_response'] ) ? $trace['parsed_response'] : array();
			$error_msg   = $response->get_error_message();
			Logger::error( 'create_webhook_api_failed', array( 'http_status' => $http_status, 'parsed_response' => $parsed_body, 'error_message' => $error_msg ) );
			$this->update_trace( 'last_create_webhook_api_status', $http_status );
			$this->update_trace( 'last_create_webhook_api_error', $error_msg );
			$this->update_webhook_diagnostics( 'failed', $error_msg );
			return array( 'is_error' => true, 'message' => 'Webhook creation failed: ' . $error_msg );
		}

		$resource         = isset( $response['resource'] ) ? $response['resource'] : array();
		$subscription_uri = isset( $resource['uri'] ) ? esc_url_raw( $resource['uri'] ) : '';
		$subscription_id  = isset( $resource['id'] ) ? sanitize_text_field( $resource['id'] ) : '';
		$saved_id_or_uri  = ! empty( $subscription_uri ) ? $subscription_uri : $subscription_id;

		$options['webhook_subscription_uri'] = $saved_id_or_uri;
		$options['webhook_scope']            = isset( $resource['scope'] ) ? sanitize_text_field( $resource['scope'] ) : 'user';
		$options['webhook_scope_uri']        = $context['user_uri'];
		$options['webhook_user_uri']         = $context['user_uri'];
		$options['webhook_organization_uri'] = $context['organization_uri'];

		Logger::debug( 'webhook_settings_persist_started' );
		update_option( 'ctfb_options', $options );
		$persisted = get_option( 'ctfb_options', array() );
		Logger::debug(
			'webhook_settings_persist_completed',
			array(
				'saved_webhook_subscription_uri' => isset( $persisted['webhook_subscription_uri'] ) ? $persisted['webhook_subscription_uri'] : '',
				'saved_webhook_user_uri'         => isset( $persisted['webhook_user_uri'] ) ? $persisted['webhook_user_uri'] : '',
				'saved_webhook_organization_uri' => isset( $persisted['webhook_organization_uri'] ) ? $persisted['webhook_organization_uri'] : '',
			)
		);
		Logger::debug( 'saved_webhook_subscription_uri', array( 'value' => isset( $persisted['webhook_subscription_uri'] ) ? $persisted['webhook_subscription_uri'] : '' ) );
		Logger::debug( 'saved_webhook_user_uri', array( 'value' => isset( $persisted['webhook_user_uri'] ) ? $persisted['webhook_user_uri'] : '' ) );
		Logger::debug( 'saved_webhook_organization_uri', array( 'value' => isset( $persisted['webhook_organization_uri'] ) ? $persisted['webhook_organization_uri'] : '' ) );

		$this->update_trace( 'last_create_webhook_api_status', isset( $trace['http_status'] ) ? (string) $trace['http_status'] : '200' );
		$this->update_trace( 'last_create_webhook_api_error', '' );
		$this->update_trace( 'last_saved_webhook_subscription_uri', $saved_id_or_uri );
		$this->update_webhook_diagnostics( 'success', '' );

		Logger::info( 'create_webhook_api_success', array( 'subscription' => $saved_id_or_uri, 'scope' => $options['webhook_scope'], 'scope_uri' => $options['webhook_scope_uri'], 'organization_uri' => $options['webhook_organization_uri'] ), true );

		$message = $refresh ? 'Webhook refreshed successfully.' : 'Webhook created successfully.';
		return array( 'is_error' => false, 'message' => $message );
	}

	private function derive_webhook_context( $options ) {
		$token = isset( $options['pat'] ) ? $options['pat'] : '';
		Logger::debug( 'token_present_yes_or_no', array( 'present' => empty( $token ) ? 'no' : 'yes' ) );
		Logger::debug( 'token_masked_preview', array( 'pat' => $this->mask_pat( $token ) ) );
		if ( empty( $token ) ) {
			return array( 'error' => 'Missing Personal Access Token.' );
		}

		$user_uri = Token_Helper::get_user_uri_from_pat( $token );
		if ( empty( $user_uri ) ) {
			return array( 'error' => 'Malformed PAT: user_uuid claim is missing.' );
		}

		$org_uri = '';
		$client  = new Calendly_Client( $token );
		$me      = $client->get_users_me();
		if ( ! is_wp_error( $me ) && ! empty( $me['resource']['current_organization'] ) ) {
			$org_uri = esc_url_raw( $me['resource']['current_organization'] );
		} elseif ( ! empty( $options['webhook_organization_uri'] ) ) {
			$org_uri = esc_url_raw( $options['webhook_organization_uri'] );
			Logger::warning( 'organization_uri_reused_from_saved_settings', array( 'organization_uri' => $org_uri ) );
		}

		if ( empty( $org_uri ) ) {
			return array( 'error' => 'Could not determine Calendly organization URI. Run Test Connection and try again.' );
		}

		return array(
			'token'            => $token,
			'user_uri'         => $user_uri,
			'organization_uri' => $org_uri,
		);
	}

	private function build_webhook_payload( $user_uri, $organization_uri ) {
		return array(
			'url'          => rest_url( 'ctfb/v1/webhook' ),
			'events'       => array( 'invitee.created', 'invitee.canceled' ),
			'scope'        => 'user',
			'user'         => $user_uri,
			'organization' => $organization_uri,
		);
	}

	private function update_webhook_diagnostics( $result, $error ) {
		$diag                               = get_option( 'ctfb_diagnostics', array() );
		$diag['last_webhook_creation_result'] = sanitize_text_field( $result );
		$diag['last_webhook_creation_error']  = sanitize_text_field( $error );
		$diag['last_webhook_creation_time']   = current_time( 'mysql' );
		update_option( 'ctfb_diagnostics', $diag );
	}

	private function perform_delete_webhook( $client, $options ) {
		if ( empty( $options['webhook_subscription_uri'] ) ) {
			return 'No stored webhook subscription URI to delete.';
		}
		$trace    = array();
		$response = $client->delete_webhook( $options['webhook_subscription_uri'], $trace );
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$options['webhook_subscription_uri'] = '';
		update_option( 'ctfb_options', $options );
		return 'Webhook deleted successfully.';
	}

	public function render_page() {
		$options      = get_option( 'ctfb_options', array() );
		$diagnostics  = get_option( 'ctfb_diagnostics', array() );
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
				<tr><td>Connection status</td><td><?php echo esc_html( isset( $diagnostics['connection_status'] ) ? $diagnostics['connection_status'] : '' ); ?></td></tr>
				<tr><td>Last API check time</td><td><?php echo esc_html( isset( $diagnostics['last_api_check'] ) ? $diagnostics['last_api_check'] : '' ); ?></td></tr>
				<tr><td>Last API error</td><td><?php echo esc_html( isset( $diagnostics['last_api_error'] ) ? $diagnostics['last_api_error'] : '' ); ?></td></tr>
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
		if ( empty( $options['pat'] ) ) {
			return array();
		}
		$client   = new Calendly_Client( $options['pat'] );
		$user_uri = Token_Helper::get_user_uri_from_pat( $options['pat'] );
		if ( empty( $user_uri ) ) {
			return array();
		}
		$events = $client->get_scheduled_events( $user_uri, 10 );
		if ( is_wp_error( $events ) || empty( $events['collection'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $events['collection'] as $event ) {
			$inv     = $client->get_event_invitees( isset( $event['uri'] ) ? $event['uri'] : '', 1 );
			$invitee = ( ! is_wp_error( $inv ) && ! empty( $inv['collection'][0] ) ) ? $inv['collection'][0] : array();
			$rows[]  = array(
				'name'   => isset( $invitee['name'] ) ? $invitee['name'] : '',
				'email'  => isset( $invitee['email'] ) ? $invitee['email'] : '',
				'event'  => isset( $event['name'] ) ? $event['name'] : '',
				'start'  => isset( $event['start_time'] ) ? $event['start_time'] : '',
				'status' => isset( $invitee['status'] ) ? $invitee['status'] : 'active',
			);
		}
		return $rows;
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
