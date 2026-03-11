<?php

namespace CTFB\Admin;

use CTFB\API\Calendly_Client;
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
		$existing          = get_option( 'ctfb_options', array() );
		$sanitized         = array();
		$sanitized['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;
		$sanitized['debug_logging'] = ! empty( $input['debug_logging'] ) ? 1 : 0;
		$sanitized['fallback_company_name'] = isset( $input['fallback_company_name'] ) ? sanitize_text_field( $input['fallback_company_name'] ) : '';
		$sanitized['fallback_freight_forwarder'] = ( isset( $input['fallback_freight_forwarder'] ) && 'Yes' === $input['fallback_freight_forwarder'] ) ? 'Yes' : 'No';
		$sanitized['default_country'] = isset( $input['default_country'] ) ? sanitize_text_field( $input['default_country'] ) : '';
		$sanitized['pat'] = ! empty( $input['pat'] ) ? sanitize_text_field( $input['pat'] ) : ( isset( $existing['pat'] ) ? $existing['pat'] : '' );
		$sanitized['webhook_subscription_uri'] = isset( $existing['webhook_subscription_uri'] ) ? $existing['webhook_subscription_uri'] : '';
		$sanitized['webhook_scope'] = isset( $existing['webhook_scope'] ) ? $existing['webhook_scope'] : 'user';
		$sanitized['webhook_scope_uri'] = isset( $existing['webhook_scope_uri'] ) ? $existing['webhook_scope_uri'] : '';
		return $sanitized;
	}

	public function activation_notice() {
		if ( get_option( 'ctfb_activation_notice' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Calendly to Formidable Bridge activated.</p></div>';
			delete_option( 'ctfb_activation_notice' );
		}
	}

	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( 'ctfb_action_nonce' );

		$action  = isset( $_POST['ctfb_subaction'] ) ? sanitize_text_field( wp_unslash( $_POST['ctfb_subaction'] ) ) : '';
		$options = get_option( 'ctfb_options', array() );
		$client  = new Calendly_Client( isset( $options['pat'] ) ? $options['pat'] : '' );
		$notice  = '';

		if ( 'test_connection' === $action ) {
			$notice = $this->test_connection( $client, $options );
		}
		if ( 'create_webhook' === $action ) {
			$notice = $this->create_webhook( $client, $options, false );
		}
		if ( 'refresh_webhook' === $action ) {
			$notice = $this->create_webhook( $client, $options, true );
		}
		if ( 'delete_webhook' === $action ) {
			$notice = $this->delete_webhook( $client, $options );
		}
		if ( 'manual_test' === $action ) {
			$payload = isset( $_POST['manual_payload'] ) ? wp_unslash( $_POST['manual_payload'] ) : '';
			$data    = json_decode( $payload, true );
			if ( ! is_array( $data ) ) {
				$notice = 'Manual payload is not valid JSON.';
			} else {
				$commit = ! empty( $_POST['commit_to_database'] );
				$sync   = new Formidable_Sync();
				$result = $sync->process( $data, $commit );
				$notice = $result['message'];
			}
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=calendly-to-formidable-bridge&ctfb_notice=' . rawurlencode( $notice ) ) );
		exit;
	}

	private function test_connection( $client, $options ) {
		if ( empty( $options['pat'] ) ) {
			update_option( 'ctfb_diagnostics', array( 'last_api_error' => 'Missing Personal Access Token.', 'last_api_check' => current_time( 'mysql' ) ) );
			return 'Missing Personal Access Token.';
		}
		$uuid = Token_Helper::get_user_uuid_from_pat( $options['pat'] );
		if ( empty( $uuid ) ) {
			update_option( 'ctfb_diagnostics', array( 'last_api_error' => 'Malformed PAT: user_uuid claim is missing.', 'last_api_check' => current_time( 'mysql' ) ) );
			return 'Malformed PAT: user_uuid claim is missing.';
		}
		$response = $client->get_users_me();
		$diag     = array(
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

		$resource = isset( $response['resource'] ) ? $response['resource'] : array();
		$diag['connection_status'] = 'ok';
		$diag['last_api_error']    = '';
		$diag['user_name']         = isset( $resource['name'] ) ? $resource['name'] : '';
		$diag['user_email']        = isset( $resource['email'] ) ? $resource['email'] : '';
		$diag['organization_uri']  = isset( $resource['current_organization'] ) ? $resource['current_organization'] : '';
		update_option( 'ctfb_diagnostics', $diag );
		return 'Connection successful.';
	}

	private function create_webhook( $client, $options, $refresh ) {
		if ( empty( $options['pat'] ) ) {
			return 'Missing Personal Access Token.';
		}
		if ( $refresh && ! empty( $options['webhook_subscription_uri'] ) ) {
			$client->delete_webhook( $options['webhook_subscription_uri'] );
		}
		$scope_uri = Token_Helper::get_user_uri_from_pat( $options['pat'] );
		if ( empty( $scope_uri ) ) {
			return 'Malformed PAT: user_uuid claim is missing.';
		}
		$response = $client->create_webhook( rest_url( 'ctfb/v1/webhook' ), 'user', $scope_uri );
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$resource = isset( $response['resource'] ) ? $response['resource'] : array();
		$options['webhook_subscription_uri'] = isset( $resource['uri'] ) ? $resource['uri'] : '';
		$options['webhook_scope']            = isset( $resource['scope'] ) ? $resource['scope'] : 'user';
		$options['webhook_scope_uri']        = $scope_uri;
		update_option( 'ctfb_options', $options );
		return $refresh ? 'Webhook refreshed successfully.' : 'Webhook created successfully.';
	}

	private function delete_webhook( $client, $options ) {
		if ( ! empty( $options['webhook_subscription_uri'] ) ) {
			$client->delete_webhook( $options['webhook_subscription_uri'] );
		}
		$options['webhook_subscription_uri'] = '';
		$options['webhook_scope_uri']        = '';
		update_option( 'ctfb_options', $options );
		return 'Webhook deleted successfully.';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options     = get_option( 'ctfb_options', array() );
		$diagnostics = get_option( 'ctfb_diagnostics', array() );
		$bookings    = $this->get_recent_bookings( $options );
		?>
		<div class="wrap">
			<h1>Calendly to Formidable Bridge</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ctfb_settings_group' ); ?>
				<table class="form-table">
					<tr><th>Enable sync</th><td><input type="checkbox" name="ctfb_options[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?> /></td></tr>
					<tr><th>Calendly Personal Access Token</th><td><input type="password" name="ctfb_options[pat]" class="regular-text" value="" placeholder="<?php echo esc_attr( $this->mask_pat( isset( $options['pat'] ) ? $options['pat'] : '' ) ); ?>" /></td></tr>
					<tr><th>Debug logging</th><td><input type="checkbox" name="ctfb_options[debug_logging]" value="1" <?php checked( ! empty( $options['debug_logging'] ) ); ?> /></td></tr>
					<tr><th>Fallback company name</th><td><input type="text" name="ctfb_options[fallback_company_name]" class="regular-text" value="<?php echo esc_attr( isset( $options['fallback_company_name'] ) ? $options['fallback_company_name'] : '' ); ?>" /></td></tr>
					<tr><th>Fallback freight forwarder</th><td><select name="ctfb_options[fallback_freight_forwarder]"><option value="Yes" <?php selected( isset( $options['fallback_freight_forwarder'] ) ? $options['fallback_freight_forwarder'] : 'No', 'Yes' ); ?>>Yes</option><option value="No" <?php selected( isset( $options['fallback_freight_forwarder'] ) ? $options['fallback_freight_forwarder'] : 'No', 'No' ); ?>>No</option></select></td></tr>
					<tr><th>Default country</th><td><input type="text" name="ctfb_options[default_country]" class="regular-text" value="<?php echo esc_attr( isset( $options['default_country'] ) ? $options['default_country'] : '' ); ?>" /></td></tr>
					<tr><th>Webhook endpoint URL</th><td><code><?php echo esc_html( rest_url( 'ctfb/v1/webhook' ) ); ?></code><p class="description">Calendly sends booking event data to this webhook URL.</p></td></tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<h2>Actions</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ctfb_action_nonce' ); ?>
				<input type="hidden" name="action" value="ctfb_action" />
				<button class="button" name="ctfb_subaction" value="test_connection">Test Connection</button>
				<button class="button" name="ctfb_subaction" value="create_webhook">Create Webhook</button>
				<button class="button" name="ctfb_subaction" value="refresh_webhook">Refresh Webhook</button>
				<button class="button" name="ctfb_subaction" value="delete_webhook">Delete Webhook</button>
			</form>

			<h2>Recent Bookings</h2>
			<table class="widefat striped"><thead><tr><th>Invitee Name</th><th>Invitee Email</th><th>Event Name</th><th>Start Time</th><th>Status</th></tr></thead><tbody>
			<?php foreach ( $bookings as $row ) : ?>
				<tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( $row['email'] ); ?></td><td><?php echo esc_html( $row['event'] ); ?></td><td><?php echo esc_html( $row['start'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2>Diagnostics</h2>
			<table class="widefat striped">
			<tr><td>Formidable active</td><td><?php echo class_exists( 'FrmEntry' ) ? 'Yes' : 'No'; ?></td></tr>
			<tr><td>Form ID 4 ready</td><td><?php echo class_exists( 'FrmForm' ) && \FrmForm::getOne( 4 ) ? 'Yes' : 'No'; ?></td></tr>
			<tr><td>Webhook endpoint URL</td><td><?php echo esc_html( rest_url( 'ctfb/v1/webhook' ) ); ?></td></tr>
			<tr><td>Stored webhook subscription URI or ID</td><td><?php echo esc_html( isset( $options['webhook_subscription_uri'] ) ? $options['webhook_subscription_uri'] : '' ); ?></td></tr>
			<tr><td>Connection status</td><td><?php echo esc_html( isset( $diagnostics['connection_status'] ) ? $diagnostics['connection_status'] : 'unknown' ); ?></td></tr>
			<tr><td>Last API check time</td><td><?php echo esc_html( isset( $diagnostics['last_api_check'] ) ? $diagnostics['last_api_check'] : '' ); ?></td></tr>
			<tr><td>Last API error</td><td><?php echo esc_html( isset( $diagnostics['last_api_error'] ) ? $diagnostics['last_api_error'] : '' ); ?></td></tr>
			<tr><td>Recent bookings count</td><td><?php echo esc_html( count( $bookings ) ); ?></td></tr>
			<tr><td>Last successful sync time</td><td><?php echo esc_html( get_option( 'ctfb_last_successful_sync', '' ) ); ?></td></tr>
			<tr><td>Last error</td><td><?php echo esc_html( get_option( 'ctfb_last_error', '' ) ); ?></td></tr>
			<tr><td>Last processed email</td><td><?php echo esc_html( get_option( 'ctfb_last_processed_email', '' ) ); ?></td></tr>
			<tr><td>PAT user UUID if extracted</td><td><?php echo esc_html( Token_Helper::get_user_uuid_from_pat( isset( $options['pat'] ) ? $options['pat'] : '' ) ); ?></td></tr>
			<tr><td>PAT scopes if extracted</td><td><?php echo esc_html( implode( ', ', Token_Helper::get_scopes_from_pat( isset( $options['pat'] ) ? $options['pat'] : '' ) ) ); ?></td></tr>
			</table>

			<h3>Recent sync attempts table</h3>
			<?php $this->render_logs_table(); ?>

			<h2>Manual Payload Test</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ctfb_action_nonce' ); ?>
				<input type="hidden" name="action" value="ctfb_action" />
				<input type="hidden" name="ctfb_subaction" value="manual_test" />
				<textarea name="manual_payload" rows="8" style="width:100%;"></textarea>
				<p><label><input type="checkbox" name="commit_to_database" value="1" /> Commit to database</label></p>
				<?php submit_button( 'Run Manual Test' ); ?>
			</form>
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
			$inv = $client->get_event_invitees( isset( $event['uri'] ) ? $event['uri'] : '', 1 );
			$invitee = ( ! is_wp_error( $inv ) && ! empty( $inv['collection'][0] ) ) ? $inv['collection'][0] : array();
			$rows[] = array(
				'name'   => isset( $invitee['name'] ) ? $invitee['name'] : '',
				'email'  => isset( $invitee['email'] ) ? $invitee['email'] : '',
				'event'  => isset( $event['name'] ) ? $event['name'] : '',
				'start'  => isset( $event['start_time'] ) ? $event['start_time'] : '',
				'status' => isset( $invitee['status'] ) ? $invitee['status'] : 'active',
			);
		}
		return $rows;
	}

	private function mask_pat( $token ) {
		$token = trim( (string) $token );
		if ( strlen( $token ) < 8 ) {
			return 'Saved';
		}
		return substr( $token, 0, 4 ) . str_repeat( '*', max( strlen( $token ) - 8, 4 ) ) . substr( $token, -4 );
	}
}
