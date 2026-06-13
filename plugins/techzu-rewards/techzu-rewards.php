<?php
/**
 * Plugin Name:       Techzu Rewards for WooCommerce
 * Plugin URI:        https://techzu.site/
 * Description:       Complete WooCommerce loyalty programme with points, fixed reward vouchers, membership tiers, birthday perks, point expiry, customer controls, Elementor support and a built-in Guide app.
 * Version:           2.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Techzu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techzu-rewards
 * Domain Path:       /languages
 * WC requires at least: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TZ_REWARDS_VERSION', '2.1.0' );
define( 'TZ_REWARDS_PLUGIN_FILE', __FILE__ );
define( 'TZ_REWARDS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'TZ_REWARDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TZ_REWARDS_PLUGIN_PATH . 'includes/Autoloader.php';

Techzu\Rewards\Autoloader::register();

register_activation_hook( __FILE__, array( 'Techzu\\Rewards\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Techzu\\Rewards\\Plugin', 'deactivate' ) );

add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TZ_REWARDS_PLUGIN_FILE, true );
        }
    }
);

add_action(
    'plugins_loaded',
    static function () {
        Techzu\Rewards\Plugin::instance()->boot();
    }
);
