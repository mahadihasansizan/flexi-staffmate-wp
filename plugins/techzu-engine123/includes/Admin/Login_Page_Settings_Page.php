<?php
namespace Techzu\Engine\Admin;

use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Login_Page_Settings_Page {
    const SLUG = 'techzu-engine-login-page';

    /**
     * @var Login_Page_Store
     */
    protected $store;

    /**
     * @var Settings
     */
    protected $settings;

    public function __construct( Login_Page_Store $store, Settings $settings ) {
        $this->store    = $store;
        $this->settings = $settings;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_submenu' ), 40 );
        add_action( 'admin_init', array( $this, 'handle_post' ), 10 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Register submenu page.
     *
     * @return void
     */
    public function register_submenu() {
        add_submenu_page(
            Settings_Page::MENU_SLUG,
            __( 'Login Page', 'techzu-engine' ),
            __( 'Login Page', 'techzu-engine' ),
            'manage_options',
            self::SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * Handle form submission.
     *
     * @return void
     */
    public function handle_post() {
        if ( ! isset( $_POST['tz_login_page_nonce'] ) ) {
            return;
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to save login page settings.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tz_login_page_nonce'] ) ), 'tz_login_page_form' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        $values = array(
            'enable_customization' => ! empty( $_POST['tz_login_enable'] ),
        );

        // Collect form values

        if ( isset( $_POST['tz_login_logo_url'] ) ) {
            $values['logo_url'] = esc_url_raw( wp_unslash( $_POST['tz_login_logo_url'] ) );
        }

        if ( isset( $_POST['tz_login_logo_width'] ) ) {
            $values['logo_width'] = absint( sanitize_text_field( wp_unslash( $_POST['tz_login_logo_width'] ) ) );
        }

        if ( isset( $_POST['tz_login_logo_height'] ) ) {
            $values['logo_height'] = absint( sanitize_text_field( wp_unslash( $_POST['tz_login_logo_height'] ) ) );
        }

        if ( isset( $_POST['tz_login_bg_color'] ) ) {
            $values['background_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_bg_color'] ) );
        }

        if ( isset( $_POST['tz_login_bg_image'] ) ) {
            $values['background_image'] = sanitize_text_field( wp_unslash( $_POST['tz_login_bg_image'] ) );
        }

        if ( isset( $_POST['tz_login_bg_size'] ) ) {
            $values['background_size'] = sanitize_text_field( wp_unslash( $_POST['tz_login_bg_size'] ) );
        }

        if ( isset( $_POST['tz_login_text_color'] ) ) {
            $values['text_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_text_color'] ) );
        }

        if ( isset( $_POST['tz_login_link_color'] ) ) {
            $values['link_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_link_color'] ) );
        }

        if ( isset( $_POST['tz_login_button_bg_color'] ) ) {
            $values['button_bg_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_button_bg_color'] ) );
        }

        if ( isset( $_POST['tz_login_button_text_color'] ) ) {
            $values['button_text_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_button_text_color'] ) );
        }

        if ( isset( $_POST['tz_login_button_hover_bg_color'] ) ) {
            $values['button_hover_bg_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_button_hover_bg_color'] ) );
        }

        if ( isset( $_POST['tz_login_input_border_color'] ) ) {
            $values['input_border_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_input_border_color'] ) );
        }

        if ( isset( $_POST['tz_login_input_focus_color'] ) ) {
            $values['input_focus_color'] = sanitize_text_field( wp_unslash( $_POST['tz_login_input_focus_color'] ) );
        }

        if ( isset( $_POST['tz_login_hide_hint'] ) ) {
            $values['hide_login_hint'] = sanitize_text_field( wp_unslash( $_POST['tz_login_hide_hint'] ) );
        }

        $this->store->save( $values );
        set_transient( 'tz_engine_login_page_saved_' . get_current_user_id(), 1, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
        exit;
    }

    /**
     * Enqueue scripts and styles.
     *
     * @param string $hook_suffix Hook suffix.
     * @return void
     */
    public function enqueue( $hook_suffix ) {
        if ( false === strpos( $hook_suffix, self::SLUG ) ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        wp_enqueue_media();

        wp_enqueue_style(
            'tz-engine-admin-ui',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-ui.css',
            array(),
            TZ_ENGINE_VERSION
        );

        wp_enqueue_script(
            'tz-engine-login-page',
            TZ_ENGINE_PLUGIN_URL . 'assets/js/login-page.js',
            array( 'jquery', 'wp-color-picker' ),
            TZ_ENGINE_VERSION,
            true
        );
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        $settings = $this->store->get_all();
        $uid      = get_current_user_id();
        if ( $uid && get_transient( 'tz_engine_login_page_saved_' . $uid ) ) {
            delete_transient( 'tz_engine_login_page_saved_' . $uid );
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e( 'Login page settings saved.', 'techzu-engine' );
            echo '</p></div>';
        }
        ?>
        <div class="wrap tz-engine-login-page-wrap">
            <h1><?php esc_html_e( 'Login Page Customization', 'techzu-engine' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Customize the WordPress login page with your logo, colors, and styling.', 'techzu-engine' ); ?>
            </p>

            <div class="tz-settings-container">
                <form method="post" action="">
                    <?php wp_nonce_field( 'tz_login_page_form', 'tz_login_page_nonce' ); ?>

                    <table class="form-table">
                        <!-- Enable Customization -->
                        <tr>
                            <th scope="row">
                                <label for="tz_login_enable">
                                    <?php esc_html_e( 'Enable Login Customization', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="tz_login_enable" name="tz_login_enable" value="1" 
                                    <?php checked( $settings['enable_customization'], true ); ?> />
                                <p class="description">
                                    <?php esc_html_e( 'Enable or disable all login page customizations.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Logo Section -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3><?php esc_html_e( 'Logo Settings', 'techzu-engine' ); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_logo_url">
                                    <?php esc_html_e( 'Logo URL', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_logo_url" name="tz_login_logo_url" 
                                    value="<?php echo esc_attr( $settings['logo_url'] ); ?>" class="regular-text" />
                                <button type="button" class="button tz-upload-media-btn" data-target="tz_login_logo_url">
                                    <?php esc_html_e( 'Upload Logo', 'techzu-engine' ); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e( 'Upload or select a logo image URL.', 'techzu-engine' ); ?>
                                </p>
                                <div class="tz-logo-preview">
                                    <?php if ( ! empty( $settings['logo_url'] ) ) : ?>
                                        <img src="<?php echo esc_url( $settings['logo_url'] ); ?>" 
                                            style="max-width: <?php echo absint( $settings['logo_width'] ); ?>px; height: auto;" />
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_logo_width">
                                    <?php esc_html_e( 'Logo Width (px)', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="tz_login_logo_width" name="tz_login_logo_width" 
                                    value="<?php echo absint( $settings['logo_width'] ); ?>" min="10" max="500" />
                                <p class="description">
                                    <?php esc_html_e( 'Logo width in pixels.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_logo_height">
                                    <?php esc_html_e( 'Logo Height (px)', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="tz_login_logo_height" name="tz_login_logo_height" 
                                    value="<?php echo absint( $settings['logo_height'] ); ?>" min="10" max="500" />
                                <p class="description">
                                    <?php esc_html_e( 'Logo height in pixels.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Background Section -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3><?php esc_html_e( 'Background Settings', 'techzu-engine' ); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_bg_color">
                                    <?php esc_html_e( 'Background Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_bg_color" name="tz_login_bg_color" 
                                    value="<?php echo esc_attr( $settings['background_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Select the login page background color.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_bg_image">
                                    <?php esc_html_e( 'Background Image', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_bg_image" name="tz_login_bg_image" 
                                    value="<?php echo esc_attr( $settings['background_image'] ); ?>" class="regular-text" />
                                <button type="button" class="button tz-upload-media-btn" data-target="tz_login_bg_image">
                                    <?php esc_html_e( 'Upload Image', 'techzu-engine' ); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e( 'Upload or select a background image URL.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_bg_size">
                                    <?php esc_html_e( 'Background Size', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <select id="tz_login_bg_size" name="tz_login_bg_size">
                                    <option value="auto" <?php selected( $settings['background_size'], 'auto' ); ?>>
                                        <?php esc_html_e( 'Auto', 'techzu-engine' ); ?>
                                    </option>
                                    <option value="cover" <?php selected( $settings['background_size'], 'cover' ); ?>>
                                        <?php esc_html_e( 'Cover', 'techzu-engine' ); ?>
                                    </option>
                                    <option value="contain" <?php selected( $settings['background_size'], 'contain' ); ?>>
                                        <?php esc_html_e( 'Contain', 'techzu-engine' ); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'How the background image should be sized.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Text & Links Section -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3><?php esc_html_e( 'Text & Links Colors', 'techzu-engine' ); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_text_color">
                                    <?php esc_html_e( 'Text Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_text_color" name="tz_login_text_color" 
                                    value="<?php echo esc_attr( $settings['text_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Color for body text on the login page.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_link_color">
                                    <?php esc_html_e( 'Link Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_link_color" name="tz_login_link_color" 
                                    value="<?php echo esc_attr( $settings['link_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Color for hyperlinks on the login page.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Button Section -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3><?php esc_html_e( 'Button Colors', 'techzu-engine' ); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_button_bg_color">
                                    <?php esc_html_e( 'Button Background Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_button_bg_color" name="tz_login_button_bg_color" 
                                    value="<?php echo esc_attr( $settings['button_bg_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Background color for the login button.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_button_text_color">
                                    <?php esc_html_e( 'Button Text Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_button_text_color" name="tz_login_button_text_color" 
                                    value="<?php echo esc_attr( $settings['button_text_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Text color for the login button.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_button_hover_bg_color">
                                    <?php esc_html_e( 'Button Hover Background Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_button_hover_bg_color" name="tz_login_button_hover_bg_color" 
                                    value="<?php echo esc_attr( $settings['button_hover_bg_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Background color when hovering over the button.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Input Section -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3><?php esc_html_e( 'Input Fields Colors', 'techzu-engine' ); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_input_border_color">
                                    <?php esc_html_e( 'Input Border Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_input_border_color" name="tz_login_input_border_color" 
                                    value="<?php echo esc_attr( $settings['input_border_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Border color for input fields.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_input_focus_color">
                                    <?php esc_html_e( 'Input Focus Color', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="tz_login_input_focus_color" name="tz_login_input_focus_color" 
                                    value="<?php echo esc_attr( $settings['input_focus_color'] ); ?>" class="tz-color-picker" />
                                <p class="description">
                                    <?php esc_html_e( 'Border color when input is focused.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Additional Options -->
                        <tr>
                            <th scope="row" colspan="2">
                                <h3><?php esc_html_e( 'Additional Options', 'techzu-engine' ); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="tz_login_hide_hint">
                                    <?php esc_html_e( 'Hide Login Hint', 'techzu-engine' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="tz_login_hide_hint" name="tz_login_hide_hint" value="1" 
                                    <?php checked( $settings['hide_login_hint'], true ); ?> />
                                <p class="description">
                                    <?php esc_html_e( 'Hide the "Lost your password?" link and other login hints.', 'techzu-engine' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( __( 'Save Changes', 'techzu-engine' ) ); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
