<?php
namespace Techzu\Engine\Modules;

use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin brand colors, custom login slug (pattern similar to Admin Login URL Change: template_redirect + early blocks + flush).
 */
class Admin_Branding_Module implements Module_Interface {
    const QUERY_LOGIN = 'tz_engine_custom_login';

    const RUNTIME_SLUG_OPTION = 'tz_engine_branding_login_slug_runtime';

    /**
     * @var Settings
     */
    protected $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function setting_key() {
        return 'module_admin_branding';
    }

    public function register() {
        add_action( 'init', array( $this, 'block_default_login_and_admin_for_guests' ), 0 );
        add_action( 'init', array( $this, 'register_rewrite' ), 1 );

        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'load_custom_login_screen' ), 1 );

        add_action( 'plugins_loaded', array( $this, 'maybe_auto_flush_rewrites' ), 20 );
        add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );

        add_filter( 'site_url', array( $this, 'filter_site_url_for_custom_login' ), 10, 4 );
        add_filter( 'network_site_url', array( $this, 'filter_network_site_url_for_custom_login' ), 10, 3 );

        add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
        add_filter( 'logout_url', array( $this, 'filter_logout_url' ), 10, 2 );
        add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );
        add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );
        add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect_strip_wp_login' ), 2, 2 );

        add_filter( 'admin_body_class', array( $this, 'filter_admin_body_class' ) );
        add_action( 'admin_head', array( $this, 'print_admin_brand_css' ), 5 );
    }

    /**
     * @return string
     */
    protected function sanitized_slug() {
        return Settings::sanitize_custom_admin_slug( (string) $this->settings->get( 'custom_admin_slug', '' ) );
    }

    /**
     * Request path with leading/trailing slashes trimmed (full path from URI).
     *
     * @return string
     */
    protected function raw_request_path() {
        $req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path = wp_parse_url( $req, PHP_URL_PATH );
        return is_string( $path ) ? trim( $path, '/' ) : '';
    }

    /**
     * Path relative to home URL path (for subdirectory installs), lowercase, trimmed.
     *
     * @return string
     */
    protected function request_path_relative_to_home() {
        $path      = $this->raw_request_path();
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH );
        $home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';

        if ( '' !== $home_path && 0 === strpos( $path . '/', $home_path . '/' ) ) {
            $path = trim( substr( $path, strlen( $home_path ) ), '/' );
        }

        return strtolower( $path );
    }

    /**
     * Guests may not use wp-admin or wp-login.php; respond with 404 (no redirect to a fake page).
     *
     * @return void
     */
    public function block_default_login_and_admin_for_guests() {
        if ( '' === $this->sanitized_slug() ) {
            return;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }

        $path = $this->raw_request_path();

        if ( is_user_logged_in() ) {
            return;
        }

        if ( preg_match( '#(^|/)wp-login\.php$#i', $path ) ) {
            remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
            $this->send_not_found();
        }

        if ( preg_match( '#(^|/)wp-admin(/|$)#i', $path ) ) {
            if ( preg_match( '#admin-ajax\.php$#i', $path ) ) {
                return;
            }
            remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
            $this->send_not_found();
        }
    }

    /**
     * @return void
     */
    protected function send_not_found() {
        status_header( 404 );
        nocache_headers();
        wp_die( esc_html__( 'Not found.', 'techzu-engine' ), esc_html__( 'Not found', 'techzu-engine' ), array( 'response' => 404 ) );
    }

    /**
     * @return void
     */
    public function register_rewrite() {
        $slug = $this->sanitized_slug();
        if ( '' === $slug ) {
            return;
        }

        add_rewrite_rule(
            '^' . preg_quote( $slug, '/' ) . '/?$',
            'index.php?' . self::QUERY_LOGIN . '=1',
            'top'
        );
    }

    /**
     * @param list<string> $vars Vars.
     * @return list<string>
     */
    public function register_query_vars( $vars ) {
        $vars[] = self::QUERY_LOGIN;
        return $vars;
    }

    /**
     * Flush when Engine saves slug; also see maybe_auto_flush_rewrites().
     *
     * @return void
     */
    public function maybe_flush_rewrite_rules() {
        if ( '1' !== get_option( 'tz_engine_branding_flush_rewrite', '' ) ) {
            return;
        }
        flush_rewrite_rules( false );
        delete_option( 'tz_engine_branding_flush_rewrite' );
    }

    /**
     * If the slug changed since last load, flush rules so /slug/ resolves without a manual Permalinks save.
     *
     * @return void
     */
    public function maybe_auto_flush_rewrites() {
        $slug    = $this->sanitized_slug();
        $stored  = (string) get_option( self::RUNTIME_SLUG_OPTION, '' );
        if ( $slug === $stored ) {
            return;
        }
        update_option( self::RUNTIME_SLUG_OPTION, $slug, false );
        flush_rewrite_rules( false );
    }

    /**
     * Load wp-login.php for the custom slug (same approach as Admin Login URL Change).
     *
     * @return void
     */
    public function load_custom_login_screen() {
        if ( is_admin() ) {
            return;
        }

        $slug = $this->sanitized_slug();
        if ( '' === $slug ) {
            return;
        }

        $from_query = (string) get_query_var( self::QUERY_LOGIN ) === '1';
        $from_uri   = ( $this->request_path_relative_to_home() === strtolower( $slug ) );

        if ( ! $from_query && ! $from_uri ) {
            return;
        }

        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
        }

        global $pagenow, $user_login, $error;
        $pagenow = 'wp-login.php';

        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    /**
     * @param string      $url     URL.
     * @param string      $path    Path.
     * @param string|null $scheme  Scheme.
     * @param int|null    $blog_id Blog ID.
     * @return string
     */
    public function filter_site_url_for_custom_login( $url, $path, $scheme, $blog_id ) {
        $replacement = $this->rewrite_wp_login_path_to_custom( $path, $scheme, $blog_id );
        return null !== $replacement ? $replacement : $url;
    }

    /**
     * @param string      $url    URL.
     * @param string      $path   Path.
     * @param string|null $scheme Scheme.
     * @return string
     */
    public function filter_network_site_url_for_custom_login( $url, $path, $scheme ) {
        $main_id = function_exists( 'get_main_site_id' ) ? get_main_site_id() : 1;
        $replacement = $this->rewrite_wp_login_path_to_custom( $path, $scheme, $main_id );
        return null !== $replacement ? $replacement : $url;
    }

    /**
     * @param string $login_url Login URL.
     * @param string $redirect  Redirect.
     * @param bool   $reauth    Reauth.
     * @return string
     */
    public function filter_login_url( $login_url, $redirect, $reauth ) {
        if ( '' === $this->sanitized_slug() ) {
            return $login_url;
        }

        $url = trailingslashit( home_url( '/' . $this->sanitized_slug() . '/', 'login' ) );
        if ( $redirect ) {
            $url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
        }
        if ( $reauth ) {
            $url = add_query_arg( 'reauth', '1', $url );
        }
        return $url;
    }

    /**
     * @param string $logout_url Logout URL.
     * @param string $redirect   Redirect.
     * @return string
     */
    public function filter_logout_url( $logout_url, $redirect ) {
        if ( '' === $this->sanitized_slug() ) {
            return $logout_url;
        }

        $url = trailingslashit( home_url( '/' . $this->sanitized_slug() . '/', 'login' ) );
        $url = add_query_arg( 'action', 'logout', $url );
        if ( $redirect ) {
            $url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
        }
        return wp_nonce_url( $url, 'log-out' );
    }

    /**
     * @param string $lostpassword_url URL.
     * @param string $redirect         Redirect.
     * @return string
     */
    public function filter_lostpassword_url( $lostpassword_url, $redirect ) {
        if ( '' === $this->sanitized_slug() ) {
            return $lostpassword_url;
        }

        $url = trailingslashit( home_url( '/' . $this->sanitized_slug() . '/', 'login' ) );
        $url = add_query_arg( 'action', 'lostpassword', $url );
        if ( $redirect ) {
            $url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
        }
        return $url;
    }

    /**
     * @param string           $redirect_to Redirect.
     * @param string           $requested   Requested.
     * @param \WP_User|\WP_Error $user      User.
     * @return string
     */
    public function filter_login_redirect( $redirect_to, $requested, $user ) {
        if ( '' === $this->sanitized_slug() ) {
            return $redirect_to;
        }
        if ( is_wp_error( $user ) || ! $user instanceof \WP_User || ! $user->ID ) {
            return $redirect_to;
        }
        if ( '' === $redirect_to || false !== stripos( $redirect_to, 'wp-login.php' ) ) {
            return admin_url();
        }
        return $redirect_to;
    }

    /**
     * @param string $location Location.
     * @param int    $status   Status.
     * @return string
     */
    public function filter_wp_redirect_strip_wp_login( $location, $status ) {
        if ( '' === $this->sanitized_slug() ) {
            return $location;
        }
        if ( false === stripos( $location, 'wp-login.php' ) ) {
            return $location;
        }
        $parts = wp_parse_url( $location );
        if ( ! is_array( $parts ) || empty( $parts['path'] ) || false === stripos( $parts['path'], 'wp-login.php' ) ) {
            return $location;
        }
        $base = trailingslashit( home_url( '/' . $this->sanitized_slug() . '/', 'login' ) );
        if ( ! empty( $parts['query'] ) ) {
            return $base . '?' . $parts['query'];
        }
        return $base;
    }

    /**
     * @param string      $path    Relative path passed to site_url.
     * @param string|null $scheme  URL scheme.
     * @param int|null    $blog_id Blog ID.
     * @return string|null New URL or null to keep default.
     */
    protected function rewrite_wp_login_path_to_custom( $path, $scheme, $blog_id ) {
        $slug = $this->sanitized_slug();
        if ( '' === $slug ) {
            return null;
        }
        if ( ! is_string( $path ) || ! preg_match( '#^wp-login\.php(\?.*)?$#', $path ) ) {
            return null;
        }

        $blog_id = (int) $blog_id;
        if ( $blog_id <= 0 ) {
            $blog_id = get_current_blog_id();
        }

        $base = trailingslashit( get_home_url( $blog_id, '/' . $slug . '/', $scheme ) );

        if ( strlen( $path ) > 12 && '?' === $path[12] ) {
            return $base . substr( $path, 12 );
        }

        return $base;
    }

    /**
     * @param string $classes Space-separated classes.
     * @return string
     */
    public function filter_admin_body_class( $classes ) {
        $brand = sanitize_hex_color( (string) $this->settings->get( 'admin_brand_color', '' ) );
        $head  = sanitize_hex_color( (string) $this->settings->get( 'admin_widget_heading_color', '' ) );
        if ( $brand || $head ) {
            $classes .= ' tz-engine-brand-active';
        }
        return $classes;
    }

    /**
     * @return void
     */
    public function print_admin_brand_css() {
        $brand = sanitize_hex_color( (string) $this->settings->get( 'admin_brand_color', '' ) );
        $head  = sanitize_hex_color( (string) $this->settings->get( 'admin_widget_heading_color', '' ) );
        if ( ! $brand && ! $head ) {
            return;
        }

        $widget_color = $head ? $head : $brand;
        $d10          = $brand ? self::darken_hex( $brand, 10 ) : '';
        $d20          = $brand ? self::darken_hex( $brand, 20 ) : '';

        echo "\n<style id=\"tz-engine-admin-brand\">\n";

        if ( $brand ) {
            echo 'body.wp-admin.tz-engine-brand-active {';
            echo '--wp-admin-theme-color:' . esc_attr( $brand ) . ';';
            if ( $d10 ) {
                echo '--wp-admin-theme-color-darker-10:' . esc_attr( $d10 ) . ';';
            }
            if ( $d20 ) {
                echo '--wp-admin-theme-color-darker-20:' . esc_attr( $d20 ) . ';';
            }
            echo "}\n";

            echo '#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,' . "\n";
            echo '#adminmenu li.current a.menu-top,' . "\n";
            echo '.folded #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu {' . "\n";
            echo 'background: var(--wp-admin-theme-color) !important;' . "\n";
            echo 'color: #fff !important;' . "\n";
            echo "}\n";

            echo 'body.wp-admin.tz-engine-brand-active .tz-engine-ui,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active #tz_engine_site_hub,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active #tz_engine_support_hub,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active #tz_engine_wc_commerce_hub {' . "\n";
            echo '--tz-primary: ' . esc_attr( $brand ) . ';' . "\n";
            echo '--tz-primary-hover: var(--wp-admin-theme-color-darker-10, ' . esc_attr( $d10 ? $d10 : $brand ) . ');' . "\n";
            echo "}\n";

            echo 'body.wp-admin.tz-engine-brand-active .tz-engine-ui .tz-engine-btn--ghost {' . "\n";
            echo 'color: var(--tz-primary, ' . esc_attr( $brand ) . ') !important;' . "\n";
            echo 'border-color: var(--tz-primary, ' . esc_attr( $brand ) . ');' . "\n";
            echo "}\n";

            echo 'body.wp-admin.tz-engine-brand-active .tz-engine-ui .tz-engine-btn--ghost:hover {' . "\n";
            echo 'background: var(--tz-primary, ' . esc_attr( $brand ) . ');' . "\n";
            echo 'border-color: var(--tz-primary, ' . esc_attr( $brand ) . ');' . "\n";
            echo 'color: #fff !important;' . "\n";
            echo "}\n";
        }

        if ( $widget_color ) {
            echo 'body.wp-admin.tz-engine-brand-active .edit-post-fullscreen-mode-close svg,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active #dashboard-widgets .postbox-header h2,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active #dashboard-widgets .postbox-header .hndle,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active .postbox .postbox-header h2,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active .widgets-holder-wrap .sidebar-name h2,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active .wrap > h1.wp-heading-inline,' . "\n";
            echo 'body.wp-admin.tz-engine-brand-active .wrap h1.wp-heading-inline {' . "\n";
            echo 'color: ' . esc_attr( $widget_color ) . ' !important;' . "\n";
            echo "}\n";
        }

        echo "</style>\n";
    }

    /**
     * @param string $hex #RRGGBB.
     * @param int    $pct 0–100 darken percent (multiplicative).
     * @return string
     */
    protected static function darken_hex( $hex, $pct ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '#' . $hex;
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $f = max( 0.0, min( 1.0, 1 - ( $pct / 100 ) ) );
        $r = (int) round( $r * $f );
        $g = (int) round( $g * $f );
        $b = (int) round( $b * $f );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * @return void
     */
    public static function schedule_rewrite_flush() {
        update_option( 'tz_engine_branding_flush_rewrite', '1', false );
    }
}
