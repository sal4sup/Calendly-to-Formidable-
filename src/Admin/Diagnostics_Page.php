<?php

namespace CalendlyToFormidableBridge\Admin;

use CalendlyToFormidableBridge\Support\Logger;
use CalendlyToFormidableBridge\Sync\Formidable_Sync;

/**
 * Diagnostics section renderer and tester.
 */
class Diagnostics_Page {
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
		add_action( 'admin_footer-options-general.php', array( $this, 'render_in_settings_footer' ) );
	}

	/**
	 * Render diagnostics in settings page.
	 *
	 * @return void
	 */
	public function render_in_settings_footer() {
		if ( ! isset( $_GET['page'] ) || 'ctfb-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sync      = new Formidable_Sync( $this->logger );
		$settings  = get_option( 'ctfb_settings', array() );
		$last_info = get_option( 'ctfb_last_sync_info', array() );
		$logs      = $this->get_recent_logs();
		?>
		<h2><?php echo esc_html__( 'Diagnostics', 'calendly-to-formidable-bridge' ); ?></h2>
		<table class="widefat striped">
			<tbody>
			<tr><td>Formidable active</td><td><?php echo class_exists( 'FrmEntry' ) ? 'Yes' : 'No'; ?></td></tr>
			<tr><td>Form ID 4 ready</td><td><?php echo $sync->is_formidable_ready() ? 'Yes' : 'No'; ?></td></tr>
			<tr><td>Webhook endpoint</td><td><code><?php echo esc_html( rest_url( 'ctfb/v1/webhook' ) ); ?></code></td></tr>
			<tr><td>Webhook subscription ID</td><td><code><?php echo esc_html( isset( $settings['webhook_subscription_id'] ) ? $settings['webhook_subscription_id'] : '' ); ?></code></td></tr>
			<tr><td>Last successful sync time</td><td><?php echo esc_html( isset( $last_info['last_success_at'] ) ? $last_info['last_success_at'] : '' ); ?></td></tr>
			<tr><td>Last error</td><td><?php echo esc_html( isset( $last_info['last_error'] ) ? $last_info['last_error'] : '' ); ?></td></tr>
			<tr><td>Last processed email</td><td><?php echo esc_html( isset( $last_info['last_email'] ) ? $last_info['last_email'] : '' ); ?></td></tr>
			</tbody>
		</table>

		<h3>Manual test payload</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ctfb_test_payload' ); ?>
			<input type="hidden" name="action" value="ctfb_test_payload" />
			<p><textarea name="ctfb_payload" rows="10" class="large-text code" placeholder="Paste Calendly webhook JSON"></textarea></p>
			<p><label><input type="checkbox" name="commit" value="1" /> Commit to database</label></p>
			<p><button class="button button-primary" type="submit">Run test</button></p>
		</form>

		<h3>Recent sync attempts</h3>
		<table class="widefat striped">
			<thead><tr><th>Time (UTC)</th><th>Event</th><th>Email</th><th>Action</th><th>Reason</th></tr></thead>
			<tbody>
			<?php foreach ( $logs as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['created_at'] ); ?></td>
				<td><?php echo esc_html( $row['event_type'] ); ?></td>
				<td><?php echo esc_html( $row['invitee_email'] ); ?></td>
				<td><?php echo esc_html( $row['action_taken'] ); ?></td>
				<td><?php echo esc_html( $row['failure_reason'] ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle test payload action.
	 *
	 * @return void
	 */
	public function handle_test_payload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( 'ctfb_test_payload' );
		$raw    = isset( $_POST['ctfb_payload'] ) ? wp_unslash( $_POST['ctfb_payload'] ) : '';
		$event  = json_decode( (string) $raw, true );
		if ( ! is_array( $event ) ) {
			$this->redirect( 'error', 'Invalid JSON payload.' );
		}
		$sync   = new Formidable_Sync( $this->logger );
		$commit = ! empty( $_POST['commit'] );
		$res    = $sync->process_event( $event, $commit );
		$msg    = $res['success'] ? 'Test completed: ' . $res['action'] : 'Test failed: ' . $res['reason'];
		$this->redirect( $res['success'] ? 'success' : 'error', $msg );
	}

	/**
	 * Get recent logs.
	 *
	 * @return array
	 */
	private function get_recent_logs() {
		global $wpdb;
		$table = $wpdb->prefix . 'ctfb_logs';
		$rows  = $wpdb->get_results( "SELECT created_at, event_type, invitee_email, action_taken, failure_reason FROM {$table} ORDER BY id DESC LIMIT 20", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Redirect helper.
	 */
	private function redirect( $type, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ctfb-settings',
					'ctfb_nt' => $type,
					'ctfb_msg'=> rawurlencode( $message ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
