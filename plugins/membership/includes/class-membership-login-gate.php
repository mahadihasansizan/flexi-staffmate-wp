<?php
/**
 * Frontend login gate.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Protects frontend requests and renders login form.
 */
final class LoginGate {
    /** @var LoginGate|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings service.
     */
    private function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Get singleton.
     *
     * @param Settings|null $settings Settings service.
     * @return LoginGate
     */
    public static function instance( ?Settings $settings = null ): LoginGate {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings ?: Settings::instance() );
        }
        return self::$instance;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks(): void {
        add_action( 'template_redirect', array( $this, 'maybe_redirect_guest' ), 1 );
        add_shortcode( 'membership_login_form', array( $this, 'login_form_shortcode' ) );
        add_shortcode( 'tmgmp_login_form', array( $this, 'login_form_shortcode' ) );
        add_action( 'wp_login_failed', array( $this, 'redirect_failed_login' ) );
        add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
    }

    /**
     * Redirect guests to login page.
     *
     * @return void
     */
    public function maybe_redirect_guest(): void {
        $settings = $this->settings->get_settings();

        if ( empty( $settings['login_gate_enabled'] ) || is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && empty( $settings['protect_rest'] ) ) {
            return;
        }

        if ( $this->is_public_request( $settings ) ) {
            return;
        }

        $login_url = $this->get_login_url();
        if ( ! $login_url ) {
            return;
        }

        $login_url = add_query_arg( 'redirect_to', self::current_url(), $login_url );
        wp_safe_redirect( $login_url );
        exit;
    }

    /**
     * Render login form shortcode.
     *
     * @param array<string,mixed> $atts Attributes.
     * @return string
     */
    public function login_form_shortcode( array $atts = array() ): string {
        wp_enqueue_style( 'membership-frontend' );
        $atts = shortcode_atts( array( 'title' => __( 'Member Login', 'membership' ) ), $atts, 'membership_login_form' );

        ob_start();
        ?>
        <div class="membership-login-wrap">
            <div class="membership-login-card">
                <div class="membership-login-brand">
                    <span aria-hidden="true">M</span>
                    <div>
                        <h1><?php echo esc_html( (string) $atts['title'] ); ?></h1>
                        <p><?php esc_html_e( 'Log in to access the private website.', 'membership' ); ?></p>
                    </div>
                </div>
                <?php if ( is_user_logged_in() ) : ?>
                    <?php $user = wp_get_current_user(); ?>
                    <div class="membership-login-message membership-login-message-success">
                        <?php
                        printf(
                            /* translators: %s: display name */
                            esc_html__( 'You are logged in as %s.', 'membership' ),
                            esc_html( $user->display_name )
                        );
                        ?>
                    </div>
                    <p class="membership-login-actions">
                        <a class="membership-login-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Enter website', 'membership' ); ?></a>
                        <a class="membership-login-button membership-login-button-secondary" href="<?php echo esc_url( wp_logout_url( $this->get_login_url() ) ); ?>"><?php esc_html_e( 'Log out', 'membership' ); ?></a>
                    </p>
                <?php else : ?>
                    <?php if ( isset( $_GET['login'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['login'] ) ) ) : ?>
                        <div class="membership-login-message membership-login-message-error"><?php esc_html_e( 'The username/email or password is incorrect.', 'membership' ); ?></div>
                    <?php endif; ?>
                    <?php if ( isset( $_GET['loggedout'] ) && 'true' === sanitize_key( wp_unslash( $_GET['loggedout'] ) ) ) : ?>
                        <div class="membership-login-message membership-login-message-success"><?php esc_html_e( 'You have been logged out.', 'membership' ); ?></div>
                    <?php endif; ?>
                    <?php
                    echo wp_login_form(
                        array(
                            'echo'           => false,
                            'redirect'       => $this->requested_redirect_url(),
                            'form_id'        => 'membership-loginform',
                            'label_username' => __( 'Username or email', 'membership' ),
                            'label_password' => __( 'Password', 'membership' ),
                            'label_remember' => __( 'Remember me', 'membership' ),
                            'label_log_in'   => __( 'Log in', 'membership' ),
                            'remember'       => true,
                        )
                    );
                    ?>
                    <p class="membership-login-links"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Lost your password?', 'membership' ); ?></a></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Failed login redirect.
     *
     * @param string $username Username.
     * @return void
     */
    public function redirect_failed_login( string $username ): void {
        unset( $username );
        $referrer = wp_get_referer();
        if ( ! $referrer ) {
            return;
        }

        $login_path = untrailingslashit( (string) wp_parse_url( $this->get_login_url(), PHP_URL_PATH ) );
        $ref_path   = untrailingslashit( (string) wp_parse_url( $referrer, PHP_URL_PATH ) );
        if ( $login_path !== $ref_path ) {
            return;
        }

        wp_safe_redirect( add_query_arg( 'login', 'failed', remove_query_arg( 'loggedout', $referrer ) ) );
        exit;
    }

    /**
     * Login redirect fallback.
     *
     * @param string           $redirect_to Redirect URL.
     * @param string           $requested_redirect_to Requested redirect URL.
     * @param \WP_User|\WP_Error $user User or error.
     * @return string
     */
    public function login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
        if ( $user instanceof \WP_Error ) {
            return $redirect_to;
        }

        if ( $requested_redirect_to ) {
            return wp_validate_redirect( $requested_redirect_to, home_url( '/' ) );
        }

        return $this->default_after_login_url();
    }

    /**
     * Public request check.
     *
     * @param array<string,mixed> $settings Settings.
     * @return bool
     */
    private function is_public_request( array $settings ): bool {
        $login_page_id = absint( $settings['login_page_id'] ?? 0 );
        if ( $login_page_id && is_page( $login_page_id ) ) {
            return true;
        }

        if ( ! empty( $settings['allow_homepage'] ) && ( is_front_page() || is_home() ) ) {
            return true;
        }

        if ( function_exists( 'is_robots' ) && is_robots() ) {
            return true;
        }

        $path = $this->current_path();
        if ( false !== strpos( $path, 'wp-login.php' ) || false !== strpos( $path, 'wp-register.php' ) ) {
            return true;
        }

        $paths = preg_split( '/\r\n|\r|\n/', (string) ( $settings['public_paths'] ?? '' ) );
        foreach ( (array) $paths as $line ) {
            $rule = trim( (string) $line );
            if ( '' === $rule ) {
                continue;
            }
            $rule_path = wp_parse_url( $rule, PHP_URL_PATH );
            $rule_path = $rule_path ? $rule_path : $rule;
            $wildcard  = '*' === substr( $rule_path, -1 );
            $rule_path = untrailingslashit( '/' . ltrim( rtrim( $rule_path, '*' ), '/' ) );

            if ( $wildcard && 0 === strpos( $path, $rule_path ) ) {
                return true;
            }
            if ( $path === $rule_path || 0 === strpos( $path, trailingslashit( $rule_path ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get configured login URL.
     *
     * @return string
     */
    public function get_login_url(): string {
        $settings = $this->settings->get_settings();
        $page_id  = absint( $settings['login_page_id'] ?? 0 );
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return $url;
            }
        }
        return wp_login_url();
    }

    /**
     * Requested redirect URL from query.
     *
     * @return string
     */
    private function requested_redirect_url(): string {
        if ( isset( $_GET['redirect_to'] ) ) {
            $requested = rawurldecode( wp_unslash( (string) $_GET['redirect_to'] ) );
            return wp_validate_redirect( esc_url_raw( $requested ), $this->default_after_login_url() );
        }
        return $this->default_after_login_url();
    }

    /**
     * Default after-login URL.
     *
     * @return string
     */
    private function default_after_login_url(): string {
        $settings = $this->settings->get_settings();
        $mode     = sanitize_key( (string) ( $settings['after_login'] ?? 'requested' ) );

        if ( 'custom' === $mode && ! empty( $settings['custom_redirect'] ) ) {
            return wp_validate_redirect( esc_url_raw( (string) $settings['custom_redirect'] ), home_url( '/' ) );
        }

        if ( 'shop' === $mode && function_exists( 'wc_get_page_permalink' ) ) {
            $shop = wc_get_page_permalink( 'shop' );
            if ( $shop ) {
                return $shop;
            }
        }

        if ( 'account' === $mode && function_exists( 'wc_get_page_permalink' ) ) {
            $account = wc_get_page_permalink( 'myaccount' );
            if ( $account ) {
                return $account;
            }
        }

        return home_url( '/' );
    }

    /**
     * Current absolute URL.
     *
     * @return string
     */
    private static function current_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
        return esc_url_raw( $scheme . $host . $uri );
    }

    /**
     * Current request path.
     *
     * @return string
     */
    private function current_path(): string {
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        $path = $path ? $path : '/';
        return untrailingslashit( '/' . ltrim( $path, '/' ) );
    }
}
