<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Mappings {

	public static function get_all(): array {
		global $wpdb;
		$table = DB::table( 'event_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT m.*, (SELECT COUNT(*) FROM " . DB::table( 'attendees' ) . " a WHERE a.event_mapping_id = m.id) as attendee_count
			 FROM {$table} m ORDER BY m.created_at DESC",
			ARRAY_A
		);
		return $rows ?: [];
	}

	public static function find_by_id( int $id ): ?array {
		global $wpdb;
		$table = DB::table( 'event_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function find_by_form_id( string $form_id ): ?array {
		global $wpdb;
		$table = DB::table( 'event_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %s AND status = 'active' LIMIT 1", $form_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function create( array $data ): int|false {
		global $wpdb;
		$inserted = $wpdb->insert(
			DB::table( 'event_mappings' ),
			[
				'event_name'      => sanitize_text_field( $data['event_name'] ?? '' ),
				'form_id'         => sanitize_text_field( $data['form_id'] ?? '' ),
				'form_title'      => sanitize_text_field( $data['form_title'] ?? '' ),
				'email_field'     => sanitize_text_field( $data['email_field'] ?? 'Email' ),
				'first_name_field' => sanitize_text_field( $data['first_name_field'] ?? 'First Name' ),
				'last_name_field' => sanitize_text_field( $data['last_name_field'] ?? 'Last Name' ),
				'ac_registration_tag' => sanitize_text_field( $data['ac_registration_tag'] ?? '' ),
				'ac_checkin_tag'      => sanitize_text_field( $data['ac_checkin_tag'] ?? '' ),
				'ac_checkout_tag'     => sanitize_text_field( $data['ac_checkout_tag'] ?? '' ),
				'status'              => 'active',
				'created_at'          => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$fields = [];
		$formats = [];

		$allowed = [
			'event_name'          => '%s',
			'form_id'             => '%s',
			'form_title'          => '%s',
			'email_field'         => '%s',
			'first_name_field'    => '%s',
			'last_name_field'     => '%s',
			'ac_registration_tag' => '%s',
			'ac_checkin_tag'      => '%s',
			'ac_checkout_tag'     => '%s',
			'status'              => '%s',
		];

		foreach ( $allowed as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = sanitize_text_field( $data[ $key ] );
				$formats[]      = $fmt;
			}
		}

		if ( empty( $fields ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			DB::table( 'event_mappings' ),
			$fields,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return false !== $result;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			DB::table( 'event_mappings' ),
			[ 'id' => $id ],
			[ '%d' ]
		);
		return false !== $result;
	}
}
