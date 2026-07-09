<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Attendees {

	private const TTL_TOKEN = 900;

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

		return (int) $wpdb->insert_id;
	}

	/** Paginated, optionally search-filtered attendee list for a mapping. Always ordered newest first. */
	public static function get_for_mapping( int $mapping_id, int $limit = 50, int $offset = 0, string $search = '' ): array {
		global $wpdb;
		[ $where_sql, $params ] = self::build_search_where( $mapping_id, $search );

		$sql    = "SELECT * FROM " . DB::table( 'attendees' ) . " WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params = array_merge( $params, [ $limit, $offset ] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	/** Full, unpaginated attendee list for a mapping — used for CSV export so nothing is ever silently truncated. */
	public static function get_all_for_mapping( int $mapping_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . DB::table( 'attendees' ) . " WHERE event_mapping_id = %d ORDER BY created_at DESC",
				$mapping_id
			),
			ARRAY_A
		) ?: [];
	}

	/** True counts by status for a mapping, independent of any pagination. */
	public static function status_counts( int $mapping_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as c FROM " . DB::table( 'attendees' ) . " WHERE event_mapping_id = %d GROUP BY status",
				$mapping_id
			),
			ARRAY_A
		) ?: [];

		$counts = [ 'total' => 0, 'registered' => 0, 'checked_in' => 0, 'checked_out' => 0 ];
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['c'];
			$counts['total']         += (int) $row['c'];
		}
		return $counts;
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
		}
		return false !== $result;
	}

	/** Total attendees for a mapping, optionally filtered by the same search used by get_for_mapping(). */
	public static function count_for_mapping( int $mapping_id, string $search = '' ): int {
		global $wpdb;
		[ $where_sql, $params ] = self::build_search_where( $mapping_id, $search );
		$sql = "SELECT COUNT(*) FROM " . DB::table( 'attendees' ) . " WHERE {$where_sql}";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/** Helper: full name from first + last. */
	public static function full_name( array $attendee ): string {
		return trim( ( $attendee['first_name'] ?? '' ) . ' ' . ( $attendee['last_name'] ?? '' ) )
			?: ( $attendee['email'] ?? 'Unknown' );
	}

	/** Shared WHERE clause + params for mapping-scoped, optionally search-filtered attendee queries. */
	private static function build_search_where( int $mapping_id, string $search ): array {
		global $wpdb;
		$where  = 'event_mapping_id = %d';
		$params = [ $mapping_id ];

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
			$params  = array_merge( $params, [ $like, $like, $like ] );
		}

		return [ $where, $params ];
	}
}
