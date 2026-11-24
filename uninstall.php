<?php
/**
 * Удаление плагина: таблицы, опции, mu-plugin загрузчик, cron.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plp_results" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plp_scans" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'plp_settings' );
delete_option( 'plp_db_version' );
delete_option( 'plp_active_scan' );
delete_option( 'plp_mu_error' );

wp_clear_scheduled_hook( 'plp_cleanup' );

$plp_mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
$plp_mu     = trailingslashit( $plp_mu_dir ) . 'plp-loader.php';

if ( file_exists( $plp_mu ) ) {
	$plp_content = file_get_contents( $plp_mu ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== $plp_content && false !== strpos( $plp_content, 'PLP-MU-LOADER' ) ) {
		@unlink( $plp_mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
