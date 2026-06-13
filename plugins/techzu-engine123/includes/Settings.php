<?php
namespace Techzu\Engine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    /**
     * @var array<string, mixed>
     */
    protected $data = array();

    public function __construct() {
        $stored       = get_option( TZ_ENGINE_OPTION_KEY, array() );
        $this->data   = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults() {
        return array(
            'module_dashboard_clean'          => true,
            'module_support_dashboard'      => true,
            'module_wc_commerce_hub'        => true,
            'module_admin_branding'         => true,
            'module_admin_appearance'       => false,
            'module_login_page'             => false,
            'support_recipient_email'       => 'support@techzu.com',
            'whatsapp_contact_url'          => '',
            'admin_brand_color'             => '',
            'admin_widget_heading_color'    => '',
            'custom_admin_slug'             => '',
        );
    }

    /**
     * Sanitize custom admin base slug (segment only, no slashes).
     *
     * @param string $slug Raw slug.
     * @return string Empty if invalid or reserved.
     */
    public static function sanitize_custom_admin_slug( $slug ) {
        $slug = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', (string) $slug ) );
        $slug = trim( $slug, '-' );
        if ( strlen( $slug ) < 2 || strlen( $slug ) > 80 ) {
            return '';
        }

        $reserved = array(
            'wp-admin',
            'wp-login',
            'wp-content',
            'wp-includes',
            'wp-json',
            'feed',
            'rss2',
            'rss',
            'robots',
            'favicon',
            'sitemap',
            'admin',
            'login',
            'dashboard',
            'xmlrpc',
            'wp-cron',
            'cdn-cgi',
            'apps',
            'site-editor',
            'embed',
            'rest',
            'shop',
            'cart',
            'checkout',
            'my-account',
            'wc-api',
        );

        if ( in_array( $slug, $reserved, true ) ) {
            return '';
        }

        return $slug;
    }

    /**
     * @return void
     */
    public static function ensure_defaults() {
        $current = get_option( TZ_ENGINE_OPTION_KEY, null );
        if ( null === $current || ! is_array( $current ) ) {
            update_option( TZ_ENGINE_OPTION_KEY, self::defaults() );
            return;
        }
        update_option( TZ_ENGINE_OPTION_KEY, wp_parse_args( $current, self::defaults() ) );
    }

    /**
     * @param string $key Key.
     * @param mixed  $default Default.
     * @return mixed
     */
    public function get( $key, $default = null ) {
        if ( array_key_exists( $key, $this->data ) ) {
            return $this->data[ $key ];
        }
        $defaults = self::defaults();
        return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : $default;
    }

    /**
     * @param string $key Key.
     * @return bool
     */
    public function is_module_enabled( $key ) {
        return (bool) $this->get( $key, false );
    }

    /**
     * @return array<string, mixed>
     */
    public function all() {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $values Values.
     * @return void
     */
    public function save( array $values ) {
        $clean               = wp_parse_args( $values, self::defaults() );
        $clean['module_dashboard_clean']       = ! empty( $clean['module_dashboard_clean'] );
        $clean['module_support_dashboard']      = ! empty( $clean['module_support_dashboard'] );
        $clean['module_wc_commerce_hub']       = ! empty( $clean['module_wc_commerce_hub'] );
        $clean['module_admin_branding']        = ! empty( $clean['module_admin_branding'] );
        $clean['module_admin_appearance']      = ! empty( $clean['module_admin_appearance'] );
        $clean['module_login_page']            = ! empty( $clean['module_login_page'] );

        $bc = isset( $clean['admin_brand_color'] ) ? sanitize_hex_color( (string) $clean['admin_brand_color'] ) : '';
        $clean['admin_brand_color']            = $bc ? $bc : '';
        $wh = isset( $clean['admin_widget_heading_color'] ) ? sanitize_hex_color( (string) $clean['admin_widget_heading_color'] ) : '';
        $clean['admin_widget_heading_color']   = $wh ? $wh : '';
        $clean['custom_admin_slug']            = self::sanitize_custom_admin_slug( isset( $clean['custom_admin_slug'] ) ? (string) $clean['custom_admin_slug'] : '' );

        $clean['support_recipient_email']      = sanitize_email( $clean['support_recipient_email'] );
        if ( ! is_email( $clean['support_recipient_email'] ) ) {
            $clean['support_recipient_email'] = self::defaults()['support_recipient_email'];
        }
        $clean['whatsapp_contact_url']         = esc_url_raw( $clean['whatsapp_contact_url'] );
        update_option( TZ_ENGINE_OPTION_KEY, $clean );
        $this->data = $clean;
    }
}
