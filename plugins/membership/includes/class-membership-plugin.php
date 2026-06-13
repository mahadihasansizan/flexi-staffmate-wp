<?php
/**
 * Main plugin bootstrap.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Boots all modules.
 */
final class Plugin {
    /** @var Plugin|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /** @var Roles */
    private $roles;

    /**
     * Get singleton.
     *
     * @return Plugin
     */
    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot plugin.
     *
     * @return void
     */
    public function boot(): void {
        $this->settings = Settings::instance();
        $this->roles    = Roles::instance( $this->settings );

        load_plugin_textdomain( 'membership', false, dirname( MEMBERSHIP_BASENAME ) . '/languages' );

        add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
        add_filter( 'plugin_action_links_' . MEMBERSHIP_BASENAME, array( $this, 'plugin_action_links' ) );

        $this->roles->hooks();
        Assets::instance()->hooks();
        LoginGate::instance( $this->settings )->hooks();
        Users::instance( $this->settings, $this->roles )->hooks();
        AdminMenu::instance( $this->settings, $this->roles )->hooks();

        if ( class_exists( 'WooCommerce' ) ) {
            ProductPricingPanel::instance( $this->settings )->hooks();
            Pricing::instance( $this->settings, $this->roles )->hooks();
        }
    }

    /**
     * Upgrade routine.
     *
     * @return void
     */
    public function maybe_upgrade(): void {
        $stored_version = (string) get_option( 'membership_version', '' );
        if ( MEMBERSHIP_VERSION === $stored_version ) {
            return;
        }

        if ( false === get_option( Settings::LEVELS_OPTION, false ) ) {
            $this->settings->update_levels( $this->settings->default_levels() );
        }

        $settings = $this->settings->get_settings();
        if ( empty( $settings['login_page_id'] ) || ! get_post( (int) $settings['login_page_id'] ) ) {
            $settings['login_page_id'] = Activator::create_login_page();
            $this->settings->update_settings( $settings );
        }

        $this->roles->sync_roles();
        update_option( 'membership_version', MEMBERSHIP_VERSION, false );
    }

    /**
     * Add plugin action links.
     *
     * @param array<int|string,string> $links Links.
     * @return array<int|string,string>
     */
    public function plugin_action_links( array $links ): array {
        array_unshift(
            $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=membership' ) ) . '">' . esc_html__( 'Membership', 'membership' ) . '</a>'
        );

        return $links;
    }
}
