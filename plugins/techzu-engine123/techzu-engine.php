<?php
/**
 * Plugin Name:       Techzu Engine
 * Plugin URI:        https://techzu.com/
 * Description:       Core Techzu plugin hub: modular admin features with per-module toggles under Techzu Engine settings.
 * Version:           1.2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Techzu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techzu-engine
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TZ_ENGINE_VERSION', '1.2.0' );
define( 'TZ_ENGINE_PLUGIN_FILE', __FILE__ );
define( 'TZ_ENGINE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'TZ_ENGINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TZ_ENGINE_OPTION_KEY', 'techzu_engine_settings' );
define( 'TZ_ENGINE_ADMIN_APPEARANCE_OPTION', 'techzu_engine_admin_appearance' );

require_once TZ_ENGINE_PLUGIN_PATH . 'includes/Autoloader.php';

Techzu\Engine\Autoloader::register();

register_activation_hook( __FILE__, array( 'Techzu\\Engine\\Plugin', 'activate' ) );

add_action(Q
    'plugins_loaded',
    static function () {
        Techzu\Engine\Plugin::instance()->boot();
    }
);

/**
 * Register extra admin sidebar separators by slug (merged with separators saved under Techzu → Appearance).
 * Slugs must match /^tz-sep-[a-zA-Z0-9_-]+$/ — use a unique suffix, e.g. tz-sep-my-plugin.
 *
 * @param string|string[] $slug_or_slugs One slug or a list of slugs.
 * @return void
 */
function tz_engine_register_admin_menu_separators( $slug_or_slugs ) {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $slugs = is_array( $slug_or_slugs ) ? $slug_or_slugs : array( $slug_or_slugs );
    $slugs = array_filter( array_map( 'sanitize_text_field', $slugs ) );
    if ( array() === $slugs ) {
        return;
    }
    $option = get_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION, array() );
    if ( ! is_array( $option ) ) {
        $option = array();
    }
    $existing = isset( $option['custom_separators'] ) && is_array( $option['custom_separators'] ) ? $option['custom_separators'] : array();
    foreach ( $slugs as $slug ) {
        if ( ! preg_match( '/^tz-sep-[a-zA-Z0-9_-]+$/', $slug ) ) {
            continue;
        }
        if ( ! in_array( $slug, $existing, true ) ) {
            $existing[] = $slug;
        }
    }
    $option['custom_separators'] = array_values( $existing );
    update_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION, $option, false );
}
