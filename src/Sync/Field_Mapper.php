<?php

namespace CTFB\Sync;

use CTFB\Support\Logger;

class Field_Mapper {
	public function map_fields( $payload, $settings ) {
		Logger::debug( 'mapping_started' );
		Logger::debug( 'questions_and_answers_extraction_started' );

		$resource = ( isset( $payload['payload'] ) && is_array( $payload['payload'] ) ) ? $payload['payload'] : array();
		$email    = isset( $resource['email'] ) ? sanitize_email( $resource['email'] ) : '';
		$name     = isset( $resource['name'] ) ? sanitize_text_field( $resource['name'] ) : '';
		$qa       = array();
		if ( isset( $resource['questions_and_answers'] ) && is_array( $resource['questions_and_answers'] ) ) {
			$qa = $resource['questions_and_answers'];
		} elseif ( isset( $resource['questions_and_answers'] ) ) {
			Logger::warning( 'questions_and_answers_unexpected_shape', array( 'value_type' => gettype( $resource['questions_and_answers'] ) ) );
		}
		Logger::debug( 'questions_and_answers_extraction_completed', array( 'count' => count( $qa ) ) );
		Logger::debug( 'payload_value_extraction_started' );

		$labels = array();
		foreach ( $qa as $item ) {
			if ( isset( $item['question'] ) ) {
				$labels[] = sanitize_text_field( $item['question'] );
			}
		}
		Logger::debug( 'questions_and_answers_count', array( 'value' => count( $qa ) ) );
		Logger::debug( 'detected_question_labels', array( 'labels' => $labels ) );
		Logger::debug( 'detected_custom_question_labels', array( 'labels' => implode( '|', $labels ) ) );

		$freight_question_needles = array(
			'are you a freight forwarder',
			'freight forwarder',
			'forwarder',
			'logistics company',
		);
		$company_question_needles = array(
			"what's your company name",
			'whats your company name',
			'what is your company name',
			'your company name',
			'company name',
			'business name',
			'organization name',
			'employer name',
			'company',
			'business',
			'organization',
			'employer',
		);

		$company               = $this->extract_answer( $qa, $company_question_needles );
		$company_fallback_used = false;
		Logger::debug( 'extracted_company_name_answer', array( 'value' => $company ) );
		if ( empty( $company ) ) {
			$company               = ! empty( $settings['fallback_company_name'] ) ? sanitize_text_field( $settings['fallback_company_name'] ) : 'Not Provided';
			$company_fallback_used = true;
			Logger::debug( 'fallback_values_applied', array( 'company' => $company ) );
		}
		Logger::debug( 'fallback_used_for_company_name', array( 'value' => $company_fallback_used ? 'yes' : 'no' ) );
		Logger::debug( 'company_name_field_24_resolved', array( 'extracted_value' => $company, 'fallback_used' => $company_fallback_used ? 'yes' : 'no', 'final_value' => $company ) );

		$phone = isset( $resource['text_reminder_number'] ) ? sanitize_text_field( $resource['text_reminder_number'] ) : '';
		if ( empty( $phone ) ) {
			$phone = $this->extract_answer( $qa, array( 'phone', 'mobile', 'mobile number', 'phone number', 'whatsapp', 'whatsapp number' ) );
		}

		$country = $this->extract_answer( $qa, array( 'country', 'country name', 'location country' ) );
		if ( empty( $country ) && ! empty( $settings['default_country'] ) ) {
			$country = sanitize_text_field( $settings['default_country'] );
			Logger::debug( 'fallback_values_applied', array( 'country' => $country ) );
		}

		$forwarder_source_value  = $this->extract_answer( $qa, $freight_question_needles );
		Logger::debug( 'extracted_freight_forwarder_answer', array( 'value' => $forwarder_source_value ) );
		$forwarder               = $this->normalize_yes_no( $forwarder_source_value );
		$forwarder_fallback_used = false;
		if ( empty( $forwarder ) ) {
			$forwarder               = ! empty( $settings['fallback_freight_forwarder'] ) ? $this->normalize_yes_no( $settings['fallback_freight_forwarder'] ) : 'No';
			$forwarder_fallback_used = true;
			Logger::debug( 'fallback_values_applied', array( 'freight_forwarder' => $forwarder ) );
		}
		Logger::debug( 'fallback_used_for_freight_forwarder', array( 'value' => $forwarder_fallback_used ? 'yes' : 'no' ) );
		Logger::debug( 'freight_forwarder_field_73_resolved', array( 'source_value' => $forwarder_source_value, 'normalized_value' => $forwarder, 'fallback_used' => $forwarder_fallback_used ? 'yes' : 'no' ) );

		$terms_info = $this->get_terms_value();
		Logger::debug( 'field_26_allowed_option_resolved', array( 'source' => $terms_info['source'], 'value' => $terms_info['value'] ) );

		$item_meta = array(
			'22' => $name,
			'23' => $email,
			'24' => $company,
			'31' => $phone,
			'32' => $country,
			'73' => $forwarder ? $forwarder : 'No',
			'26' => array( $terms_info['value'] ),
		);
		Logger::debug( 'field_26_checkbox_shape', array( 'source' => $terms_info['source'], 'item_meta_26' => $item_meta['26'] ) );
		Logger::debug( 'payload_value_extraction_completed', array( 'email_detected' => empty( $email ) ? 'no' : 'yes' ) );

		Logger::debug(
			'mapped_fields_ready',
			array(
				'extracted_name'              => $name,
				'extracted_email'             => $email,
				'extracted_company_name'      => $company,
				'extracted_phone'             => $phone,
				'extracted_country'           => $country,
				'extracted_freight_forwarder' => $item_meta['73'],
			)
		);
		Logger::debug( 'item_meta_build_completed', array( 'item_meta' => $item_meta ) );
		Logger::debug( 'final_item_meta_ready', array( 'item_meta' => $item_meta ) );

		return array(
			'email'       => $email,
			'fields'      => $item_meta,
			'diagnostics' => array(
				'fallback_values' => array(
					'company'           => $company_fallback_used ? 'yes' : 'no',
					'freight_forwarder' => $forwarder_fallback_used ? 'yes' : 'no',
				),
				'required_values' => array(
					'email'   => $email,
					'company' => $company,
				),
				'field_26_source' => $terms_info['source'],
			),
		);
	}

	private function extract_answer( $qa, $needles ) {
		$normalized_needles = array();
		foreach ( (array) $needles as $needle ) {
			$normalized_needles[] = $this->normalize_question_label( $needle );
		}

		foreach ( $qa as $item ) {
			$question = isset( $item['question'] ) ? $this->normalize_question_label( $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? trim( wp_strip_all_tags( (string) $item['answer'] ) ) : '';
			if ( empty( $question ) || '' === $answer ) {
				continue;
			}
			foreach ( $normalized_needles as $needle ) {
				if ( '' !== $needle && false !== strpos( $question, $needle ) ) {
					return sanitize_text_field( $answer );
				}
			}
		}
		return '';
	}

	private function normalize_question_label( $text ) {
		$text = $this->norm( $text );
		$text = str_replace( array( "'", '"' ), '', $text );
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', trim( (string) $text ) );
		return $text;
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
				return array(
					'value'  => $field->options['options'][0],
					'source' => 'dynamic_formidable_config',
				);
			}
		}
		return array(
			'value'  => 'I agree to Logitude’s <a href="https://logitudeworld.com/terms-and-conditions/">Тerms of use </a> and <a href="https://logitudeworld.com/privacy-policy/">Privacy Policy</a>',
			'source' => 'fallback_default',
		);
	}
}
