<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Attendees {

	private const TTL_TOKEN = 900;
	private const TTL_LIST  = 300;

	public static function find_by_token( string $token ): ?array {
		$cache_key = 'checkee_att_tok_' . $token;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . DB::table( 'attendees' ) . " WHERE qr_token = %s LIMIT 1", $token ),
			ARRAY_A
		);

		set_transient( $cache_key, $row ?? [], self::TTL_TOKEN );
		return $row ?: null;
	}

	public static function find_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . DB::table( 'attendees' ) . " WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function find_by_email_event( string $email, int $mapping_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . DB::table( 'attendees' ) . " WHERE email = %s AND event_mapping_id = %d LIMIT 1",
				$email,
				$mapping_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function create( array $data ): int|false {
		global $wpdb;
		$now   = current_time( 'mysql' );
		$token = QR::generate_token();

		$inserted = $wpdb->insert(
			DB::table( 'attendees' ),
			[
				'event_mapping_id' => (int) ( $data['event_mapping_id'] ?? 0 ) ?: null,
				'event_name'       => sanitize_text_field( $data['event_name'] ?? '' ),
				'first_name'       => sanitize_text_field( $data['first_name'] ?? '' ),
				'last_name'        => sanitize_text_field( $data['last_name'] ?? '' ),
				'email'            => sanitize_email( $data['email'] ?? '' ),
				'status'           => 'registered',
				'qr_token'         => $token,
				'metadata'         => $data['metadata'] ?? null,
				'created_at'       => $now,
				'updated_at'       => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;
		self::clear_list_cache( (int) ( $data['event_mapping_id'] ?? 0 ) );
		return $id;
	}

	public static function get_for_mapping( int $mapping_id, int $limit = 200, int $offset = 0 ): array {
		$cache_key = 'checkee_atts_m' . $mapping_id . "_{$limit}_{$offset}";
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . DB::table( 'attendees' ) . " WHERE event_mapping_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$mapping_id, $limit, $offset
			),
			ARRAY_A
		);

		$result = $rows ?: [];
		set_transient( $cache_key, $result, self::TTL_LIST );
		return $result;
	}

	public static function update_status( int $id, string $status ): bool {
		global $wpdb;
		$attendee = self::find_by_id( $id );
		if ( ! $attendee ) {
			return false;
		}

		$updated = $wpdb->update(
			DB::table( 'attendees' ),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false !== $updated ) {
			delete_transient( 'checkee_att_tok_' . $attendee['qr_token'] );
			self::clear_list_cache( (int) ( $attendee['event_mapping_id'] ?? 0 ) );
		}

		return false !== $updated;
	}

	public static function delete_by_id( int $id ): bool {
		global $wpdb;
		$attendee = self::find_by_id( $id );
		if ( ! $attendee ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( DB::table( 'attendees' ), [ 'id' => $id ], [ '%d' ] );
		if ( false !== $result ) {
			delete_transient( 'checkee_att_tok_' . $attendee['qr_token'] );
			self::clear_list_cache( (int) ( $attendee['event_mapping_id'] ?? 0 ) );
		}
		return false !== $result;
	}

	public static function search( string $query, int $mapping_id ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $query ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . DB::table( 'attendees' ) . "
				 WHERE event_mapping_id = %d
				 AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)
				 ORDER BY first_name ASC LIMIT 50",
				$mapping_id, $like, $like, $like
			),
			ARRAY_A
		) ?: [];
	}

	public static function count_for_mapping( int $mapping_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM " . DB::table( 'attendees' ) . " WHERE event_mapping_id = %d", $mapping_id )
		);
	}

	/** Helper: full name from first + last. */
	public static function full_name( array $attendee ): string {
		return trim( ( $attendee['first_name'] ?? '' ) . ' ' . ( $attendee['last_name'] ?? '' ) )
			?: ( $attendee['email'] ?? 'Unknown' );
	}

	private static function clear_list_cache( int $mapping_id ): void {
		delete_transient( 'checkee_atts_m' . $mapping_id . '_200_0' );
	}
}
