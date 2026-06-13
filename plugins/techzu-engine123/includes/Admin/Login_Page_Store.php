<?php
namespace Techzu\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Login_Page_Store {
    const OPTION_KEY = 'techzu_engine_login_page';

    /**
     * Get all login page settings.
     *
     * @return array
     */
    public function get_all() {
        $stored = get_option( self::OPTION_KEY, array() );
        return wp_parse_args(
            is_array( $stored ) ? $stored : array(),
            self::defaults()
        );
    }

    /**
     * Get default login page settings.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'enable_customization'  => false,
            'logo_url'              => '',
            'logo_width'            => 84,
            'logo_height'           => 84,
            'background_color'      => '#f1f1f1',
            'background_image'      => '',
            'background_size'       => 'cover',
            'text_color'            => '#000000',
            'link_color'            => '#0073aa',
            'button_bg_color'       => '#0073aa',
            'button_text_color'     => '#ffffff',
            'button_hover_bg_color' => '#005a87',
            'input_border_color'    => '#ddd',
            'input_focus_color'     => '#0073aa',
            'hide_login_hint'       => false,
            'custom_login_url'      => '',
        );
    }

    /**
     * Get a specific setting value.
     *
     * @param string $key Setting key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get( $key, $default = null ) {
        $all = $this->get_all();
        if ( array_key_exists( $key, $all ) ) {
            return $all[ $key ];
        }
        return $default;
    }

    /**
     * Save login page settings.
     *
     * @param array $values Settings values.
     * @return void
     */
    public function save( array $values ) {
        $clean = wp_parse_args( $values, self::defaults() );

        // Sanitize boolean values
        $clean['enable_customization'] = ! empty( $clean['enable_customization'] );
        $clean['hide_login_hint']      = ! empty( $clean['hide_login_hint'] );

        // Sanitize URLs
        $clean['logo_url']           = esc_url_raw( $clean['logo_url'] );
        $clean['background_image']   = esc_url_raw( $clean['background_image'] );
        $clean['custom_login_url']   = sanitize_text_field( $clean['custom_login_url'] );

        // Sanitize numbers
        $clean['logo_width']  = absint( $clean['logo_width'] );
        $clean['logo_height'] = absint( $clean['logo_height'] );

        // Sanitize color values (hex colors)
        $clean['background_color']      = $this->sanitize_color( $clean['background_color'] );
        $clean['text_color']            = $this->sanitize_color( $clean['text_color'] );
        $clean['link_color']            = $this->sanitize_color( $clean['link_color'] );
        $clean['button_bg_color']       = $this->sanitize_color( $clean['button_bg_color'] );
        $clean['button_text_color']     = $this->sanitize_color( $clean['button_text_color'] );
        $clean['button_hover_bg_color'] = $this->sanitize_color( $clean['button_hover_bg_color'] );
        $clean['input_border_color']    = $this->sanitize_color( $clean['input_border_color'] );
        $clean['input_focus_color']     = $this->sanitize_color( $clean['input_focus_color'] );

        // Sanitize background size
        $allowed_sizes = array( 'auto', 'cover', 'contain' );
        if ( ! in_array( $clean['background_size'], $allowed_sizes, true ) ) {
            $clean['background_size'] = 'cover';
        }

        update_option( self::OPTION_KEY, $clean );
    }

    /**
     * Sanitize color value (hex or rgb).
     *
     * @param mixed $color Color value.
     * @return string
     */
    protected function sanitize_color( $color ) {
        $color = sanitize_text_field( $color );

        // Allow empty strings to revert to defaults
        if ( empty( $color ) ) {
            return '';
        }

        // Check if it's a valid hex color
        if ( preg_match( '/#([a-f0-9]{3}){1,2}\b/i', $color ) ) {
            return $color;
        }

        // Check if it's a valid rgb/rgba color
        if ( preg_match( '/^rgba?\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+/i', $color ) ) {
            return $color;
        }

        return '';
    }

    /**
     * Reset all settings to defaults.
     *
     * @return void
     */
    public function reset() {
        delete_option( self::OPTION_KEY );
    }
}
