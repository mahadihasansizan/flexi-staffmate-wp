<?php
/**
 * Asset loader.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueues admin and frontend assets.
 */
final class Assets {
    /** @var Assets|null */
    private static $instance = null;

    /**
     * Get singleton.
     *
     * @return Assets
     */
    public static function instance(): Assets {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
    }

    /**
     * Admin assets.
     *
     * @param string $hook_suffix Hook suffix.
     * @return void
     */
    public function admin_assets( string $hook_suffix ): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';
        $load = false;

        if ( false !== strpos( $hook_suffix, 'membership' ) || false !== strpos( $screen_id, 'membership' ) ) {
            $load = true;
        }

        if ( in_array( $hook_suffix, array( 'user-new.php', 'user-edit.php', 'profile.php', 'post.php', 'post-new.php' ), true ) ) {
            $load = true;
        }

        if ( ! $load ) {
            return;
        }

        wp_enqueue_style( 'membership-admin', MEMBERSHIP_URL . 'assets/admin.css', array(), MEMBERSHIP_VERSION );
        wp_enqueue_script( 'membership-admin', MEMBERSHIP_URL . 'assets/admin.js', array( 'jquery' ), MEMBERSHIP_VERSION, true );
        wp_localize_script(
            'membership-admin',
            'MembershipAdmin',
            array(
                'confirmDelete' => __( 'Remove this membership level? Save the form to apply the change.', 'membership' ),
            )
        );
    }

    /**
     * Frontend assets.
     *
     * @return void
     */
    public function frontend_assets(): void {
        wp_register_style( 'membership-frontend', MEMBERSHIP_URL . 'assets/frontend.css', array(), MEMBERSHIP_VERSION );
    }
}
