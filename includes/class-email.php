<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Email {

	private const OPTION_TEMPLATE         = 'checkee_email_template';
	private const OPTION_SUBJECT          = 'checkee_email_subject';
	private const OPTION_RESEND_TEMPLATE  = 'checkee_resend_email_template';
	private const OPTION_RESEND_SUBJECT   = 'checkee_resend_email_subject';
	private const OPTION_FROM_NAME        = 'checkee_email_from_name';
	private const OPTION_FROM_EMAIL       = 'checkee_email_from_email';

	public static function send_confirmation( array $attendee, array $mapping ): bool {
		return self::send( 'confirmation', self::get_subject(), self::get_template(), $attendee, $mapping );
	}

	/** Same QR code, different copy — makes clear this is a reminder, not a fresh registration. */
	public static function send_resend( array $attendee, array $mapping ): bool {
		return self::send( 'resend', self::get_resend_subject(), self::get_resend_template(), $attendee, $mapping );
	}

	private static function send( string $context, string $subject_template, string $body_template, array $attendee, array $mapping ): bool {
		$to      = $attendee['email'];
		$subject = self::render_subject( $subject_template, $attendee, $mapping );
		$body    = self::render_body( $body_template, $attendee, $mapping );
		$headers = self::build_headers();

		add_filter( 'wp_mail_content_type', [ self::class, 'html_content_type' ] );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_filter( 'wp_mail_content_type', [ self::class, 'html_content_type' ] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "Checkee | Email::send ({$context}) to=" . $to . ' sent=' . ( $sent ? 'true' : 'false' ) );
			if ( ! $sent ) {
				global $phpmailer;
				if ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
					error_log( 'Checkee | PHPMailer error: ' . $phpmailer->ErrorInfo );
				}
			}
		}

		return $sent;
	}

	public static function html_content_type(): string {
		return 'text/html';
	}

	public static function render( array $attendee, array $mapping ): string {
		return self::render_body( self::get_template(), $attendee, $mapping );
	}

	private static function render_body( string $template, array $attendee, array $mapping ): string {
		$checkin_url = QR::get_checkin_url( $attendee['qr_token'] );
		$full_name   = Attendees::full_name( $attendee );
		$qr_img_src  = QR::get_image_url( $attendee['qr_token'] );

		$replacements = [
			'{{first_name}}'   => esc_html( $attendee['first_name'] ?? '' ),
			'{{last_name}}'    => esc_html( $attendee['last_name'] ?? '' ),
			'{{full_name}}'    => esc_html( $full_name ),
			'{{email}}'        => esc_html( $attendee['email'] ?? '' ),
			'{{event_name}}'   => esc_html( $mapping['event_name'] ?? $attendee['event_name'] ?? '' ),
			'{{checkin_url}}'  => esc_url( $checkin_url ),
			'{{qr_code}}'      => '<img src="' . esc_url( $qr_img_src ) . '" width="200" height="200" alt="QR Code" style="display:block;border:3px solid #e2e8f0;border-radius:6px;">',
			'{{site_name}}'    => esc_html( get_bloginfo( 'name' ) ),
			'{{site_url}}'     => esc_url( home_url() ),
		];

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	private static function render_subject( string $subject, array $attendee, array $mapping ): string {
		return str_replace(
			[ '{{event_name}}', '{{first_name}}', '{{last_name}}', '{{full_name}}' ],
			[
				$mapping['event_name'] ?? $attendee['event_name'] ?? '',
				$attendee['first_name'] ?? '',
				$attendee['last_name'] ?? '',
				Attendees::full_name( $attendee ),
			],
			$subject
		);
	}

	public static function get_template(): string {
		$saved = get_option( self::OPTION_TEMPLATE, '' );
		return $saved !== '' ? $saved : self::default_template();
	}

	public static function save_template( string $html ): void {
		update_option( self::OPTION_TEMPLATE, wp_kses_post( $html ) );
	}

	public static function get_subject(): string {
		return get_option( self::OPTION_SUBJECT, 'Your registration for {{event_name}} is confirmed' );
	}

	public static function save_subject( string $subject ): void {
		update_option( self::OPTION_SUBJECT, sanitize_text_field( $subject ) );
	}

	public static function get_resend_template(): string {
		$saved = get_option( self::OPTION_RESEND_TEMPLATE, '' );
		return $saved !== '' ? $saved : self::default_resend_template();
	}

	public static function save_resend_template( string $html ): void {
		update_option( self::OPTION_RESEND_TEMPLATE, wp_kses_post( $html ) );
	}

	public static function get_resend_subject(): string {
		return get_option( self::OPTION_RESEND_SUBJECT, 'Your QR code for {{event_name}} — here it is again' );
	}

	public static function save_resend_subject( string $subject ): void {
		update_option( self::OPTION_RESEND_SUBJECT, sanitize_text_field( $subject ) );
	}

	public static function get_from_name(): string {
		return get_option( self::OPTION_FROM_NAME, get_bloginfo( 'name' ) );
	}

	public static function get_from_email(): string {
		return get_option( self::OPTION_FROM_EMAIL, get_option( 'admin_email' ) );
	}

	private static function build_headers(): array {
		$from_name  = self::get_from_name();
		$from_email = self::get_from_email();
		return [ "From: {$from_name} <{$from_email}>" ];
	}

	// phpcs:disable Generic.Functions.FunctionCallArgumentSpacing
	private static function default_template(): string {
		$site = get_bloginfo( 'name' );
		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registration Confirmed</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 16px;">
  <tr>
    <td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;">
        <tr>
          <td style="padding:28px 32px 12px;">
            <p style="margin:0;font-size:16px;line-height:28px;font-weight:500;color:#111827;letter-spacing:-0.7px;">
              {$site}
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px;">
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;">
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 12px;font-size:22px;line-height:32px;font-weight:700;color:#111827;letter-spacing:-0.7px;">
              Registration confirmed!
            </p>
            <p style="margin:0 0 24px;font-size:16px;line-height:24px;color:#4b5563;">
              Hi {{first_name}}, your registration for
              <strong style="color:#111827;">{{event_name}}</strong>
              has been confirmed. Please show this QR code at the door to check in.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
              <tr>
                <td align="center" style="padding:28px 24px;">
                  {{qr_code}}
                </td>
              </tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding-top:32px;">
                  <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 18px;">
                  <p style="margin:0;font-size:14px;line-height:20px;color:#9ca3af;">
                    Sent by {{site_name}}
                    &middot;
                    <a href="{{site_url}}" style="color:#9ca3af;text-decoration:none;">{{site_url}}</a>
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
	}

	private static function default_resend_template(): string {
		$site = get_bloginfo( 'name' );
		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your QR Code</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 16px;">
  <tr>
    <td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;">
        <tr>
          <td style="padding:28px 32px 12px;">
            <p style="margin:0;font-size:16px;line-height:28px;font-weight:500;color:#111827;letter-spacing:-0.7px;">
              {$site}
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px;">
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;">
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 12px;font-size:22px;line-height:32px;font-weight:700;color:#111827;letter-spacing:-0.7px;">
              Your QR code, resent
            </p>
            <p style="margin:0 0 24px;font-size:16px;line-height:24px;color:#4b5563;">
              Hi {{first_name}}, <strong style="color:#111827;">{{event_name}}</strong>
              is coming up soon, and we wanted to resend your QR code ahead of time so
              you have it handy. It's the same code from when you registered — please
              show it at the door to check in.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
              <tr>
                <td align="center" style="padding:28px 24px;">
                  {{qr_code}}
                </td>
              </tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding-top:32px;">
                  <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 18px;">
                  <p style="margin:0;font-size:14px;line-height:20px;color:#9ca3af;">
                    Sent by {{site_name}}
                    &middot;
                    <a href="{{site_url}}" style="color:#9ca3af;text-decoration:none;">{{site_url}}</a>
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
	}
	// phpcs:enable
}
