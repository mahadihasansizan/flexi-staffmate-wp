<?php
namespace Techzu\Rewards;

use Techzu\Rewards\Admin\Settings_Page;
use Techzu\Rewards\Frontend\Display;
use Techzu\Rewards\Integrations\Elementor;
use Techzu\Rewards\Logger;
use Techzu\Rewards\Rewards\Birthday_Discount_Manager;
use Techzu\Rewards\Rewards\Calculator;
use Techzu\Rewards\Rewards\Maintenance;
use Techzu\Rewards\Rewards\Order_Manager;
use Techzu\Rewards\Rewards\Points_Manager;
use Techzu\Rewards\Rewards\Redemption_Manager;
use Techzu\Rewards\Rewards\Tier_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    protected static $instance = null;

    /**
     * Whether the plugin has already booted.
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * Get the singleton instance.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Activation routine.
     *
     * @return void
     */
    public static function activate() {
        Settings::ensure_defaults();
        add_rewrite_endpoint( 'rewards', EP_ROOT | EP_PAGES );
        if ( class_exists( '\Techzu\Rewards\Rewards\Maintenance' ) ) {
            Maintenance::schedule();
        }
        flush_rewrite_rules();
    }

    /**
     * Deactivation routine.
     *
     * @return void
     */
    public static function deactivate() {
        if ( class_exists( '\Techzu\Rewards\Rewards\Maintenance' ) ) {
            Maintenance::unschedule();
        }
        flush_rewrite_rules();
    }

    /**
     * Boot the plugin.
     *
     * @return void
     */
    public function boot() {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;

        load_plugin_textdomain( 'techzu-rewards', false, dirname( plugin_basename( TZ_REWARDS_PLUGIN_FILE ) ) . '/languages' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
            return;
        }

        $settings     = new Settings();
        $logger       = new Logger( $settings );
        $points       = new Points_Manager( $settings );
        $calculator   = new Calculator( $settings );
        $tiers        = new Tier_Manager( $settings );
        $redemption   = new Redemption_Manager( $settings, $points, $calculator, $logger );
        $birthday     = new Birthday_Discount_Manager( $settings, $tiers, $calculator, $logger );
        $orders       = new Order_Manager( $settings, $points, $calculator, $redemption, $logger );
        $display      = new Display( $settings, $points, $calculator, $redemption, $tiers, $birthday );
        $admin_page   = new Settings_Page( $settings, $calculator, $points, $tiers );
        $maintenance  = new Maintenance( $points );
        $elementor    = new Elementor();

        $redemption->hooks();
        $birthday->hooks();
        $orders->hooks();
        $display->hooks();
        $admin_page->hooks();
        $maintenance->hooks();
        $elementor->hooks();
    }

    /**
     * Render the WooCommerce dependency notice.
     *
     * @return void
     */
    public function render_woocommerce_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'Techzu Rewards for WooCommerce requires WooCommerce to be installed and active.', 'techzu-rewards' );
        echo '</p></div>';
    }
}
