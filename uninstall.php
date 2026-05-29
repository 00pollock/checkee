<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-db.php';

Checkee\DB::drop_all();

// Remove all plugin options
$options = [
	'checkee_db_version',
	'checkee_ac_url',
	'checkee_ac_key',
	'checkee_email_template',
	'checkee_email_subject',
	'checkee_email_from_name',
	'checkee_email_from_email',
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Remove all transients
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_checkee_%' OR option_name LIKE '_transient_timeout_checkee_%'" );
