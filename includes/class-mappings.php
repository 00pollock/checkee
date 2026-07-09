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

	public static function find_by_staff_slug( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}
		global $wpdb;
		$table = DB::table( 'event_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE staff_slug = %s LIMIT 1", $slug ),
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
				'staff_slug'          => self::generate_staff_slug(),
				'staff_pin'           => self::generate_staff_pin(),
				'created_at'          => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/** Issues a fresh staff URL + PIN for a mapping, invalidating the old ones. */
	public static function regenerate_staff_access( int $id ): array {
		global $wpdb;
		$slug = self::generate_staff_slug();
		$pin  = self::generate_staff_pin();
		$wpdb->update(
			DB::table( 'event_mappings' ),
			[ 'staff_slug' => $slug, 'staff_pin' => $pin ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		return [ 'staff_slug' => $slug, 'staff_pin' => $pin ];
	}

	public static function generate_staff_slug(): string {
		return strtolower( wp_generate_password( 24, false, false ) );
	}

	public static function generate_staff_pin(): string {
		return str_pad( (string) wp_rand( 0, 9999 ), 4, '0', STR_PAD_LEFT );
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
