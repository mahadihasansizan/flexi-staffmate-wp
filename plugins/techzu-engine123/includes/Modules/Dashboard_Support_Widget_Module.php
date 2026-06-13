<?php
namespace Techzu\Engine\Modules;

use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dashboard_Support_Widget_Module implements Module_Interface {
    const ACTION = 'tz_engine_support_submit';

    /**
     * @var Settings
     */
    protected $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function setting_key() {
        return 'module_support_dashboard';
    }

    public function register() {
        add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
        add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_support_submission' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_widget_styles' ) );
    }

    /**
     * @return void
     */
    public function enqueue_widget_styles( $hook_suffix ) {
        if ( 'index.php' !== $hook_suffix ) {
            return;
        }
        wp_enqueue_style(
            'tz-engine-support-widget',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-support-widget.css',
            array( 'tz-engine-admin-ui' ),
            TZ_ENGINE_VERSION
        );
    }

    /**
     * @return void
     */
    public function register_widget() {
        if ( ! current_user_can( 'edit_dashboard' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'tz_engine_site_hub',
            __( 'Site', 'techzu-engine' ),
            array( $this, 'render_site_widget' ),
            null,
            null,
            'normal',
            'high'
        );

        wp_add_dashboard_widget(
            'tz_engine_support_hub',
            __( 'Need help', 'techzu-engine' ),
            array( $this, 'render_support_widget' ),
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * @return void
     */
    public function render_site_widget() {
        if ( ! current_user_can( 'edit_dashboard' ) ) {
            return;
        }

        $user = wp_get_current_user();
        $site = get_bloginfo( 'name' );

        /**
         * Label for the E-commerce row (empty string hides it). Default detects WooCommerce / EDD.
         *
         * @param string $label Default label from {@see detect_ecommerce_plugin_label()}.
         */
        $ecommerce = apply_filters(
            'tz_engine_dashboard_ecommerce_status',
            $this->detect_ecommerce_plugin_label()
        );
        if ( ! is_string( $ecommerce ) ) {
            $ecommerce = '';
        }
        ?>
        <div class="tz-engine-ui tz-engine-support-root">
            <div class="tz-engine-support-hub tz-engine-support-hub--site-only">
                <section class="tz-engine-support-hub__panel tz-engine-support-hub__panel--site tz-engine-glass">
                    <div class="tz-engine-support-hub__meta">
                        <div class="tz-engine-meta-row">
                            <span class="tz-engine-meta-label"><?php esc_html_e( 'Site', 'techzu-engine' ); ?></span>
                            <span class="tz-engine-meta-value"><?php echo esc_html( $site ? $site : __( '(untitled)', 'techzu-engine' ) ); ?></span>
                        </div>
                        <div class="tz-engine-meta-row">
                            <span class="tz-engine-meta-label"><?php esc_html_e( 'User', 'techzu-engine' ); ?></span>
                            <span class="tz-engine-meta-value"><?php echo esc_html( $user->display_name ); ?></span>
                        </div>
                        <div class="tz-engine-meta-row">
                            <span class="tz-engine-meta-label"><?php esc_html_e( 'System status', 'techzu-engine' ); ?></span>
                            <span class="tz-engine-pill"><span class="tz-engine-pill__dot" aria-hidden="true"></span><?php esc_html_e( 'Active', 'techzu-engine' ); ?></span>
                        </div>
                        <?php if ( is_string( $ecommerce ) && '' !== $ecommerce ) : ?>
                            <div class="tz-engine-meta-row">
                                <span class="tz-engine-meta-label"><?php esc_html_e( 'E-commerce', 'techzu-engine' ); ?></span>
                                <span class="tz-engine-meta-value tz-engine-meta-value--status">
                                    <span class="tz-engine-pill"><span class="tz-engine-pill__dot" aria-hidden="true"></span><?php esc_html_e( 'Active', 'techzu-engine' ); ?></span>
                                    <span class="tz-engine-meta-sublabel"><?php echo esc_html( $ecommerce ); ?></span>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * Human-readable store plugin name when an e-commerce plugin is active, or empty string.
     *
     * @return string
     */
    protected function detect_ecommerce_plugin_label() {
        if ( class_exists( 'WooCommerce' ) ) {
            return __( 'WooCommerce', 'techzu-engine' );
        }
        if ( class_exists( 'Easy_Digital_Downloads' ) ) {
            return __( 'Easy Digital Downloads', 'techzu-engine' );
        }
        return '';
    }

    /**
     * @return void
     */
    public function render_support_widget() {
        if ( ! current_user_can( 'edit_dashboard' ) ) {
            return;
        }

        $user         = wp_get_current_user();
        $wa           = $this->settings->get( 'whatsapp_contact_url', '' );
        $settings_url = admin_url( 'admin.php?page=techzu-engine-settings' );
        ?>
        <div class="tz-engine-ui tz-engine-support-root">
        <?php
        if ( isset( $_GET['tz_engine_support'] ) ) {
            $flag = sanitize_key( wp_unslash( $_GET['tz_engine_support'] ) );
            if ( 'sent' === $flag ) {
                echo '<div class="tz-engine-alert tz-engine-alert--success tz-engine-persist-notice" role="status">';
                esc_html_e( 'Your message was sent to Techzu support.', 'techzu-engine' );
                echo '</div>';
            } elseif ( 'invalid' === $flag ) {
                echo '<div class="tz-engine-alert tz-engine-alert--error tz-engine-persist-notice" role="alert">';
                esc_html_e( 'Please fill in all fields with a valid email address.', 'techzu-engine' );
                echo '</div>';
            } elseif ( 'rate_limited' === $flag ) {
                echo '<div class="tz-engine-alert tz-engine-alert--error tz-engine-persist-notice" role="alert">';
                esc_html_e( 'Too many messages sent recently. Please wait a few minutes and try again.', 'techzu-engine' );
                echo '</div>';
            }
        }
        ?>
            <div class="tz-engine-support-hub">
                <section class="tz-engine-support-hub__panel tz-engine-support-hub__panel--support tz-engine-glass">
                    <form class="tz-engine-support-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( self::ACTION, 'tz_engine_support_nonce' ); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
                        <div class="tz-engine-support-form__fields">
                            <div class="tz-engine-field">
                                <label class="tz-engine-label" for="tz_engine_support_name"><?php esc_html_e( 'Name', 'techzu-engine' ); ?></label>
                                <input type="text" class="tz-engine-input" id="tz_engine_support_name" name="tz_engine_support_name" value="<?php echo esc_attr( $user->display_name ); ?>" required autocomplete="name" />
                            </div>
                            <div class="tz-engine-field">
                                <label class="tz-engine-label" for="tz_engine_support_email"><?php esc_html_e( 'Email', 'techzu-engine' ); ?></label>
                                <input type="email" class="tz-engine-input" id="tz_engine_support_email" name="tz_engine_support_email" value="<?php echo esc_attr( $user->user_email ); ?>" required autocomplete="email" />
                            </div>
                            <div class="tz-engine-field">
                                <label class="tz-engine-label" for="tz_engine_support_requirement"><?php esc_html_e( 'Requirement', 'techzu-engine' ); ?></label>
                                <textarea class="tz-engine-textarea" rows="4" id="tz_engine_support_requirement" name="tz_engine_support_requirement" required placeholder="<?php echo esc_attr__( 'How can we help you today?', 'techzu-engine' ); ?>"></textarea>
                            </div>
                        </div>
                        <button type="submit" class="tz-engine-btn tz-engine-btn--primary tz-engine-btn--block"><?php esc_html_e( 'Send to Techzu', 'techzu-engine' ); ?></button>
                    </form>
                </section>
                <div class="tz-engine-support-hub__footer">
                    <?php if ( $wa ) : ?>
                        <a class="tz-engine-btn tz-engine-btn--wa tz-engine-btn--block" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'WhatsApp Techzu', 'techzu-engine' ); ?>
                        </a>
                    <?php else : ?>
                        <p class="tz-engine-support-hub__hint">
                            <?php esc_html_e( 'Add a WhatsApp link under', 'techzu-engine' ); ?>
                            <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Engine settings', 'techzu-engine' ); ?></a>.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @return void
     */
    public function handle_support_submission() {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            wp_die( esc_html__( 'Invalid request.', 'techzu-engine' ), '', array( 'response' => 405 ) );
        }

        if ( ! isset( $_POST['tz_engine_support_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tz_engine_support_nonce'] ) ), self::ACTION ) ) {
            wp_die( esc_html__( 'Security check failed.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'edit_dashboard' ) ) {
            wp_die( esc_html__( 'You do not have permission to send this form.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        $name    = isset( $_POST['tz_engine_support_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tz_engine_support_name'] ) ) : '';
        $email   = isset( $_POST['tz_engine_support_email'] ) ? sanitize_email( wp_unslash( $_POST['tz_engine_support_email'] ) ) : '';
        $message = isset( $_POST['tz_engine_support_requirement'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tz_engine_support_requirement'] ) ) : '';

        $max_len = (int) apply_filters( 'tz_engine_support_message_max_chars', 20000 );
        if ( $max_len > 0 && strlen( $message ) > $max_len ) {
            $message = substr( $message, 0, $max_len );
        }

        if ( '' === $name || ! is_email( $email ) || '' === $message ) {
            wp_safe_redirect( add_query_arg( 'tz_engine_support', 'invalid', admin_url( 'index.php' ) ) );
            exit;
        }

        $uid = get_current_user_id();
        if ( $uid ) {
            $rate_key = 'tz_engine_support_rate_' . $uid;
            $sent     = (int) get_transient( $rate_key );
            $max      = (int) apply_filters( 'tz_engine_support_max_submissions_per_window', 8 );
            if ( $sent >= $max ) {
                wp_safe_redirect( add_query_arg( 'tz_engine_support', 'rate_limited', admin_url( 'index.php' ) ) );
                exit;
            }
        }

        $default_to = 'support@techzu.com';
        $to         = sanitize_email( (string) $this->settings->get( 'support_recipient_email', $default_to ) );
        if ( ! is_email( $to ) ) {
            $to = $default_to;
        }
        $subject = sprintf(
            /* translators: %s: site name */
            __( '[Techzu] Support request from %s', 'techzu-engine' ),
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
        );

        $body = sprintf(
            "Site: %s\nURL: %s\n\nFrom: %s <%s>\n\nRequirement:\n%s\n",
            get_bloginfo( 'name' ),
            home_url( '/' ),
            $name,
            $email,
            $message
        );

        $headers = array( 'Reply-To: ' . $email );

        /**
         * Filter outgoing Techzu support mail.
         *
         * @param array $args Keys: to, subject, body, headers.
         */
        $mail_args = apply_filters(
            'tz_engine_support_mail_args',
            array(
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'headers' => $headers,
            )
        );

        $mail_to = isset( $mail_args['to'] ) ? sanitize_email( (string) $mail_args['to'] ) : $to;
        if ( ! is_email( $mail_to ) ) {
            $mail_to = $to;
        }
        $mail_subject = isset( $mail_args['subject'] ) ? sanitize_text_field( (string) $mail_args['subject'] ) : $subject;
        $mail_body    = isset( $mail_args['body'] ) && is_string( $mail_args['body'] ) ? $mail_args['body'] : $body;
        if ( strlen( $mail_body ) > 100000 ) {
            $mail_body = substr( $mail_body, 0, 100000 );
        }
        $mail_headers = isset( $mail_args['headers'] ) && is_array( $mail_args['headers'] ) ? $mail_args['headers'] : $headers;
        $mail_headers = array_filter( array_map( 'strval', $mail_headers ) );

        wp_mail( $mail_to, $mail_subject, $mail_body, $mail_headers );

        if ( $uid ) {
            $rate_key  = 'tz_engine_support_rate_' . $uid;
            $sent      = (int) get_transient( $rate_key );
            $window    = (int) apply_filters( 'tz_engine_support_rate_window_seconds', 15 * MINUTE_IN_SECONDS );
            set_transient( $rate_key, $sent + 1, $window > 0 ? $window : 15 * MINUTE_IN_SECONDS );
        }

        wp_safe_redirect( add_query_arg( 'tz_engine_support', 'sent', admin_url( 'index.php' ) ) );
        exit;
    }
}
