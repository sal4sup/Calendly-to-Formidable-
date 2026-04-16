<?php

namespace CTFB\Frontend;

class Booking_Shortcode {

	public function register() {
		add_shortcode( 'ctfb_booking', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array( 'event_type' => '' ),
			$atts,
			'ctfb_booking'
		);

		$options = get_option( 'ctfb_options', array() );
		$pat     = isset( $options['pat'] ) ? $options['pat'] : '';

		if ( empty( $pat ) ) {
			return '<p class="ctfb-booking-error">Booking is not available at this time.</p>';
		}

		$event_type   = $this->resolve_event_type( $atts, $options );
		$display_mode = ( isset( $options['booking_display_mode'] ) && 'list' === $options['booking_display_mode'] ) ? 'list' : 'calendar';

		wp_enqueue_style(
			'ctfb-booking',
			CTFB_PLUGIN_URL . 'assets/css/ctfb-booking.css',
			array(),
			CTFB_VERSION
		);
		wp_enqueue_script(
			'ctfb-booking',
			CTFB_PLUGIN_URL . 'assets/js/ctfb-booking.js',
			array(),
			CTFB_VERSION,
			true
		);
		wp_localize_script(
			'ctfb-booking',
			'ctfbBooking',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'ctfb/v1/booking/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'eventType'   => $event_type,
				'displayMode' => $display_mode,
			)
		);

		ob_start();
		$this->render_html();
		return ob_get_clean();
	}

	private function resolve_event_type( $atts, $options ) {
		if ( ! empty( $atts['event_type'] ) ) {
			return esc_url_raw( $atts['event_type'] );
		}
		if ( ! empty( $options['booking_event_types'] ) && is_array( $options['booking_event_types'] ) ) {
			$first = reset( $options['booking_event_types'] );
			$clean = esc_url_raw( trim( (string) $first ) );
			if ( '' !== $clean ) {
				return $clean;
			}
		}
		return '';
	}

	private function render_html() {
		?>
		<div id="ctfb-booking" class="ctfb-booking">

			<div id="ctfb-loading" class="ctfb-loader">
				<div class="ctfb-spinner"></div>
				<span>Loading availability...</span>
			</div>

			<div id="ctfb-global-error" class="ctfb-global-error" style="display:none;"></div>

			<!-- Step 1: Date / Time picker — both modes share this panel -->
			<div id="ctfb-step-datetime" class="ctfb-panel" style="display:none;">

				<!-- Calendar mode: month grid -->
				<div id="ctfb-cal-section" style="display:none;">
					<div class="ctfb-calendar">
						<div class="ctfb-cal-header">
							<button type="button" id="ctfb-cal-prev" class="ctfb-cal-nav" aria-label="Previous month">&#8249;</button>
							<span id="ctfb-cal-title" class="ctfb-cal-title"></span>
							<button type="button" id="ctfb-cal-next" class="ctfb-cal-nav" aria-label="Next month">&#8250;</button>
						</div>
						<div class="ctfb-cal-weekdays">
							<span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
						</div>
						<div id="ctfb-cal-grid" class="ctfb-cal-grid"></div>
						<div id="ctfb-cal-empty" class="ctfb-cal-empty" style="display:none;">No availability this month. Try the next month.</div>
					</div>
				</div>

				<!-- Calendar mode: times panel (shown after day is selected) -->
				<div id="ctfb-times-section" style="display:none;">
					<button type="button" id="ctfb-back-to-cal" class="ctfb-back-btn">&#8592; Back to calendar</button>
					<h4 id="ctfb-times-title" class="ctfb-times-title"></h4>
					<div id="ctfb-times-list" class="ctfb-times-list ctfb-times-list--grid"></div>
				</div>

				<!-- List mode: week-based slot list -->
				<div id="ctfb-list-section" style="display:none;">

					<!-- List sub-step 1: day selection -->
					<div id="ctfb-list-days-panel">
						<div class="ctfb-week-nav">
							<button type="button" id="ctfb-prev-week" class="ctfb-cal-nav" aria-label="Previous week">&#8249;</button>
							<span id="ctfb-week-label" class="ctfb-cal-title"></span>
							<button type="button" id="ctfb-next-week" class="ctfb-cal-nav" aria-label="Next week">&#8250;</button>
						</div>
						<div id="ctfb-week-slots" class="ctfb-day-cards"></div>
					</div>

					<!-- List sub-step 2: time selection for chosen day -->
					<div id="ctfb-list-times-panel" style="display:none;">
						<button type="button" id="ctfb-list-back-to-days" class="ctfb-back-btn">&#8592; Back to days</button>
						<h4 id="ctfb-list-times-title" class="ctfb-times-title"></h4>
						<div id="ctfb-list-times-list" class="ctfb-times-list ctfb-times-list--grid"></div>
					</div>

				</div>

			</div>

			<!-- Step 2: Form -->
			<div id="ctfb-step-form" class="ctfb-panel" style="display:none;">
				<div class="ctfb-form-header">
					<button type="button" id="ctfb-back-to-datetime" class="ctfb-back-btn" aria-label="Back">&#8592; Change time</button>
					<p id="ctfb-selected-info" class="ctfb-selected-info"></p>
				</div>
				<form id="ctfb-booking-form" class="ctfb-form" novalidate>
					<div class="ctfb-form-grid">
						<div class="ctfb-field">
							<label for="ctfb-name">Name <span class="ctfb-req">*</span></label>
							<input type="text" id="ctfb-name" name="name" required autocomplete="name" placeholder="Your full name" />
						</div>
						<div class="ctfb-field">
							<label for="ctfb-email">Email <span class="ctfb-req">*</span></label>
							<input type="email" id="ctfb-email" name="email" required autocomplete="email" placeholder="you@company.com" />
						</div>
						<div class="ctfb-field">
							<label for="ctfb-company">Company Name</label>
							<input type="text" id="ctfb-company" name="company" autocomplete="organization" placeholder="Company name" />
						</div>
						<div class="ctfb-field">
							<label for="ctfb-phone">Phone</label>
							<input type="tel" id="ctfb-phone" name="phone" autocomplete="tel" placeholder="+1 555 000 0000" />
						</div>
						<div class="ctfb-field">
							<label for="ctfb-country">Country</label>
							<input type="text" id="ctfb-country" name="country" autocomplete="country-name" placeholder="Country" />
						</div>
						<div class="ctfb-field">
							<label for="ctfb-freight">Are you a freight forwarder?</label>
							<select id="ctfb-freight" name="freight_forwarder">
								<option value="No">No</option>
								<option value="Yes">Yes</option>
							</select>
						</div>
					</div>
					<div class="ctfb-hp" aria-hidden="true">
						<input type="text" name="website" tabindex="-1" autocomplete="off" />
					</div>
					<div id="ctfb-form-error" class="ctfb-form-error" style="display:none;"></div>
					<button type="submit" id="ctfb-submit" class="ctfb-submit-btn">Confirm Booking</button>
				</form>
			</div>

			<!-- Step 3: Done -->
			<div id="ctfb-step-done" class="ctfb-panel" style="display:none;">
				<div class="ctfb-done">
					<div class="ctfb-done-icon">&#10003;</div>
					<h3>Almost done!</h3>
					<p>Redirecting you to Calendly to confirm your booking.</p>
					<p>If you are not redirected, <a id="ctfb-booking-link" href="#" target="_blank" rel="noopener noreferrer">click here</a>.</p>
				</div>
			</div>

		</div>
		<?php
	}
}
