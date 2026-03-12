<?php

namespace CTFB\Sync;

use CTFB\Support\Logger;

class Field_Mapper {
	public function map_fields( $payload, $settings ) {
		Logger::debug( 'mapping_started' );

		$resource = isset( $payload['payload'] ) ? $payload['payload'] : array();
		$email    = isset( $resource['email'] ) ? sanitize_email( $resource['email'] ) : '';
		$name     = isset( $resource['name'] ) ? sanitize_text_field( $resource['name'] ) : '';
		$qa       = isset( $resource['questions_and_answers'] ) && is_array( $resource['questions_and_answers'] ) ? $resource['questions_and_answers'] : array();
		$labels   = array();
		foreach ( $qa as $item ) {
			if ( isset( $item['question'] ) ) {
				$labels[] = sanitize_text_field( $item['question'] );
			}
		}
		Logger::debug( 'detected_custom_question_labels', array( 'labels' => implode( '|', $labels ) ) );

		$company = $this->extract_answer( $qa, array( 'company', 'company name', 'business', 'business name', 'organization', 'organization name', 'employer', 'employer name' ) );
		if ( empty( $company ) ) {
			$company = ! empty( $settings['fallback_company_name'] ) ? sanitize_text_field( $settings['fallback_company_name'] ) : 'Not Provided';
			Logger::debug( 'fallback_values_applied', array( 'company' => $company ) );
		}

		$phone = isset( $resource['text_reminder_number'] ) ? sanitize_text_field( $resource['text_reminder_number'] ) : '';
		if ( empty( $phone ) ) {
			$phone = $this->extract_answer( $qa, array( 'phone', 'mobile', 'mobile number', 'phone number', 'whatsapp', 'whatsapp number' ) );
		}

		$country = $this->extract_answer( $qa, array( 'country', 'country name', 'location country' ) );
		if ( empty( $country ) && ! empty( $settings['default_country'] ) ) {
			$country = sanitize_text_field( $settings['default_country'] );
			Logger::debug( 'fallback_values_applied', array( 'country' => $country ) );
		}

		$forwarder = $this->extract_answer( $qa, array( 'are you a freight forwarder', 'freight forwarder', 'forwarder', 'logistics company' ) );
		$forwarder = $this->normalize_yes_no( $forwarder );
		if ( empty( $forwarder ) ) {
			$forwarder = ! empty( $settings['fallback_freight_forwarder'] ) ? $this->normalize_yes_no( $settings['fallback_freight_forwarder'] ) : 'No';
			Logger::debug( 'fallback_values_applied', array( 'freight_forwarder' => $forwarder ) );
		}

		$terms_value = $this->get_terms_value();
		Logger::debug( 'terms_checkbox_value_chosen', array( 'value' => $terms_value ) );

		$item_meta = array(
			'22' => $name,
			'23' => $email,
			'24' => $company,
			'31' => $phone,
			'32' => $country,
			'73' => $forwarder ? $forwarder : 'No',
			'26' => array( $terms_value ),
		);

		Logger::debug(
			'mapped_fields_ready',
			array(
				'extracted_name'               => $name,
				'extracted_email'              => $email,
				'extracted_company_name'       => $company,
				'extracted_phone'              => $phone,
				'extracted_country'            => $country,
				'extracted_freight_forwarder'  => $item_meta['73'],
			)
		);
		Logger::debug( 'final_item_meta_payload_prepared', array( 'item_meta' => $item_meta ) );

		return array(
			'email'  => $email,
			'fields' => $item_meta,
		);
	}

	private function extract_answer( $qa, $needles ) {
		foreach ( $qa as $item ) {
			$question = isset( $item['question'] ) ? $this->norm( $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? trim( wp_strip_all_tags( (string) $item['answer'] ) ) : '';
			if ( empty( $question ) || '' === $answer ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $question, $this->norm( $needle ) ) ) {
					return sanitize_text_field( $answer );
				}
			}
		}
		return '';
	}

	private function norm( $text ) {
		$text = strtolower( trim( wp_strip_all_tags( (string) $text ) ) );
		return preg_replace( '/\s+/', ' ', $text );
	}

	private function normalize_yes_no( $value ) {
		$val = $this->norm( $value );
		if ( in_array( $val, array( 'yes', 'y', 'true', '1' ), true ) ) {
			return 'Yes';
		}
		if ( in_array( $val, array( 'no', 'n', 'false', '0' ), true ) ) {
			return 'No';
		}
		if ( false !== strpos( $val, 'yes' ) ) {
			return 'Yes';
		}
		if ( false !== strpos( $val, 'no' ) ) {
			return 'No';
		}
		return '';
	}

	private function get_terms_value() {
		if ( class_exists( 'FrmField' ) ) {
			$field = \FrmField::getOne( 26 );
			if ( $field && isset( $field->options['options'] ) && is_array( $field->options['options'] ) && ! empty( $field->options['options'][0] ) ) {
				return $field->options['options'][0];
			}
		}
		return 'I agree to Logitude’s <a href="https://logitudeworld.com/terms-and-conditions/">Тerms of use </a> and <a href="https://logitudeworld.com/privacy-policy/">Privacy Policy</a>';
	}
}
