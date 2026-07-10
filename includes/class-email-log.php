<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class EmailLog {

	public static function record( int $attendee_id, ?int $mapping_id, string $type, string $email, bool $success, string $error = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			DB::table( 'email_log' ),
			[
				'attendee_id'      => $attendee_id,
				'event_mapping_id' => $mapping_id ?: null,
				'type'             => $type,
				'email'            => sanitize_email( $email ),
				'success'          => $success ? 1 : 0,
				'error_message'    => sanitize_text_field( $error ),
				'created_at'       => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	public static function get_for_mapping( int $mapping_id, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$log_table   = DB::table( 'email_log' );
		$att_table   = DB::table( 'attendees' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, a.first_name, a.last_name
				 FROM {$log_table} l
				 LEFT JOIN {$att_table} a ON a.id = l.attendee_id
				 WHERE l.event_mapping_id = %d
				 ORDER BY l.created_at DESC, l.id DESC
				 LIMIT %d OFFSET %d",
				$mapping_id,
				$limit,
				$offset
			),
			ARRAY_A
		) ?: [];
	}

	public static function count_for_mapping( int $mapping_id, ?bool $success = null ): int {
		global $wpdb;
		$table = DB::table( 'email_log' );
		if ( null === $success ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_mapping_id = %d", $mapping_id ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_mapping_id = %d AND success = %d", $mapping_id, $success ? 1 : 0 )
		);
	}
}
