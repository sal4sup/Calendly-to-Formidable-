<?php

namespace CalendlyToFormidableBridge\Sync;

/**
 * Map Calendly payload into Formidable fields.
 */
class Field_Mapper {
	/**
	 * Build mapped values.
	 *
	 * @param array $payload Payload.
	 * @param array $settings Settings.
	 * @return array
	 */
	public function map( $payload, $settings ) {
		$invitee   = isset( $payload['payload'] ) ? $payload['payload'] : array();
		$questions = isset( $invitee['questions_and_answers'] ) && is_array( $invitee['questions_and_answers'] ) ? $invitee['questions_and_answers'] : array();

		$name  = $this->normalize_text( isset( $invitee['name'] ) ? $invitee['name'] : '' );
		$email = sanitize_email( isset( $invitee['email'] ) ? $invitee['email'] : '' );

		$company = $this->extract_by_labels( $questions, array( 'company', 'company name', 'business', 'business name', 'organization', 'organization name', 'employer', 'employer name' ) );
		if ( '' === $company ) {
			$company = isset( $settings['fallback_company_name'] ) ? $this->normalize_text( $settings['fallback_company_name'] ) : '';
		}
		if ( '' === $company ) {
			$company = 'Not Provided';
		}

		$phone = $this->normalize_phone( isset( $invitee['text_reminder_number'] ) ? $invitee['text_reminder_number'] : '' );
		if ( '' === $phone ) {
			$phone = $this->extract_by_labels( $questions, array( 'phone', 'mobile', 'mobile number', 'phone number', 'whatsapp', 'whatsapp number' ) );
			$phone = $this->normalize_phone( $phone );
		}

		$country = $this->extract_by_labels( $questions, array( 'country', 'country name', 'location country' ) );
		if ( '' === $country ) {
			$country = isset( $settings['default_country'] ) ? $this->normalize_text( $settings['default_country'] ) : '';
		}

		$forwarder = $this->extract_by_labels( $questions, array( 'are you a freight forwarder', 'freight forwarder', 'forwarder', 'logistics company' ) );
		if ( '' === $forwarder ) {
			$forwarder = isset( $settings['fallback_freight_forwarder'] ) ? $settings['fallback_freight_forwarder'] : 'No';
		}
		$forwarder = $this->normalize_yes_no( $forwarder );

		return array(
			'22' => $name,
			'23' => $email,
			'24' => $company,
			'31' => $phone,
			'32' => $country,
			'73' => $forwarder,
		);
	}

	/**
	 * Extract by label matching.
	 *
	 * @param array $questions Questions.
	 * @param array $labels Labels.
	 * @return string
	 */
	private function extract_by_labels( $questions, $labels ) {
		foreach ( $questions as $item ) {
			$label = $this->normalize_key( isset( $item['question'] ) ? $item['question'] : '' );
			if ( '' === $label ) {
				continue;
			}
			foreach ( $labels as $candidate ) {
				$needle = $this->normalize_key( $candidate );
				if ( $label === $needle || false !== strpos( $label, $needle ) || false !== strpos( $needle, $label ) ) {
					$value = '';
					if ( isset( $item['answer'] ) ) {
						$value = $item['answer'];
					} elseif ( isset( $item['value'] ) ) {
						$value = $item['value'];
					}
					return $this->normalize_text( $value );
				}
			}
		}
		return '';
	}

	/**
	 * Normalize text.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	private function normalize_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Normalize key.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	private function normalize_key( $text ) {
		return strtolower( $this->normalize_text( $text ) );
	}

	/**
	 * Normalize yes or no.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function normalize_yes_no( $value ) {
		$value = strtolower( $this->normalize_text( $value ) );
		if ( in_array( $value, array( 'yes', 'y', 'true', '1' ), true ) ) {
			return 'Yes';
		}
		if ( in_array( $value, array( 'no', 'n', 'false', '0' ), true ) ) {
			return 'No';
		}
		if ( false !== strpos( $value, 'yes' ) ) {
			return 'Yes';
		}
		return 'No';
	}

	/**
	 * Normalize phone.
	 *
	 * @param string $value Phone.
	 * @return string
	 */
	private function normalize_phone( $value ) {
		$value = $this->normalize_text( $value );
		$value = preg_replace( '/[^0-9\+\-\(\)\s]/', '', $value );
		return trim( (string) $value );
	}
}
