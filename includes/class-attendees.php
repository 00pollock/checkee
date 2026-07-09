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
	public static function get_for_mapping( int $mapping_id, int $limit = 50, int $offset = 0, string $search = '', string $status = '' ): array {
		global $wpdb;
		[ $where_sql, $params ] = self::build_search_where( $mapping_id, $search, $status );

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

	/**
	 * Creates local attendee records for AC contacts holding the registration tag that don't
	 * already have one (matched by email). Never touches or overwrites existing records —
	 * this only fills gaps, e.g. a staging site whose local DB is a stale copy of production
	 * while real registrations keep landing in AC via the live site.
	 */
	public static function backfill_from_ac( int $mapping_id, string $event_name, array $contacts ): array {
		$created = 0;
		$skipped = 0;

		foreach ( $contacts as $c ) {
			if ( empty( $c['email'] ) ) {
				continue;
			}
			if ( self::find_by_email_event( $c['email'], $mapping_id ) ) {
				$skipped++;
				continue;
			}
			$id = self::create( [
				'event_mapping_id' => $mapping_id,
				'event_name'       => $event_name,
				'first_name'       => $c['first_name'] ?? '',
				'last_name'        => $c['last_name'] ?? '',
				'email'            => $c['email'],
			] );
			if ( $id ) {
				$created++;
			}
		}

		return [ 'created' => $created, 'skipped' => $skipped ];
	}

	/**
	 * Reconcile local check-in status against ActiveCampaign: the tag is the source of truth.
	 * Tag present in AC  -> local status becomes 'checked_in' (even if it was 'checked_out').
	 * Tag absent from AC -> local status becomes 'registered' if it was 'checked_in'.
	 * Does not touch AC — this is a one-way pull, never pushes tags back.
	 */
	public static function sync_checkin_status( int $mapping_id, array $checked_in_emails ): array {
		$checked_in_emails = array_flip( array_map( 'strtolower', $checked_in_emails ) );
		$attendees          = self::get_all_for_mapping( $mapping_id );

		$promoted  = 0;
		$demoted   = 0;
		$unchanged = 0;

		foreach ( $attendees as $a ) {
			$should_be_in = isset( $checked_in_emails[ strtolower( $a['email'] ) ] );
			$is_in        = 'checked_in' === $a['status'];

			if ( $should_be_in && ! $is_in ) {
				self::update_status( (int) $a['id'], 'checked_in' );
				$promoted++;
			} elseif ( ! $should_be_in && $is_in ) {
				self::update_status( (int) $a['id'], 'registered' );
				$demoted++;
			} else {
				$unchanged++;
			}
		}

		return [ 'promoted' => $promoted, 'demoted' => $demoted, 'unchanged' => $unchanged ];
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
	public static function count_for_mapping( int $mapping_id, string $search = '', string $status = '' ): int {
		global $wpdb;
		[ $where_sql, $params ] = self::build_search_where( $mapping_id, $search, $status );
		$sql = "SELECT COUNT(*) FROM " . DB::table( 'attendees' ) . " WHERE {$where_sql}";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/** Helper: full name from first + last. */
	public static function full_name( array $attendee ): string {
		return trim( ( $attendee['first_name'] ?? '' ) . ' ' . ( $attendee['last_name'] ?? '' ) )
			?: ( $attendee['email'] ?? 'Unknown' );
	}

	/** Shared WHERE clause + params for mapping-scoped, optionally search/status-filtered attendee queries. */
	private static function build_search_where( int $mapping_id, string $search, string $status = '' ): array {
		global $wpdb;
		$where  = 'event_mapping_id = %d';
		$params = [ $mapping_id ];

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
			$params  = array_merge( $params, [ $like, $like, $like ] );
		}

		if ( in_array( $status, [ 'registered', 'checked_in', 'checked_out' ], true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		return [ $where, $params ];
	}
}
