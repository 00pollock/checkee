<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Events {

	private const CACHE_KEY = 'checkee_events';
	private const CACHE_TTL = 600; // 10 minutes

	public static function get_all(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = DB::table( 'attendees' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT event_name, COUNT(*) as total FROM {$table} GROUP BY event_name ORDER BY event_name ASC" );

		$events = [];
		foreach ( $rows as $row ) {
			$events[] = [
				'name'  => $row->event_name,
				'total' => (int) $row->total,
			];
		}

		set_transient( self::CACHE_KEY, $events, self::CACHE_TTL );
		return $events;
	}

	public static function get_settings( string $event_name ): array {
		$defaults = [
			'ac_checkin_tag'  => '',
			'ac_checkout_tag' => '',
		];
		$stored = get_option( 'checkee_event_' . md5( $event_name ), [] );
		return array_merge( $defaults, (array) $stored );
	}

	public static function save_settings( string $event_name, array $settings ): void {
		$allowed = [ 'ac_checkin_tag', 'ac_checkout_tag' ];
		$clean   = array_intersect_key( $settings, array_flip( $allowed ) );
		update_option( 'checkee_event_' . md5( $event_name ), $clean, false );
		delete_transient( self::CACHE_KEY );
	}

	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
