<?php

namespace CalendlyToFormidableBridge\Admin;

use CalendlyToFormidableBridge\API\Calendly_Client;
use CalendlyToFormidableBridge\Support\Logger;

/**
 * Settings admin page.
 */
class Settings_Page {
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'Calendly to Formidable', 'calendly-to-formidable-bridge' ),
			__( 'Calendly to Formidable', 'calendly-to-formidable-bridge' ),
			'manage_options',
			'ctfb-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Register option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'ctfb_settings_group', 'ctfb_settings', array( $this, 'sanitize_settings' ) );
	}

	/**
	 * Sanitize settings.
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( 'ctfb_settings', array() );
		$output   = array();
		$output['enable_sync']                = empty( $input['enable_sync'] ) ? 0 : 1;
		$output['debug_logging']              = empty( $input['debug_logging'] ) ? 0 : 1;
		$output['fallback_company_name']      = isset( $input['fallback_company_name'] ) ? sanitize_text_field( $input['fallback_company_name'] ) : 'Not Provided';
		$output['fallback_freight_forwarder'] = ( isset( $input['fallback_freight_forwarder'] ) && 'Yes' === $input['fallback_freight_forwarder'] ) ? 'Yes' : 'No';
		$output['default_country']            = isset( $input['default_country'] ) ? sanitize_text_field( $input['default_country'] ) : '';
		$output['calendly_token']             = isset( $input['calendly_token'] ) && '' !== trim( $input['calendly_token'] ) ? sanitize_text_field( $input['calendly_token'] ) : ( isset( $existing['calendly_token'] ) ? $existing['calendly_token'] : '' );
		$output['webhook_signing_key']        = isset( $input['webhook_signing_key'] ) && '' !== trim( $input['webhook_signing_key'] ) ? sanitize_text_field( $input['webhook_signing_key'] ) : ( isset( $existing['webhook_signing_key'] ) ? $existing['webhook_signing_key'] : '' );
		$output['webhook_subscription_id']    = isset( $existing['webhook_subscription_id'] ) ? sanitize_text_field( $existing['webhook_subscription_id'] ) : '';
		$output['webhook_scope']              = isset( $existing['webhook_scope'] ) ? sanitize_text_field( $existing['webhook_scope'] ) : '';
		$output['webhook_scope_uri']          = isset( $existing['webhook_scope_uri'] ) ? esc_url_raw( $existing['webhook_scope_uri'] ) : '';

		return $output;
	}

	/**
	 * Render page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = get_option( 'ctfb_settings', array() );
		$webhook_url = rest_url( 'ctfb/v1/webhook' );

		if ( isset( $_GET['ctfb_nt'], $_GET['ctfb_msg'] ) ) {
			$type = 'success' === sanitize_text_field( wp_unslash( $_GET['ctfb_nt'] ) ) ? 'notice-success' : 'notice-error';
			$msg  = sanitize_text_field( wp_unslash( $_GET['ctfb_msg'] ) );
			echo '<div class="notice ' . esc_attr( $type ) . '"><p>' . esc_html( rawurldecode( $msg ) ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Calendly to Formidable Bridge', 'calendly-to-formidable-bridge' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ctfb_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable sync', 'calendly-to-formidable-bridge' ); ?></th>
						<td><label><input type="checkbox" name="ctfb_settings[enable_sync]" value="1" <?php checked( ! empty( $settings['enable_sync'] ) ); ?> /> <?php echo esc_html__( 'Process incoming webhooks', 'calendly-to-formidable-bridge' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Calendly Personal Access Token', 'calendly-to-formidable-bridge' ); ?></th>
						<td><input type="password" class="regular-text" name="ctfb_settings[calendly_token]" placeholder="<?php echo esc_attr( $this->mask_value( isset( $settings['calendly_token'] ) ? $settings['calendly_token'] : '' ) ); ?>" value="" autocomplete="new-password" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Calendly webhook signing key', 'calendly-to-formidable-bridge' ); ?></th>
						<td><input type="password" class="regular-text" name="ctfb_settings[webhook_signing_key]" placeholder="<?php echo esc_attr( $this->mask_value( isset( $settings['webhook_signing_key'] ) ? $settings['webhook_signing_key'] : '' ) ); ?>" value="" autocomplete="new-password" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Debug logging', 'calendly-to-formidable-bridge' ); ?></th>
						<td><label><input type="checkbox" name="ctfb_settings[debug_logging]" value="1" <?php checked( ! empty( $settings['debug_logging'] ) ); ?> /> <?php echo esc_html__( 'Enable plugin debug logs', 'calendly-to-formidable-bridge' ); ?></label></td>
					</tr>
					<tr><th scope="row"><?php echo esc_html__( 'Fallback company name', 'calendly-to-formidable-bridge' ); ?></th><td><input type="text" class="regular-text" name="ctfb_settings[fallback_company_name]" value="<?php echo esc_attr( isset( $settings['fallback_company_name'] ) ? $settings['fallback_company_name'] : 'Not Provided' ); ?>" /></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Fallback freight forwarder', 'calendly-to-formidable-bridge' ); ?></th><td><select name="ctfb_settings[fallback_freight_forwarder]"><option value="Yes" <?php selected( isset( $settings['fallback_freight_forwarder'] ) ? $settings['fallback_freight_forwarder'] : 'No', 'Yes' ); ?>>Yes</option><option value="No" <?php selected( isset( $settings['fallback_freight_forwarder'] ) ? $settings['fallback_freight_forwarder'] : 'No', 'No' ); ?>>No</option></select></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Default country', 'calendly-to-formidable-bridge' ); ?></th><td><input type="text" class="regular-text" name="ctfb_settings[default_country]" value="<?php echo esc_attr( isset( $settings['default_country'] ) ? $settings['default_country'] : '' ); ?>" /></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Webhook endpoint URL', 'calendly-to-formidable-bridge' ); ?></th><td><code><?php echo esc_html( $webhook_url ); ?></code></td></tr>
				</table>
				<?php submit_button( __( 'Save settings', 'calendly-to-formidable-bridge' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Webhook management', 'calendly-to-formidable-bridge' ); ?></h2>
			<p>
				<?php $this->render_webhook_button( 'ctfb_create_webhook', 'Create webhook' ); ?>
				<?php $this->render_webhook_button( 'ctfb_refresh_webhook', 'Refresh webhook' ); ?>
				<?php $this->render_webhook_button( 'ctfb_delete_webhook', 'Delete webhook' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Create webhook action.
	 */
	public function handle_create_webhook() {
		$this->ensure_admin_action( 'ctfb_webhook_action' );
		$this->create_or_refresh_webhook( false );
	}

	/**
	 * Refresh webhook action.
	 */
	public function handle_refresh_webhook() {
		$this->ensure_admin_action( 'ctfb_webhook_action' );
		$this->create_or_refresh_webhook( true );
	}

	/**
	 * Delete webhook action.
	 */
	public function handle_delete_webhook() {
		$this->ensure_admin_action( 'ctfb_webhook_action' );
		$settings = get_option( 'ctfb_settings', array() );
		if ( empty( $settings['calendly_token'] ) || empty( $settings['webhook_subscription_id'] ) ) {
			$this->redirect_with_notice( 'error', 'Token or subscription ID is missing.' );
		}
		$client = new Calendly_Client( $this->logger );
		$resp   = $client->delete_webhook( $settings['calendly_token'], $settings['webhook_subscription_id'] );
		if ( ! $resp['success'] ) {
			$this->redirect_with_notice( 'error', 'Webhook delete failed: ' . $resp['message'] );
		}
		$settings['webhook_subscription_id'] = '';
		update_option( 'ctfb_settings', $settings );
		$this->redirect_with_notice( 'success', 'Webhook deleted.' );
	}

	/**
	 * Activation notice.
	 */
	public function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'ctfb_activation_notice' ) ) {
			return;
		}
		delete_transient( 'ctfb_activation_notice' );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Calendly to Formidable Bridge activated. Please configure settings, save your token, and create webhook.', 'calendly-to-formidable-bridge' ) . '</p></div>';
	}

	private function create_or_refresh_webhook( $refresh ) {
		$settings = get_option( 'ctfb_settings', array() );
		if ( empty( $settings['calendly_token'] ) ) {
			$this->redirect_with_notice( 'error', 'Calendly token is required.' );
		}
		$client  = new Calendly_Client( $this->logger );
		$user    = $client->get_current_user( $settings['calendly_token'] );
		if ( ! $user['success'] || empty( $user['data']['resource']['uri'] ) ) {
			$this->redirect_with_notice( 'error', 'Unable to fetch Calendly user: ' . $user['message'] );
		}

		$scope     = 'user';
		$scope_uri = $user['data']['resource']['uri'];

		if ( $refresh && ! empty( $settings['webhook_subscription_id'] ) ) {
			$client->delete_webhook( $settings['calendly_token'], $settings['webhook_subscription_id'] );
		}

		$result = $client->create_webhook( $settings['calendly_token'], rest_url( 'ctfb/v1/webhook' ), $scope, $scope_uri );
		if ( ! $result['success'] ) {
			$this->redirect_with_notice( 'error', 'Webhook creation failed: ' . $result['message'] );
		}
		$settings['webhook_subscription_id'] = isset( $result['data']['resource']['uri'] ) ? esc_url_raw( $result['data']['resource']['uri'] ) : '';
		$settings['webhook_scope']           = $scope;
		$settings['webhook_scope_uri']       = esc_url_raw( $scope_uri );
		update_option( 'ctfb_settings', $settings );

		$this->redirect_with_notice( 'success', 'Webhook created successfully.' );
	}

	private function ensure_admin_action( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized action.', 'calendly-to-formidable-bridge' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function render_webhook_button( $action, $label ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), 'ctfb_webhook_action' );
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
	}

	private function redirect_with_notice( $type, $message ) {
		$location = add_query_arg(
			array(
				'page'    => 'ctfb-settings',
				'ctfb_nt' => $type,
				'ctfb_msg'=> rawurlencode( $message ),
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $location );
		exit;
	}

	private function mask_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$start = substr( $value, 0, 4 );
		$end   = substr( $value, -4 );
		return $start . str_repeat( '*', 8 ) . $end;
	}
}
