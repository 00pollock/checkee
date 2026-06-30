<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the Checkee SaaS API.
 *
 * Standalone mode:  API token not set → all methods return null/false gracefully.
 * Connected mode:   API token set → routes data through checkee.up.railway.app (or configured URL).
 */
class API {

	private const OPTION_TOKEN   = 'checkee_api_token';
	private const OPTION_URL     = 'checkee_api_url';
	private const DEFAULT_URL    = 'https://checkee.up.railway.app';
	private const TIMEOUT        = 10;

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	public static function is_connected(): bool {
		return ! empty( get_option( self::OPTION_TOKEN, '' ) );
	}

	public static function get_base_url(): string {
		$url = trim( get_option( self::OPTION_URL, '' ) );
		return $url !== '' ? rtrim( $url, '/' ) : self::DEFAULT_URL;
	}

	public static function get_token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	public static function save( string $token, string $url ): void {
		update_option( self::OPTION_TOKEN, sanitize_text_field( $token ) );
		update_option( self::OPTION_URL, esc_url_raw( $url ) );
	}

	// -------------------------------------------------------------------------
	// Check-in URL
	// -------------------------------------------------------------------------

	public static function checkin_url( string $token ): string {
		return self::get_base_url() . '/checkin/' . rawurlencode( $token );
	}

	// -------------------------------------------------------------------------
	// API calls
	// -------------------------------------------------------------------------

	/**
	 * Fetch the org's event list from Checkee.
	 * Returns array of event objects or empty array on failure.
	 */
	public static function get_events(): array {
		if ( ! self::is_connected() ) {
			return [];
		}

		$response = wp_remote_get(
			self::get_base_url() . '/api/v1/events',
			[
				'timeout' => self::TIMEOUT,
				'headers' => self::auth_headers(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : [];
	}

	/**
	 * Register an attendee for a Checkee event.
	 *
	 * @param int   $event_id  Checkee event ID
	 * @param array $fields    Flat array: [ 'email' => ..., 'first_name' => ..., 'last_name' => ... ]
	 * @return array|null  Decoded response body, or null on failure.
	 */
	public static function register_attendee( int $event_id, array $fields ): ?array {
		if ( ! self::is_connected() ) {
			return null;
		}

		$response = wp_remote_post(
			self::get_base_url() . '/api/v1/attendees',
			[
				'timeout' => self::TIMEOUT,
				'headers' => array_merge( self::auth_headers(), [ 'Content-Type' => 'application/json' ] ),
				'body'    => wp_json_encode( [
					'event_id' => $event_id,
					'fields'   => $fields,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Checkee API error: ' . $response->get_error_message() );
			}
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Checkee API register_attendee → HTTP ' . $code . ' ' . wp_json_encode( $body ) );
		}

		return $body;
	}

	/**
	 * Test the connection — returns [ 'connected' => bool, 'message' => string ].
	 */
	public static function test_connection(): array {
		if ( ! self::is_connected() ) {
			return [ 'connected' => false, 'message' => 'No API token configured.' ];
		}

		$events = self::get_events();

		if ( $events === [] ) {
			// Could be empty org or a real failure — try a raw request to check HTTP status
			$response = wp_remote_get(
				self::get_base_url() . '/api/v1/events',
				[
					'timeout' => self::TIMEOUT,
					'headers' => self::auth_headers(),
				]
			);

			if ( is_wp_error( $response ) ) {
				return [ 'connected' => false, 'message' => 'Could not reach Checkee: ' . $response->get_error_message() ];
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code === 401 || $code === 403 ) {
				return [ 'connected' => false, 'message' => 'Invalid API token (HTTP ' . $code . ').' ];
			}
			if ( $code !== 200 ) {
				return [ 'connected' => false, 'message' => 'Unexpected response from Checkee (HTTP ' . $code . ').' ];
			}

			return [ 'connected' => true, 'message' => 'Connected to Checkee. No events found yet.' ];
		}

		return [
			'connected' => true,
			'message'   => 'Connected to Checkee. ' . count( $events ) . ' event(s) found.',
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function auth_headers(): array {
		return [
			'Authorization' => 'Bearer ' . self::get_token(),
			'Accept'        => 'application/json',
		];
	}
}
