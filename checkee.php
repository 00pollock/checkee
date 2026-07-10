<?php
/**
 * Plugin Name:       Checkee
 * Plugin URI:        https://github.com/00pollock/checkee
 * Description:       Post-registration operations for WordPress events: attendee management, QR check-in, and ActiveCampaign tag sync. Works with Kadence Forms.
 * Version:           1.5.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            George Okanga
 * License:           GPL-2.0-or-later
 * Text Domain:       checkee
 */

defined( 'ABSPATH' ) || exit;

define( 'CHECKEE_VERSION', '1.5.0' );
define( 'CHECKEE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CHECKEE_URL',     plugin_dir_url( __FILE__ ) );

// Auto-updates via GitHub releases
require_once CHECKEE_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$checkee_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/00pollock/checkee/',
	__FILE__,
	'checkee'
);
$checkee_updater->getVcsApi()->enableReleaseAssets();

// Autoloader: Checkee\ClassName → includes/class-classname.php (CamelCase → kebab-case)
spl_autoload_register( function ( string $fqcn ) {
	if ( ! str_starts_with( $fqcn, 'Checkee\\' ) ) {
		return;
	}
	$class     = substr( $fqcn, strlen( 'Checkee\\' ) );
	$kebab     = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $class );
	$kebab     = preg_replace( '/([a-z\d])([A-Z])/', '$1-$2', $kebab );
	$file_name = 'class-' . strtolower( $kebab ) . '.php';

	if ( 'Admin' === $class ) {
		$file = CHECKEE_DIR . 'admin/admin-page.php';
	} else {
		$file = CHECKEE_DIR . 'includes/' . $file_name;
	}

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

use Checkee\DB;
use Checkee\Mappings;
use Checkee\Forms;
use Checkee\Attendees;
use Checkee\Email;
use Checkee\Checkin;
use Checkee\Admin;
use Checkee\StaffPortal;

register_activation_hook( __FILE__, function () {
	try {
		DB::install();
		add_action( 'init', [ Checkin::class, 'register_rewrite_rule' ] );
		flush_rewrite_rules();
	} catch ( \Throwable $e ) {
		wp_die(
			'<h2>Checkee activation error</h2><pre>' . esc_html( $e->getMessage() ) . "\n\n" . esc_html( $e->getTraceAsString() ) . '</pre>',
			'Checkee Activation Failed',
			[ 'back_link' => true ]
		);
	}
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', function () {
	try {
		checkee_boot();
	} catch ( \Throwable $e ) {
		add_action( 'admin_notices', function () use ( $e ) {
			echo '<div class="notice notice-error"><p><strong>Checkee error:</strong> '
				. esc_html( $e->getMessage() ) . ' in <code>'
				. esc_html( $e->getFile() ) . ':' . (int) $e->getLine() . '</code></p></div>';
		} );
	}
} );

function checkee_boot(): void {

	// Run DB migrations when the stored version is behind the current one
	if ( get_option( 'checkee_db_version' ) !== DB::VERSION ) {
		DB::install();
	}

	// Flush rewrite rules once per plugin version, so new rewrite rules (like the staff
	// check-in page) actually take effect on sites that update rather than fresh-install.
	if ( get_option( 'checkee_rewrite_version' ) !== CHECKEE_VERSION ) {
		add_action( 'init', 'flush_rewrite_rules', 20 );
		update_option( 'checkee_rewrite_version', CHECKEE_VERSION );
	}

	// Kadence Advanced Form (kadence/advanced-form block) fires this hook on submission.
	// Accept up to 6 args and log them first so we can confirm the exact signature.
	add_action( 'kadence_blocks_advanced_form_submission', function () {
		$args    = func_get_args();
		$num     = count( $args );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Checkee | kadence_blocks_advanced_form_submission fired with ' . $num . ' args' );
			error_log( 'Checkee | args dump: ' . wp_json_encode( $args ) );
		}

		// Best-guess signature based on older hook: ($form_args, $fields, $form_id, $post_id)
		$form_args = $args[0] ?? [];
		$fields    = $args[1] ?? [];
		$form_id   = $args[2] ?? '';
		$post_id   = $args[3] ?? 0;

		$mapping = Mappings::find_by_form_id( (string) $form_id );
		if ( ! $mapping ) {
			$mapping = Mappings::find_by_form_id( (string) $post_id );
		}
		if ( ! $mapping ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Checkee | No mapping found. form_id=' . $form_id . ' post_id=' . $post_id );
			}
			return;
		}

		$fields     = (array) $fields;
		$email      = Forms::extract_field( $fields, $mapping['email_field'] );
		$first_name = Forms::extract_field( $fields, $mapping['first_name_field'] );
		$last_name  = Forms::extract_field( $fields, $mapping['last_name_field'] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Checkee | extracted email=' . $email . ' first=' . $first_name . ' last=' . $last_name );
		}

		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		if ( Attendees::find_by_email_event( $email, (int) $mapping['id'] ) ) {
			return;
		}

		$metadata = [];
		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$metadata[ sanitize_text_field( $field['label'] ?? '' ) ] = sanitize_text_field( $field['value'] ?? '' );
			}
		}

		$id = Attendees::create( [
			'event_mapping_id' => (int) $mapping['id'],
			'event_name'       => $mapping['event_name'],
			'first_name'       => $first_name,
			'last_name'        => $last_name,
			'email'            => $email,
			'metadata'         => wp_json_encode( $metadata ),
		] );

		if ( $id ) {
			$attendee = Attendees::find_by_id( $id );
			if ( $attendee ) {
				Checkin::apply_registration_tag( $attendee, $mapping );
				Email::send_confirmation( $attendee, $mapping );
			}
		}
	}, 10, 6 );

	// Rewrite rule for QR check-in page
	add_action( 'init', [ Checkin::class, 'register_rewrite_rule' ] );

	// Handle check-in page requests
	add_action( 'template_redirect', [ Checkin::class, 'handle_request' ] );

	// Public staff check-in page — /checkee/{slug} — no WP login, PIN-gated per event
	add_action( 'init', [ StaffPortal::class, 'register_rewrite_rule' ] );
	add_action( 'template_redirect', [ StaffPortal::class, 'handle_request' ] );
	add_action( 'wp_ajax_checkee_staff_search',                 [ StaffPortal::class, 'ajax_search' ] );
	add_action( 'wp_ajax_nopriv_checkee_staff_search',          [ StaffPortal::class, 'ajax_search' ] );
	add_action( 'wp_ajax_checkee_staff_manual_checkin',         [ StaffPortal::class, 'ajax_manual_checkin' ] );
	add_action( 'wp_ajax_nopriv_checkee_staff_manual_checkin',  [ StaffPortal::class, 'ajax_manual_checkin' ] );
	add_action( 'wp_ajax_checkee_staff_add_walkin',             [ StaffPortal::class, 'ajax_add_walkin' ] );
	add_action( 'wp_ajax_nopriv_checkee_staff_add_walkin',      [ StaffPortal::class, 'ajax_add_walkin' ] );

	// Admin
	if ( is_admin() ) {
		add_action( 'admin_menu',                            [ Admin::class, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',                 [ Admin::class, 'enqueue_assets' ] );
		add_action( 'admin_post_checkee_create_event',       [ Admin::class, 'handle_create_event' ] );
		add_action( 'admin_post_checkee_update_event',       [ Admin::class, 'handle_update_event' ] );
		add_action( 'admin_post_checkee_delete_event',       [ Admin::class, 'handle_delete_event' ] );
		add_action( 'admin_post_checkee_regenerate_staff',   [ Admin::class, 'handle_regenerate_staff' ] );
		add_action( 'admin_post_checkee_delete_attendee',      [ Admin::class, 'handle_delete_attendee' ] );
		add_action( 'admin_post_checkee_add_walkin',           [ Admin::class, 'handle_add_walkin' ] );
		add_action( 'admin_post_checkee_save_settings',      [ Admin::class, 'handle_save_settings' ] );
		add_action( 'admin_post_checkee_save_email',         [ Admin::class, 'handle_save_email' ] );
		add_action( 'admin_post_checkee_save_resend_email',  [ Admin::class, 'handle_save_resend_email' ] );
		add_action( 'wp_ajax_checkee_test_ac',               [ Admin::class, 'ajax_test_ac' ] );
		add_action( 'wp_ajax_checkee_get_form_fields',       [ Admin::class, 'ajax_get_form_fields' ] );
		add_action( 'wp_ajax_checkee_scan_checkin',          [ Admin::class, 'ajax_scan_checkin' ] );
		add_action( 'wp_ajax_checkee_sync_ac_attendance',    [ Admin::class, 'ajax_sync_ac_attendance' ] );
		add_action( 'wp_ajax_checkee_resend_qr_batch',       [ Admin::class, 'ajax_resend_qr_batch' ] );
		add_action( 'wp_ajax_checkee_search_attendees',      [ Admin::class, 'ajax_search_attendees' ] );
		add_action( 'wp_ajax_checkee_manual_checkin',        [ Admin::class, 'ajax_manual_checkin' ] );
	}
}
