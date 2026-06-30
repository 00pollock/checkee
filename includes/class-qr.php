<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class QR {

	public static function generate_token(): string {
		return bin2hex( random_bytes( 24 ) ); // 48-char hex
	}

	public static function get_checkin_url( string $token ): string {
		if ( API::is_connected() ) {
			return API::checkin_url( $token );
		}
		return home_url( '/checkee-checkin/' ) . '?token=' . rawurlencode( $token );
	}

	public static function get_image_url( string $token ): string {
		$cache_key = 'checkee_qr_' . $token;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$checkin_url = self::get_checkin_url( $token );
		$image_url   = add_query_arg(
			[
				'size' => '250x250',
				'data' => rawurlencode( $checkin_url ),
			],
			'https://api.qrserver.com/v1/create-qr-code/'
		);

		// Cache indefinitely (token never changes)
		set_transient( $cache_key, $image_url, YEAR_IN_SECONDS );
		return $image_url;
	}
}
