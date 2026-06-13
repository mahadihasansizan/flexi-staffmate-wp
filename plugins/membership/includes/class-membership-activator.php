<?php
/**
 * Activation tasks.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles activation/deactivation.
 */
final class Activator {
    /**
     * Run activation.
     *
     * @return void
     */
    public static function activate(): void {
        $settings = Settings::instance();

        if ( false === get_option( Settings::LEVELS_OPTION, false ) ) {
            $settings->update_levels( $settings->default_levels() );
        }

        $current_settings = $settings->get_settings();
        if ( empty( $current_settings['login_page_id'] ) || ! get_post( (int) $current_settings['login_page_id'] ) ) {
            $current_settings['login_page_id'] = self::create_login_page();
            $settings->update_settings( $current_settings );
        }

        Roles::instance( $settings )->sync_roles();
        update_option( 'membership_version', MEMBERSHIP_VERSION, false );
    }

    /**
     * Keep data on deactivation.
     *
     * @return void
     */
    public static function deactivate(): void {
        // Settings, roles, and assignments are intentionally kept for safe reactivation.
    }

    /**
     * Create or reuse the login page.
     *
     * @return int Page ID.
     */
    public static function create_login_page(): int {
        foreach ( array( 'membership-login', 'member-login' ) as $slug ) {
            $existing = get_page_by_path( $slug );
            if ( $existing instanceof \WP_Post && 'trash' !== $existing->post_status ) {
                if ( false === strpos( (string) $existing->post_content, '[membership_login_form]' ) ) {
                    wp_update_post(
                        array(
                            'ID'           => $existing->ID,
                            'post_content' => trim( $existing->post_content . "\n\n[membership_login_form]" ),
                        )
                    );
                }
                return (int) $existing->ID;
            }
        }

        $page_id = wp_insert_post(
            array(
                'post_title'   => __( 'Membership Login', 'membership' ),
                'post_name'    => 'membership-login',
                'post_content' => '[membership_login_form]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ),
            true
        );

        return is_wp_error( $page_id ) ? 0 : (int) $page_id;
    }
}
