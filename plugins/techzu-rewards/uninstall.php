<?php
/**
 * Uninstall Techzu Rewards for WooCommerce.
 *
 * Customer reward data is intentionally preserved unless the site owner defines
 * TECHZU_REWARDS_REMOVE_DATA as true before uninstalling.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

wp_clear_scheduled_hook( 'tz_rewards_daily_maintenance' );

if ( ! defined( 'TECHZU_REWARDS_REMOVE_DATA' ) || true !== TECHZU_REWARDS_REMOVE_DATA ) {
    return;
}

delete_option( 'tz_rewards_settings' );

global $wpdb;

$meta_keys = array(
    'tz_rewards_points_balance',
    'tz_rewards_points_lots',
    'tz_rewards_points_log',
    'tz_rewards_legacy_points_migrated',
    'tz_rewards_birthday',
    'tz_rewards_manual_tier',
);

foreach ( $meta_keys as $meta_key ) {
    $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'tz_rewards_birthday_used_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
