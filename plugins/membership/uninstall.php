<?php
/**
 * Uninstall cleanup for Membership.
 *
 * @package Membership
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$managed_roles = get_option( 'membership_managed_roles', array() );
if ( is_array( $managed_roles ) ) {
    foreach ( array_keys( $managed_roles ) as $role_name ) {
        if ( is_string( $role_name ) && function_exists( 'remove_role' ) ) {
            remove_role( $role_name );
        }
    }
}

delete_option( 'membership_settings' );
delete_option( 'membership_levels' );
delete_option( 'membership_managed_roles' );
delete_option( 'membership_version' );

global $wpdb;

if ( $wpdb instanceof wpdb ) {
    foreach ( array( 'membership_level_key', 'membership_price_override_enabled', 'membership_price_override_type', 'membership_price_override_amount' ) as $meta_key ) {
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
    }

    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_membership_product_rules' ), array( '%s' ) );
}
