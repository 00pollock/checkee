<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class DB {

	const VERSION = '1.3';

	public static function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Attendees table — dbDelta adds missing columns without destroying data
		$sql1 = "CREATE TABLE {$prefix}checkee_attendees (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_mapping_id BIGINT(20) UNSIGNED DEFAULT NULL,
  event_name VARCHAR(255) NOT NULL DEFAULT '',
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'registered',
  qr_token VARCHAR(64) NOT NULL DEFAULT '',
  metadata LONGTEXT,
  created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY qr_token (qr_token),
  KEY event_mapping_id (event_mapping_id),
  KEY email (email(191)),
  KEY status (status)
) {$charset_collate};";

		dbDelta( $sql1 );

		// Event mappings table
		$sql2 = "CREATE TABLE {$prefix}checkee_event_mappings (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_name VARCHAR(255) NOT NULL DEFAULT '',
  form_id VARCHAR(255) NOT NULL DEFAULT '',
  form_title VARCHAR(255) NOT NULL DEFAULT '',
  email_field VARCHAR(100) NOT NULL DEFAULT 'Email',
  first_name_field VARCHAR(100) NOT NULL DEFAULT 'First Name',
  last_name_field VARCHAR(100) NOT NULL DEFAULT 'Last Name',
  ac_registration_tag VARCHAR(255) NOT NULL DEFAULT '',
  ac_checkin_tag VARCHAR(255) NOT NULL DEFAULT '',
  ac_checkout_tag VARCHAR(255) NOT NULL DEFAULT '',
  checkee_event_id BIGINT(20) UNSIGNED DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset_collate};";

		dbDelta( $sql2 );

		// Check-in log table
		$sql3 = "CREATE TABLE {$prefix}checkee_checkin_log (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  attendee_id BIGINT(20) UNSIGNED NOT NULL,
  action VARCHAR(10) NOT NULL DEFAULT '',
  by_user BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY attendee_id (attendee_id)
) {$charset_collate};";

		dbDelta( $sql3 );

		update_option( 'checkee_db_version', self::VERSION );
	}

	public static function drop_all(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}checkee_checkin_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}checkee_attendees" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}checkee_event_mappings" );
		// phpcs:enable
		delete_option( 'checkee_db_version' );
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'checkee_' . $name;
	}
}
