<?php
namespace Techzu\Engine\Modules;

use Techzu\Engine\Admin\Login_Page_Settings_Page;
use Techzu\Engine\Admin\Login_Page_Store;
use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Login Page Modification Module
 * 
 * Allows customization of the WordPress login page with custom logo, colors, and styling.
 */
class Login_Page_Module implements Module_Interface {

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var Login_Page_Store
     */
    protected $store;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
        $this->store    = new Login_Page_Store();
    }

    /**
     * Get the module setting key.
     *
     * @return string
     */
    public function setting_key() {
        return 'module_login_page';
    }

    /**
     * Register the module hooks and functionality.
     *
     * @return void
     */
    public function register() {
        // Register admin settings page
        if ( is_admin() && ! is_network_admin() ) {
            $page = new Login_Page_Settings_Page( $this->store, $this->settings );
            $page->hooks();
        }

        // Frontend login page customization
        add_filter( 'login_headerurl', array( $this, 'filter_login_header_url' ) );
        add_filter( 'login_headertext', array( $this, 'filter_login_header_text' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_styles' ) );
        add_action( 'login_form', array( $this, 'maybe_hide_login_hints' ) );
    }

    /**
     * Filter the login header URL (the logo link).
     *
     * @param string $url The login header URL.
     * @return string
     */
    public function filter_login_header_url( $url ) {
        if ( ! $this->is_enabled() ) {
            return $url;
        }
        return home_url();
    }

    /**
     * Filter the login logo link text (visible / accessible name for the home link).
     *
     * @param string $text Default link text.
     * @return string
     */
    public function filter_login_header_text( $text ) {
        if ( ! $this->is_enabled() ) {
            return $text;
        }
        return get_bloginfo( 'name' );
    }

    /**
     * Enqueue custom login page styles and scripts.
     *
     * @return void
     */
    public function enqueue_login_styles() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $settings = $this->store->get_all();

        // Inline styles for dynamic customization
        $custom_css = $this->generate_custom_css( $settings );

        wp_add_inline_style( 'login', $custom_css );
    }

    /**
     * Generate custom CSS based on login page settings.
     *
     * @param array $settings Login page settings.
     * @return string
     */
    protected function generate_custom_css( array $settings ) {
        $css = '';

        $logo_url    = isset( $settings['logo_url'] ) ? (string) $settings['logo_url'] : '';
        $logo_width  = isset( $settings['logo_width'] ) ? absint( $settings['logo_width'] ) : 84;
        $logo_height = isset( $settings['logo_height'] ) ? absint( $settings['logo_height'] ) : 84;

        if ( '' === $logo_url && function_exists( 'get_theme_mod' ) ) {
            $logo_id = (int) get_theme_mod( 'custom_logo' );
            if ( $logo_id > 0 ) {
                $src = wp_get_attachment_image_url( $logo_id, 'full' );
                if ( $src ) {
                    $logo_url = $src;
                    $meta     = wp_get_attachment_metadata( $logo_id );
                    if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
                        $w = (int) $meta['width'];
                        $h = (int) $meta['height'];
                        if ( $w > 0 && $h > 0 ) {
                            $max = 320;
                            if ( $w > $max || $h > $max ) {
                                $ratio = $w / $h;
                                if ( $w >= $h ) {
                                    $logo_width  = $max;
                                    $logo_height = (int) round( $max / $ratio );
                                } else {
                                    $logo_height = $max;
                                    $logo_width  = (int) round( $max * $ratio );
                                }
                            } else {
                                $logo_width  = $w;
                                $logo_height = $h;
                            }
                        }
                    }
                }
            }
        }

        // Background
        if ( ! empty( $settings['background_color'] ) ) {
            $css .= 'body.login { background-color: ' . sanitize_hex_color( $settings['background_color'] ) . ' !important; }' . "\n";
        }

        if ( ! empty( $settings['background_image'] ) ) {
            $css .= 'body.login { background-image: url(\'' . esc_url( $settings['background_image'] ) . '\') !important; }' . "\n";
            $css .= 'body.login { background-size: ' . sanitize_text_field( $settings['background_size'] ) . ' !important; }' . "\n";
            $css .= 'body.login { background-attachment: fixed !important; }' . "\n";
        }

        // Logo styling (explicit URL or site “Custom logo” when URL is empty).
        if ( '' !== $logo_url ) {
            $css .= '#login h1 a { background-image: url(\'' . esc_url( $logo_url ) . '\') !important; }' . "\n";
            $css .= '#login h1 a { background-size: contain !important; }' . "\n";
            $css .= '#login h1 a { background-repeat: no-repeat !important; }' . "\n";
            $css .= '#login h1 a { background-position: center !important; }' . "\n";
            $css .= '#login h1 a { width: ' . $logo_width . 'px !important; }' . "\n";
            $css .= '#login h1 a { height: ' . $logo_height . 'px !important; }' . "\n";
            $css .= '#login h1 a { text-indent: -9999px !important; }' . "\n";
        }

        // Text and links color
        if ( ! empty( $settings['text_color'] ) ) {
            $css .= 'body.login, .login label { color: ' . sanitize_hex_color( $settings['text_color'] ) . ' !important; }' . "\n";
        }

        if ( ! empty( $settings['link_color'] ) ) {
            $css .= '.login a { color: ' . sanitize_hex_color( $settings['link_color'] ) . ' !important; }' . "\n";
        }

        // Button styling
        if ( ! empty( $settings['button_bg_color'] ) ) {
            $css .= '.login .button-primary { background-color: ' . sanitize_hex_color( $settings['button_bg_color'] ) . ' !important; }' . "\n";
            $css .= '.login .button-primary { border-color: ' . sanitize_hex_color( $settings['button_bg_color'] ) . ' !important; }' . "\n";
        }

        if ( ! empty( $settings['button_text_color'] ) ) {
            $css .= '.login .button-primary { color: ' . sanitize_hex_color( $settings['button_text_color'] ) . ' !important; }' . "\n";
        }

        if ( ! empty( $settings['button_hover_bg_color'] ) ) {
            $css .= '.login .button-primary:hover { background-color: ' . sanitize_hex_color( $settings['button_hover_bg_color'] ) . ' !important; }' . "\n";
            $css .= '.login .button-primary:hover { border-color: ' . sanitize_hex_color( $settings['button_hover_bg_color'] ) . ' !important; }' . "\n";
        }

        // Input fields styling
        if ( ! empty( $settings['input_border_color'] ) ) {
            $css .= '.login input[type="text"], .login input[type="password"], .login input[type="email"] { border-color: ' . sanitize_hex_color( $settings['input_border_color'] ) . ' !important; }' . "\n";
        }

        if ( ! empty( $settings['input_focus_color'] ) ) {
            $css .= '.login input[type="text"]:focus, .login input[type="password"]:focus, .login input[type="email"]:focus { border-color: ' . sanitize_hex_color( $settings['input_focus_color'] ) . ' !important; }' . "\n";
        }

        return $css;
    }

    /**
     * Maybe hide login hints based on settings.
     *
     * @return void
     */
    public function maybe_hide_login_hints() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $settings = $this->store->get_all();

        if ( ! empty( $settings['hide_login_hint'] ) ) {
            wp_add_inline_style(
                'login',
                '.login .login-body p.forgetmenot, .login .user-pass-wrap + p { display: none !important; }'
            );
        }
    }

    /**
     * Check if login customization is enabled.
     *
     * @return bool
     */
    protected function is_enabled() {
        return (bool) $this->store->get( 'enable_customization', false );
    }
}
