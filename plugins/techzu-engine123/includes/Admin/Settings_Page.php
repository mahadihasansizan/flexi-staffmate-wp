<?php
namespace Techzu\Engine\Admin;

use Techzu\Engine\Modules\Admin_Branding_Module;
use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings_Page {
    const MENU_SLUG = 'techzu-engine-settings';

    /**
     * @var Settings
     */
    protected $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * @return void
     */
    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
    }

    /**
     * @param string $classes Body classes.
     * @return string
     */
    public function admin_body_class( $classes ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
            $classes .= ' tz-engine-settings-page';
        }
        return $classes;
    }

    /**
     * @param string $hook_suffix Hook.
     * @return void
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script(
            'wp-color-picker',
            '(function($){$(function(){$(".tz-engine-color-field").wpColorPicker({});});})(jQuery);'
        );

        wp_enqueue_style(
            'tz-engine-admin-ui',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-ui.css',
            array( 'wp-color-picker' ),
            TZ_ENGINE_VERSION
        );
    }

    /**
     * @return void
     */
    public function register_menu() {
        add_menu_page(
            __( 'Techzu Engine', 'techzu-engine' ),
            __( 'Techzu', 'techzu-engine' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' ),
            'dashicons-admin-generic',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Engine settings', 'techzu-engine' ),
            __( 'Engine', 'techzu-engine' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * @return void
     */
    public function handle_save() {
        if ( ! isset( $_POST['tz_engine_settings_nonce'] ) ) {
            return;
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to save these settings.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tz_engine_settings_nonce'] ) ), 'tz_engine_save_settings' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        $before = $this->settings->all();

        if ( ! empty( $_POST['tz_engine_reset_brand_colors'] ) ) {
            $_POST['admin_brand_color']             = '';
            $_POST['admin_widget_heading_color']    = '';
        }
        if ( ! empty( $_POST['tz_engine_reset_admin_slug'] ) ) {
            $_POST['custom_admin_slug'] = '';
        }

        $raw_slug_try = isset( $_POST['custom_admin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_admin_slug'] ) ) : '';

        $wc_hub = (bool) $this->settings->get( 'module_wc_commerce_hub' );
        if ( class_exists( 'WooCommerce' ) ) {
            $wc_hub = ! empty( $_POST['module_wc_commerce_hub'] );
        }

        $incoming = array(
            'module_dashboard_clean'      => ! empty( $_POST['module_dashboard_clean'] ),
            'module_support_dashboard'    => ! empty( $_POST['module_support_dashboard'] ),
            'module_wc_commerce_hub'      => $wc_hub,
            'module_admin_branding'       => ! empty( $_POST['module_admin_branding'] ),
            'module_admin_appearance'     => ! empty( $_POST['module_admin_appearance'] ),
            'module_login_page'           => ! empty( $_POST['module_login_page'] ),
            'support_recipient_email'     => isset( $_POST['support_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['support_recipient_email'] ) ) : '',
            'whatsapp_contact_url'        => isset( $_POST['whatsapp_contact_url'] ) ? esc_url_raw( wp_unslash( $_POST['whatsapp_contact_url'] ) ) : '',
        );

        if ( isset( $_POST['admin_brand_color'] ) ) {
            $brand_hex                        = sanitize_hex_color( wp_unslash( $_POST['admin_brand_color'] ) );
            $incoming['admin_brand_color']    = $brand_hex ? $brand_hex : '';
        }
        if ( isset( $_POST['admin_widget_heading_color'] ) ) {
            $head_hex                              = sanitize_hex_color( wp_unslash( $_POST['admin_widget_heading_color'] ) );
            $incoming['admin_widget_heading_color'] = $head_hex ? $head_hex : '';
        }
        if ( array_key_exists( 'custom_admin_slug', $_POST ) ) {
            $incoming['custom_admin_slug'] = sanitize_text_field( wp_unslash( $_POST['custom_admin_slug'] ) );
        }

        $incoming = wp_parse_args( $incoming, $before );

        $this->settings->save( $incoming );

        if ( '' !== trim( $raw_slug_try ) && '' === (string) $this->settings->get( 'custom_admin_slug', '' ) ) {
            set_transient( 'tz_engine_notice_slug_invalid_' . get_current_user_id(), 1, 120 );
        }

        $after = $this->settings->all();
        if (
            ( isset( $before['custom_admin_slug'] ) ? (string) $before['custom_admin_slug'] : '' ) !== ( isset( $after['custom_admin_slug'] ) ? (string) $after['custom_admin_slug'] : '' )
            || ( ! empty( $before['module_admin_branding'] ) ) !== ( ! empty( $after['module_admin_branding'] ) )
        ) {
            Admin_Branding_Module::schedule_rewrite_flush();
        }

        set_transient( 'tz_engine_settings_saved_' . get_current_user_id(), 1, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    /**
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s = $this->settings->all();
        $engine_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );

        $uid = get_current_user_id();
        if ( $uid && get_transient( 'tz_engine_settings_saved_' . $uid ) ) {
            delete_transient( 'tz_engine_settings_saved_' . $uid );
            echo '<div class="notice notice-success is-dismissible techzu-engine-settings-notice"><p>';
            esc_html_e( 'Techzu Engine settings saved.', 'techzu-engine' );
            echo '</p></div>';
        }
        if ( $uid && get_transient( 'tz_engine_notice_slug_invalid_' . $uid ) ) {
            delete_transient( 'tz_engine_notice_slug_invalid_' . $uid );
            echo '<div class="notice notice-warning is-dismissible techzu-engine-settings-notice"><p>';
            esc_html_e( 'That login URL slug is not allowed or too short. Use 2–80 characters (letters, numbers, hyphens only) and avoid reserved names like wp-admin.', 'techzu-engine' );
            echo '</p></div>';
        }
        ?>
        <div class="wrap tz-engine-settings">
            <div class="tz-engine-settings__hero">
                <h1 class="tz-engine-settings__title"><?php esc_html_e( 'Techzu Engine', 'techzu-engine' ); ?></h1>
                <p class="tz-engine-settings__lead"><?php esc_html_e( 'Turn modules on or off and connect support options. Other Techzu plugins can extend this screen later.', 'techzu-engine' ); ?></p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'tz_engine_save_settings', 'tz_engine_settings_nonce' ); ?>

                <div class="tz-engine-settings__card">
                    <h2><?php esc_html_e( 'Modules', 'techzu-engine' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Dashboard cleanup', 'techzu-engine' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="module_dashboard_clean" value="1" <?php checked( ! empty( $s['module_dashboard_clean'] ) ); ?> />
                                    <?php esc_html_e( 'Declutter the WordPress dashboard, remove the “New” shortcut from the admin bar on the dashboard, and move notices into the Techzu notice hub.', 'techzu-engine' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Dashboard site & help widgets', 'techzu-engine' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="module_support_dashboard" value="1" <?php checked( ! empty( $s['module_support_dashboard'] ) ); ?> />
                                    <?php esc_html_e( 'Show the Site summary widget and the Need help widget (support form and WhatsApp) on the dashboard.', 'techzu-engine' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'WooCommerce commerce hub', 'techzu-engine' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="module_wc_commerce_hub" value="1" <?php checked( ! empty( $s['module_wc_commerce_hub'] ) ); ?> <?php disabled( ! class_exists( 'WooCommerce' ) ); ?> />
                                    <?php esc_html_e( 'Show a WooCommerce shop overview on the WordPress dashboard and on the WooCommerce Home screen (requires WooCommerce).', 'techzu-engine' ); ?>
                                </label>
                                <?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
                                    <p class="description"><?php esc_html_e( 'Install and activate WooCommerce to use this module.', 'techzu-engine' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Admin branding & login URL', 'techzu-engine' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="module_admin_branding" value="1" <?php checked( ! empty( $s['module_admin_branding'] ) ); ?> />
                                    <?php esc_html_e( 'Apply custom admin colors and an optional custom login URL. After you sign in, wp-admin and all admin links use WordPress defaults.', 'techzu-engine' ); ?>
                                </label>
                                <?php if ( ! empty( $s['module_admin_branding'] ) ) : ?>
                                    <p class="description"><?php esc_html_e( 'Uses WordPress admin theme variables so buttons and accents follow your brand where supported.', 'techzu-engine' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Admin appearance', 'techzu-engine' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="module_admin_appearance" value="1" <?php checked( ! empty( $s['module_admin_appearance'] ) ); ?> />
                                    <?php esc_html_e( 'Let Techzu control admin menu order, labels, visibility, and plugin display names.', 'techzu-engine' ); ?>
                                </label>
                                <?php if ( ! empty( $s['module_admin_appearance'] ) ) : ?>
                                    <p class="description">
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=techzu-engine-admin-appearance' ) ); ?>"><?php esc_html_e( 'Open Admin appearance', 'techzu-engine' ); ?></a>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Login page customization', 'techzu-engine' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="module_login_page" value="1" <?php checked( ! empty( $s['module_login_page'] ) ); ?> />
                                    <?php esc_html_e( 'Customize the WordPress login page with custom logo, colors, and styling.', 'techzu-engine' ); ?>
                                </label>
                                <?php if ( ! empty( $s['module_login_page'] ) ) : ?>
                                    <p class="description">
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=techzu-engine-login-page' ) ); ?>"><?php esc_html_e( 'Open Login page settings', 'techzu-engine' ); ?></a>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                    </table>
                </div>

                <?php if ( ! empty( $s['module_admin_branding'] ) ) : ?>
                <div class="tz-engine-settings__card">
                    <h2><?php esc_html_e( 'Admin branding & login URL', 'techzu-engine' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row"><label for="admin_brand_color"><?php esc_html_e( 'Brand color', 'techzu-engine' ); ?></label></th>
                            <td>
                                <input type="text" name="admin_brand_color" id="admin_brand_color" class="tz-engine-color-field" value="<?php echo esc_attr( $s['admin_brand_color'] ); ?>" data-default-color="" />
                                <p class="description"><?php esc_html_e( 'Replaces the default wp-admin blue for the active menu highlight and related theme accents.', 'techzu-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_widget_heading_color"><?php esc_html_e( 'Dashboard widget title color', 'techzu-engine' ); ?></label></th>
                            <td>
                                <input type="text" name="admin_widget_heading_color" id="admin_widget_heading_color" class="tz-engine-color-field" value="<?php echo esc_attr( $s['admin_widget_heading_color'] ); ?>" data-default-color="" />
                                <p class="description"><?php esc_html_e( 'Leave blank to match the brand color.', 'techzu-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="custom_admin_slug"><?php esc_html_e( 'Custom login URL slug', 'techzu-engine' ); ?></label></th>
                            <td>
                                <input name="custom_admin_slug" id="custom_admin_slug" type="text" class="regular-text code" value="<?php echo esc_attr( $s['custom_admin_slug'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. studio-console', 'techzu-engine' ); ?>" autocomplete="off" spellcheck="false" />
                                <p class="description">
                                    <?php esc_html_e( 'Single path segment (no slashes). Guests sign in only here. Direct requests to wp-login.php or wp-admin (except admin-ajax for forms) return “Not found”. Login links use this URL automatically; after login, /wp-admin/ works normally.', 'techzu-engine' ); ?>
                                    <code><?php echo esc_html( trailingslashit( home_url( $s['custom_admin_slug'] ? $s['custom_admin_slug'] . '/' : 'your-slug/', 'admin' ) ) ); ?></code>
                                </p>
                                <?php if ( ! empty( $s['custom_admin_slug'] ) && ! (string) get_option( 'permalink_structure' ) ) : ?>
                                    <p class="description"><strong><?php esc_html_e( 'Pretty permalinks are off.', 'techzu-engine' ); ?></strong> <?php esc_html_e( 'Set Permalinks to anything other than “Plain” in Settings → Permalinks so the custom admin URL can work.', 'techzu-engine' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Reset branding', 'techzu-engine' ); ?></th>
                            <td>
                                <button type="submit" name="tz_engine_reset_brand_colors" value="1" class="button"><?php esc_html_e( 'Reset colors to default', 'techzu-engine' ); ?></button>
                                <button type="submit" name="tz_engine_reset_admin_slug" value="1" class="button"><?php esc_html_e( 'Reset login URL slug', 'techzu-engine' ); ?></button>
                                <p class="description"><?php esc_html_e( 'Clearing the slug restores the default wp-login.php entry. Visit Settings → Permalinks and save once if the login URL misbehaves.', 'techzu-engine' ); ?></p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="tz-engine-settings__card">
                    <h2><?php esc_html_e( 'Support & WhatsApp', 'techzu-engine' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row"><label for="support_recipient_email"><?php esc_html_e( 'Support recipient email', 'techzu-engine' ); ?></label></th>
                            <td>
                                <input name="support_recipient_email" id="support_recipient_email" type="email" class="regular-text" value="<?php echo esc_attr( $s['support_recipient_email'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Dashboard support submissions are sent to this address.', 'techzu-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="whatsapp_contact_url"><?php esc_html_e( 'WhatsApp URL', 'techzu-engine' ); ?></label></th>
                            <td>
                                <input name="whatsapp_contact_url" id="whatsapp_contact_url" type="url" class="regular-text code" value="<?php echo esc_attr( $s['whatsapp_contact_url'] ); ?>" placeholder="https://wa.me/1234567890" />
                                <p class="description">
                                    <?php esc_html_e( 'Full link shown on the dashboard support widget.', 'techzu-engine' ); ?>
                                    <?php if ( $s['whatsapp_contact_url'] ) : ?>
                                        <a href="<?php echo esc_url( $s['whatsapp_contact_url'] ); ?>" class="tz-engine-settings__inline-link" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Test link', 'techzu-engine' ); ?></a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( $engine_url ); ?>" class="tz-engine-settings__inline-link"><?php esc_html_e( 'Engine settings', 'techzu-engine' ); ?></a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <?php submit_button( __( 'Save Engine settings', 'techzu-engine' ) ); ?>
            </form>
        </div>
        <?php
    }
}
