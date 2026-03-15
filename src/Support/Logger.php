<?php

namespace CTFB\Support;

class Logger {
	private static $file_path = null;

	private static $db_table_available = null;

	public static function debug( $event, $context = array() ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}
		self::write( 'DEBUG', $event, $context, true );
	}

	public static function info( $event, $context = array(), $important = false ) {
		if ( ! self::should_log( 'INFO', $important ) ) {
			return;
		}
		self::write( 'INFO', $event, $context, true );
	}

	public static function warning( $event, $context = array() ) {
		if ( ! self::should_log( 'WARNING' ) ) {
			return;
		}
		self::write( 'WARNING', $event, $context, true );
	}

	public static function error( $event, $context = array() ) {
		self::write( 'ERROR', $event, $context, true );
	}

	public static function log( $event_type, $invitee_email, $action_taken, $failure_reason = '' ) {
		$context = array(
			'invitee_email'  => $invitee_email,
			'action_taken'   => $action_taken,
			'failure_reason' => $failure_reason,
		);
		if ( '' !== $failure_reason ) {
			self::error( $event_type, $context );
			return;
		}
		self::info( $event_type, $context );
	}

	public static function log_raw_payload( $raw_payload ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		$payload = trim( (string) $raw_payload );
		if ( strlen( $payload ) > 20000 ) {
			$payload = substr( $payload, 0, 20000 ) . "\n... [TRUNCATED]";
		}

		self::append_to_file( "----- RAW WEBHOOK PAYLOAD START -----\n" . $payload . "\n----- RAW WEBHOOK PAYLOAD END -----\n" );
	}

	public static function get_log_file_path() {
		if ( null === self::$file_path ) {
			self::$file_path = trailingslashit( WP_CONTENT_DIR ) . 'ctfb-debug.txt';
		}
		return self::$file_path;
	}

	public static function clear_log_file() {
		$path = self::get_log_file_path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$result = @file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== $result;
	}

	public static function read_tail_lines( $line_count = 50 ) {
		$path = self::get_log_file_path();
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array();
		}
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', trim( $contents ) );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		return array_slice( $lines, -1 * absint( $line_count ) );
	}

	private static function is_debug_enabled() {
		$options = get_option( 'ctfb_options', array() );
		return ! empty( $options['debug_logging'] );
	}

	private static function should_log( $level, $important = false ) {
		$level = strtoupper( (string) $level );

		if ( 'ERROR' === $level ) {
			return true;
		}

		if ( 'WARNING' === $level ) {
			return true;
		}

		if ( 'INFO' === $level ) {
			return $important || self::is_debug_enabled();
		}

		if ( 'DEBUG' === $level ) {
			return self::is_debug_enabled();
		}

		return self::is_debug_enabled();
	}

	private static function write( $level, $event, $context, $store_in_db ) {
		$line = self::format_line( $level, $event, $context );
		$ok   = self::append_to_file( $line . "\n" );

		if ( $store_in_db && self::has_log_table() ) {
			self::write_db_summary( $event, $context, $level );
		}

		if ( ! $ok && $store_in_db && self::has_log_table() ) {
			self::write_db_summary( 'file_log_write_failed', array( 'event' => $event, 'level' => $level ), 'ERROR' );
		}
	}

	private static function format_line( $level, $event, $context ) {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$parts     = array();
		foreach ( (array) $context as $key => $value ) {
			$parts[] = sanitize_key( (string) $key ) . '=' . self::sanitize_context_value( $key, $value );
		}
		$compact = implode( ' ', array_filter( $parts ) );
		return sprintf( '[%s UTC] [%s] %s%s', $timestamp, strtoupper( $level ), sanitize_key( $event ), $compact ? ' ' . $compact : '' );
	}

	private static function sanitize_context_value( $key, $value ) {
		$key_name = strtolower( (string) $key );
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( false !== strpos( $key_name, 'token' ) || false !== strpos( $key_name, 'pat' ) || false !== strpos( $key_name, 'authorization' ) || false !== strpos( $key_name, 'secret' ) ) {
			return self::mask_value( $value );
		}
		if ( strlen( $value ) > 500 ) {
			$value = substr( $value, 0, 500 ) . '...';
		}
		return str_replace( ' ', '_', sanitize_text_field( $value ) );
	}

	private static function mask_value( $value ) {
		if ( strlen( $value ) <= 8 ) {
			return '********';
		}
		return substr( $value, 0, 4 ) . str_repeat( '*', max( strlen( $value ) - 8, 8 ) ) . substr( $value, -4 );
	}

	private static function append_to_file( $content ) {
		$path = self::get_log_file_path();
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		if ( ! file_exists( $path ) ) {
			$created = @touch( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
			if ( ! $created ) {
				return false;
			}
		}

		if ( ! is_writable( $path ) ) {
			return false;
		}

		$handle = @fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		$written = false;
		if ( function_exists( 'flock' ) ) {
			if ( flock( $handle, LOCK_EX ) ) {
				$written = false !== fwrite( $handle, $content );
				flock( $handle, LOCK_UN );
			}
		} else {
			$written = false !== fwrite( $handle, $content );
		}

		fclose( $handle );
		return $written;
	}

	private static function has_log_table() {
		if ( null !== self::$db_table_available ) {
			return self::$db_table_available;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'ctfb_logs';
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		self::$db_table_available = ( $exists === $table_name );
		return self::$db_table_available;
	}

	private static function write_db_summary( $event, $context, $level ) {
		global $wpdb;

		$invitee_email = isset( $context['invitee_email'] ) ? sanitize_email( $context['invitee_email'] ) : '';
		$action_taken  = strtoupper( sanitize_text_field( $level ) ) . ':' . sanitize_text_field( $event );
		$reason_parts  = array();
		foreach ( (array) $context as $key => $value ) {
			if ( 'invitee_email' === $key ) {
				continue;
			}
			$reason_parts[] = sanitize_key( $key ) . '=' . self::sanitize_context_value( $key, $value );
		}

		$wpdb->insert(
			$wpdb->prefix . 'ctfb_logs',
			array(
				'event_type'     => sanitize_text_field( $event ),
				'invitee_email'  => $invitee_email,
				'action_taken'   => $action_taken,
				'failure_reason' => implode( ' ', $reason_parts ),
				'created_at'     => current_time( 'mysql', 1 ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
