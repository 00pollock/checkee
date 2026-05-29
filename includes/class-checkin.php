<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Checkin {

	public static function register_rewrite_rule(): void {
		add_rewrite_rule( '^checkee-checkin/?$', 'index.php?checkee_checkin=1', 'top' );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'checkee_checkin';
			return $vars;
		} );
	}

	public static function handle_request(): void {
		if ( ! get_query_var( 'checkee_checkin' ) ) {
			return;
		}

		$token = sanitize_text_field( $_GET['token'] ?? '' );

		if ( ! $token ) {
			self::render_result_page( 'error', __( 'Invalid QR code — no token found.', 'checkee' ), null );
			exit;
		}

		$attendee = Attendees::find_by_token( $token );
		if ( ! $attendee ) {
			self::render_result_page( 'error', __( 'QR code not recognised. Please contact the event organiser.', 'checkee' ), null );
			exit;
		}

		// Already checked in — show status without re-processing
		if ( $attendee['status'] === 'checked_in' ) {
			self::render_result_page( 'already', '', $attendee );
			exit;
		}

		// Auto check-in on first scan
		$result = self::process( $token, 'in' );
		if ( $result['success'] ) {
			self::render_result_page( 'success', '', $attendee );
		} else {
			self::render_result_page( 'error', $result['message'], $attendee );
		}
		exit;
	}

	public static function process( string $token, string $action ): array {
		$attendee = Attendees::find_by_token( $token );
		if ( ! $attendee ) {
			return [ 'success' => false, 'message' => __( 'Attendee not found.', 'checkee' ) ];
		}

		$new_status = $action === 'in' ? 'checked_in' : 'checked_out';
		$updated    = Attendees::update_status( (int) $attendee['id'], $new_status );

		if ( ! $updated ) {
			return [ 'success' => false, 'message' => __( 'Failed to update status.', 'checkee' ) ];
		}

		self::log( (int) $attendee['id'], $action );
		self::sync_ac_tags( $attendee, $action );

		$attendee['status'] = $new_status;

		return [
			'success'  => true,
			'attendee' => [
				'id'         => $attendee['id'],
				'name'       => Attendees::full_name( $attendee ),
				'first_name' => $attendee['first_name'] ?? '',
				'last_name'  => $attendee['last_name']  ?? '',
				'email'      => $attendee['email'],
				'event'      => $attendee['event_name'],
				'status'     => $new_status,
			],
		];
	}

	private static function log( int $attendee_id, string $action ): void {
		global $wpdb;
		$table = DB::table( 'checkin_log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			[
				'attendee_id' => $attendee_id,
				'action'      => $action,
				'by_user'     => (int) get_current_user_id(),
				'at'          => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%d', '%s' ]
		);
	}

	private static function sync_ac_tags( array $attendee, string $action ): void {
		$ac = new ActiveCampaign();
		if ( ! $ac->is_configured() ) {
			return;
		}

		$contact_id = $ac->find_contact( $attendee['email'] );
		if ( ! $contact_id ) {
			return;
		}

		$mapping = Mappings::find_by_id( (int) ( $attendee['event_mapping_id'] ?? 0 ) );
		if ( ! $mapping ) {
			return;
		}

		if ( $action === 'in' && ! empty( $mapping['ac_checkin_tag'] ) ) {
			$ac->add_tag( $contact_id, $mapping['ac_checkin_tag'] );
		}
		if ( $action === 'out' ) {
			if ( ! empty( $mapping['ac_checkin_tag'] ) ) {
				$ac->remove_tag( $contact_id, $mapping['ac_checkin_tag'] );
			}
			if ( ! empty( $mapping['ac_checkout_tag'] ) ) {
				$ac->add_tag( $contact_id, $mapping['ac_checkout_tag'] );
			}
		}
	}

	/**
	 * Render the auto-check-in result page — no buttons, just a status screen.
	 *
	 * @param string     $state   'success' | 'already' | 'error'
	 * @param string     $message Error message (only used when $state === 'error')
	 * @param array|null $attendee
	 */
	private static function render_result_page( string $state, string $message, ?array $attendee ): void {
		$site    = esc_html( get_bloginfo( 'name' ) );
		$css_url = esc_url( CHECKEE_URL . 'assets/checkin.css' );

		$name  = $attendee ? esc_html( Attendees::full_name( $attendee ) ) : '';
		$event = $attendee ? esc_html( $attendee['event_name'] ) : '';

		if ( $state === 'success' ) {
			$icon    = '✓';
			$icon_class = 'ck-icon--success';
			$heading = 'You\'re checked in!';
			$sub     = "Welcome, {$name}. Enjoy <strong>{$event}</strong>.";
		} elseif ( $state === 'already' ) {
			$icon    = '✓';
			$icon_class = 'ck-icon--already';
			$heading = 'Already checked in';
			$sub     = "{$name} is already checked in for <strong>{$event}</strong>.";
		} else {
			$icon    = '✕';
			$icon_class = 'ck-icon--error';
			$heading = 'Check-in failed';
			$sub     = esc_html( $message );
		}

		// phpcs:disable WordPress.Security.EscapeOutput
		echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$site} — Check In</title>
<link rel="stylesheet" href="{$css_url}">
</head>
<body class="ck-body">
<div class="ck-result-card">
  <div class="ck-result-icon {$icon_class}">{$icon}</div>
  <div class="ck-result-heading">{$heading}</div>
  <div class="ck-result-sub">{$sub}</div>
  <div class="ck-result-site">{$site}</div>
</div>
</body>
</html>
HTML;
		// phpcs:enable
	}

	private static function render_error_page( string $message ): void {
		$site    = esc_html( get_bloginfo( 'name' ) );
		$msg_esc = esc_html( $message );
		$css_url = esc_url( CHECKEE_URL . 'assets/checkin.css' );
		// phpcs:disable WordPress.Security.EscapeOutput
		echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$site} — Check In</title>
<link rel="stylesheet" href="{$css_url}">
</head>
<body class="ck-body">
<div class="ck-card">
  <div class="ck-header"><span class="ck-site">{$site}</span></div>
  <div class="ck-body-inner">
    <p class="ck-error">{$msg_esc}</p>
  </div>
</div>
</body>
</html>
HTML;
		// phpcs:enable
	}
}
