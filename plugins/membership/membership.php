<?php
/**
 * Plugin Name: Membership
 * Plugin URI: https://techzu.site/
 * Description: Private login gate, dynamic membership roles, user membership assignment, and WooCommerce member pricing rules.
 * Version: 2.1.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Techzu
 * Author URI: https://techzu.site/
 * Text Domain: membership
 * Domain Path: /languages
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 *
 * @package Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MEMBERSHIP_VERSION', '2.1.0' );
define( 'MEMBERSHIP_FILE', __FILE__ );
define( 'MEMBERSHIP_BASENAME', plugin_basename( __FILE__ ) );
define( 'MEMBERSHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEMBERSHIP_URL', plugin_dir_url( __FILE__ ) );

require_once MEMBERSHIP_DIR . 'includes/class-membership-autoloader.php';
Membership\Autoloader::register();

register_activation_hook( __FILE__, array( 'Membership\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Membership\\Activator', 'deactivate' ) );

add_action(
    'before_woocommerce_init',
    static function (): void {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        Membership\Plugin::instance()->boot();
    },
    20
);
