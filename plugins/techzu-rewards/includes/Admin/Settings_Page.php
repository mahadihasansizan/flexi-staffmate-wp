<?php
namespace Techzu\Rewards\Admin;

use Techzu\Rewards\Rewards\Calculator;
use Techzu\Rewards\Rewards\Points_Manager;
use Techzu\Rewards\Rewards\Tier_Manager;
use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings_Page {
    /**
     * Settings instance.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Calculator.
     *
     * @var Calculator
     */
    protected $calculator;

    /**
     * Points manager.
     *
     * @var Points_Manager
     */
    protected $points_manager;

    /**
     * Tier manager.
     *
     * @var Tier_Manager
     */
    protected $tier_manager;

    /**
     * Menu slug.
     *
     * @var string
     */
    protected $menu_slug = 'tz-rewards';

    /**
     * Constructor.
     *
     * @param Settings       $settings Settings object.
     * @param Calculator     $calculator Calculator.
     * @param Points_Manager $points_manager Points manager.
     * @param Tier_Manager   $tier_manager Tier manager.
     */
    public function __construct( Settings $settings, Calculator $calculator, Points_Manager $points_manager, Tier_Manager $tier_manager ) {
        $this->settings       = $settings;
        $this->calculator     = $calculator;
        $this->points_manager = $points_manager;
        $this->tier_manager   = $tier_manager;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_customer_update' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'show_user_profile', array( $this, 'render_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_user_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
    }

    /**
     * Register admin menu.
     *
     * @return void
     */
    public function register_menu() {
        add_menu_page(
            __( 'ElegantBliss Rewards', 'techzu-rewards' ),
            __( 'ElegantBliss Rewards', 'techzu-rewards' ),
            'manage_woocommerce',
            $this->menu_slug,
            array( $this, 'render_page' ),
            'dashicons-awards',
            56
        );

        add_submenu_page(
            $this->menu_slug,
            __( 'Rewards Settings', 'techzu-rewards' ),
            __( 'Settings', 'techzu-rewards' ),
            'manage_woocommerce',
            $this->menu_slug,
            array( $this, 'render_page' )
        );

        add_submenu_page(
            $this->menu_slug,
            __( 'Reward Customers', 'techzu-rewards' ),
            __( 'Customers', 'techzu-rewards' ),
            'manage_woocommerce',
            'tz-rewards-customers',
            array( $this, 'render_page' )
        );

        add_submenu_page(
            $this->menu_slug,
            __( 'Rewards Guide', 'techzu-rewards' ),
            __( 'Guide', 'techzu-rewards' ),
            'manage_woocommerce',
            'tz-rewards-guide',
            array( $this, 'render_page' )
        );
    }

    /**
     * Register the plugin option.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'tz_rewards_settings_group',
            Settings::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( Settings::class, 'normalize' ),
                'default'           => Settings::defaults(),
            )
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook_suffix Current admin hook.
     * @return void
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( false === strpos( $hook_suffix, 'tz-rewards' ) ) {
            return;
        }

        wp_enqueue_style(
            'tz-rewards-admin',
            TZ_REWARDS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            TZ_REWARDS_VERSION
        );

        wp_enqueue_script(
            'tz-rewards-admin',
            TZ_REWARDS_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            TZ_REWARDS_VERSION,
            true
        );
    }

    /**
     * Handle customer balance/profile updates.
     *
     * @return void
     */
    public function handle_customer_update() {
        if ( empty( $_POST['tz_rewards_customer_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage rewards.', 'techzu-rewards' ) );
        }

        check_admin_referer( 'tz_rewards_update_customer' );

        $user_id = isset( $_POST['tz_rewards_customer_user_id'] ) ? absint( wp_unslash( $_POST['tz_rewards_customer_user_id'] ) ) : 0;
        if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
            wp_die( esc_html__( 'Invalid customer.', 'techzu-rewards' ) );
        }

        if ( isset( $_POST['tz_rewards_customer_balance'] ) ) {
            $this->points_manager->set_balance(
                $user_id,
                absint( wp_unslash( $_POST['tz_rewards_customer_balance'] ) ),
                __( 'Admin customer control update', 'techzu-rewards' )
            );
        }

        if ( isset( $_POST['tz_rewards_customer_birthday'] ) ) {
            $this->tier_manager->set_birthday( $user_id, sanitize_text_field( wp_unslash( $_POST['tz_rewards_customer_birthday'] ) ) );
        }

        if ( isset( $_POST['tz_rewards_customer_manual_tier'] ) ) {
            $this->tier_manager->set_manual_tier( $user_id, sanitize_key( wp_unslash( $_POST['tz_rewards_customer_manual_tier'] ) ) );
        }

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=tz-rewards-customers' );
        wp_safe_redirect( add_query_arg( 'tz_rewards_updated', 'customer', $redirect ) );
        exit;
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $tab = $this->get_current_tab();
        ?>
        <div class="wrap tz-rewards-admin">
            <div class="tz-rewards-admin-hero">
                <div>
                    <p class="tz-rewards-admin-hero__eyebrow"><?php esc_html_e( 'WooCommerce loyalty programme', 'techzu-rewards' ); ?></p>
                    <h1><?php esc_html_e( 'ElegantBliss Rewards', 'techzu-rewards' ); ?></h1>
                    <p><?php esc_html_e( 'Manage points, reward vouchers, membership tiers, birthday perks, programme copy, customer balances and documentation from one simple app.', 'techzu-rewards' ); ?></p>
                </div>
                <div class="tz-rewards-admin-hero__badge"><?php echo esc_html( 'v' . TZ_REWARDS_VERSION ); ?></div>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rewards settings saved.', 'techzu-rewards' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['tz_rewards_updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Customer rewards updated.', 'techzu-rewards' ); ?></p></div>
            <?php endif; ?>

            <?php $this->render_tabs( $tab ); ?>

            <?php
            if ( 'customers' === $tab ) {
                $this->render_customers_page();
            } elseif ( 'guide' === $tab ) {
                $this->render_guide_page();
            } else {
                $this->render_settings_page();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Get current tab.
     *
     * @return string
     */
    protected function get_current_tab() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : $this->menu_slug;
        if ( 'tz-rewards-customers' === $page ) {
            return 'customers';
        }
        if ( 'tz-rewards-guide' === $page ) {
            return 'guide';
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        return in_array( $tab, array( 'settings', 'customers', 'guide' ), true ) ? $tab : 'settings';
    }

    /**
     * Render admin tabs.
     *
     * @param string $active Active tab.
     * @return void
     */
    protected function render_tabs( $active ) {
        $tabs = array(
            'settings'  => array( 'label' => __( 'Settings', 'techzu-rewards' ), 'url' => admin_url( 'admin.php?page=tz-rewards' ) ),
            'customers' => array( 'label' => __( 'Customers', 'techzu-rewards' ), 'url' => admin_url( 'admin.php?page=tz-rewards-customers' ) ),
            'guide'     => array( 'label' => __( 'Guide', 'techzu-rewards' ), 'url' => admin_url( 'admin.php?page=tz-rewards-guide' ) ),
        );
        echo '<nav class="nav-tab-wrapper tz-rewards-tabs">';
        foreach ( $tabs as $key => $tab ) {
            printf(
                '<a class="nav-tab %1$s" href="%2$s">%3$s</a>',
                esc_attr( $active === $key ? 'nav-tab-active' : '' ),
                esc_url( $tab['url'] ),
                esc_html( $tab['label'] )
            );
        }
        echo '</nav>';
    }

    /**
     * Render settings form.
     *
     * @return void
     */
    protected function render_settings_page() {
        $settings         = $this->settings->all();
        $order_statuses   = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        $redemption_tiers = $this->calculator->get_redemption_tiers( false );
        $membership_tiers = $this->tier_manager->get_all_tiers();
        $faq_items        = $settings['faq_items'];
        ?>
        <form method="post" action="options.php" class="tz-rewards-settings-form">
            <?php settings_fields( 'tz_rewards_settings_group' ); ?>

            <div class="tz-rewards-admin__grid">
                <section class="tz-rewards-panel tz-rewards-panel--wide">
                    <h2><?php esc_html_e( 'Programme basics', 'techzu-rewards' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'These are the core earning, expiry and one-code-per-order rules from the client screenshot.', 'techzu-rewards' ); ?></p>
                    <div class="tz-rewards-fields-grid">
                        <?php $this->checkbox_field( 'enabled', __( 'Enable rewards programme', 'techzu-rewards' ), $settings['enabled'] ); ?>
                        <?php $this->text_field( 'points_label', __( 'Plural points label', 'techzu-rewards' ), $settings['points_label'] ); ?>
                        <?php $this->text_field( 'point_label_singular', __( 'Singular points label', 'techzu-rewards' ), $settings['point_label_singular'] ); ?>
                        <?php $this->text_field( 'voucher_label', __( 'Voucher label', 'techzu-rewards' ), $settings['voucher_label'] ); ?>
                        <?php $this->number_field( 'points_per_dollar', __( 'Points per $1 spent', 'techzu-rewards' ), $settings['points_per_dollar'], '0.01' ); ?>
                        <?php $this->number_field( 'minimum_spend', __( 'Minimum spend to earn points', 'techzu-rewards' ), $settings['minimum_spend'], '0.01' ); ?>
                        <?php $this->number_field( 'points_expiry_months', __( 'Points expiry in months', 'techzu-rewards' ), $settings['points_expiry_months'], '1' ); ?>
                        <?php $this->select_field( 'rounding_mode', __( 'Point rounding', 'techzu-rewards' ), $settings['rounding_mode'], array( 'floor' => __( 'Round down', 'techzu-rewards' ), 'round' => __( 'Round nearest', 'techzu-rewards' ), 'ceil' => __( 'Round up', 'techzu-rewards' ) ) ); ?>
                        <?php $this->checkbox_field( 'exclude_sale_items', __( 'Exclude sale items from earning and tier spend', 'techzu-rewards' ), $settings['exclude_sale_items'] ); ?>
                        <?php $this->checkbox_field( 'subtract_negative_fees', __( 'Subtract reward/discount fees from eligible paid product amount', 'techzu-rewards' ), $settings['subtract_negative_fees'] ); ?>
                        <?php $this->checkbox_field( 'non_stacking_enabled', __( 'Enable one reward/discount/coupon per order', 'techzu-rewards' ), $settings['non_stacking_enabled'] ); ?>
                        <?php $this->checkbox_field( 'block_coupons_when_reward_active', __( 'Block coupons when reward or birthday discount is active', 'techzu-rewards' ), $settings['block_coupons_when_reward_active'] ); ?>
                    </div>
                    <?php $this->status_checkboxes( 'earn_order_statuses', __( 'Award points when order status becomes', 'techzu-rewards' ), $settings['earn_order_statuses'], $order_statuses ); ?>
                </section>

                <section class="tz-rewards-panel tz-rewards-panel--wide">
                    <h2><?php esc_html_e( 'Reward voucher conversion', 'techzu-rewards' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Default client rule: every 150 Bliss Points gives S$5 off, and the same conversion continues for larger balances. Fixed rows below control the public display table and can also be used as the checkout rule when mode is set to Fixed tiers only.', 'techzu-rewards' ); ?></p>
                    <div class="tz-rewards-fields-grid">
                        <?php $this->select_field( 'redemption_mode', __( 'Checkout redemption mode', 'techzu-rewards' ), $settings['redemption_mode'], array( 'continuous' => __( 'Continuous conversion: every step repeats', 'techzu-rewards' ), 'fixed' => __( 'Fixed tiers only', 'techzu-rewards' ) ) ); ?>
                        <?php $this->number_field( 'redemption_step_points', __( 'Continuous step points', 'techzu-rewards' ), $settings['redemption_step_points'], '1' ); ?>
                        <?php $this->number_field( 'redemption_step_discount', __( 'Continuous step discount amount', 'techzu-rewards' ), $settings['redemption_step_discount'], '0.01' ); ?>
                        <?php $this->number_field( 'redemption_max_generated_steps', __( 'Max generated choices, 0 = continue automatically', 'techzu-rewards' ), $settings['redemption_max_generated_steps'], '1' ); ?>
                    </div>
                    <?php $this->render_redemption_repeater( $redemption_tiers ); ?>
                </section>

                <section class="tz-rewards-panel tz-rewards-panel--wide">
                    <h2><?php esc_html_e( 'Membership tiers and birthday perks', 'techzu-rewards' ); ?></h2>
                    <div class="tz-rewards-fields-grid">
                        <?php $this->number_field( 'tier_window_months', __( 'Tier spend window in months', 'techzu-rewards' ), $settings['tier_window_months'], '1' ); ?>
                        <?php $this->checkbox_field( 'birthday_enabled', __( 'Enable birthday discounts', 'techzu-rewards' ), $settings['birthday_enabled'] ); ?>
                        <?php $this->number_field( 'birthday_minimum_spend', __( 'Birthday minimum eligible spend', 'techzu-rewards' ), $settings['birthday_minimum_spend'], '0.01' ); ?>
                        <?php $this->checkbox_field( 'birthday_block_sale_items', __( 'Block birthday discounts on carts containing sale items', 'techzu-rewards' ), $settings['birthday_block_sale_items'] ); ?>
                        <?php $this->checkbox_field( 'birthday_auto_apply', __( 'Auto-apply birthday discount when eligible', 'techzu-rewards' ), $settings['birthday_auto_apply'] ); ?>
                        <?php $this->text_field( 'birthday_label', __( 'Birthday discount label', 'techzu-rewards' ), $settings['birthday_label'] ); ?>
                    </div>
                    <?php $this->status_checkboxes( 'tier_order_statuses', __( 'Order statuses counted for tier spend', 'techzu-rewards' ), $settings['tier_order_statuses'], $order_statuses ); ?>
                    <?php $this->render_membership_repeater( $membership_tiers ); ?>
                </section>

                <section class="tz-rewards-panel tz-rewards-panel--wide">
                    <h2><?php esc_html_e( 'Programme page content', 'techzu-rewards' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Everything visible on the public rewards programme design is editable here.', 'techzu-rewards' ); ?></p>
                    <div class="tz-rewards-fields-grid">
                        <?php $this->text_field( 'program_brand_text', __( 'Top brand text', 'techzu-rewards' ), $settings['program_brand_text'] ); ?>
                        <?php $this->text_field( 'program_overline', __( 'Overline', 'techzu-rewards' ), $settings['program_overline'] ); ?>
                        <?php $this->text_field( 'program_title', __( 'Programme title', 'techzu-rewards' ), $settings['program_title'] ); ?>
                        <?php $this->text_field( 'program_tagline', __( 'Tagline', 'techzu-rewards' ), $settings['program_tagline'] ); ?>
                        <?php $this->text_field( 'program_earning_message', __( 'Earning banner text', 'techzu-rewards' ), $settings['program_earning_message'] ); ?>
                        <?php $this->text_field( 'rewards_section_title', __( 'Rewards section title', 'techzu-rewards' ), $settings['rewards_section_title'] ); ?>
                        <?php $this->text_field( 'rewards_section_subtitle', __( 'Rewards section subtitle', 'techzu-rewards' ), $settings['rewards_section_subtitle'] ); ?>
                        <?php $this->text_field( 'tiers_section_title', __( 'Tiers section title', 'techzu-rewards' ), $settings['tiers_section_title'] ); ?>
                        <?php $this->text_field( 'tiers_section_subtitle', __( 'Tiers section subtitle', 'techzu-rewards' ), $settings['tiers_section_subtitle'] ); ?>
                        <?php $this->text_field( 'faq_section_title', __( 'FAQ section title', 'techzu-rewards' ), $settings['faq_section_title'] ); ?>
                    </div>
                    <?php $this->textarea_field( 'birthday_terms', __( 'Birthday terms note', 'techzu-rewards' ), $settings['birthday_terms'] ); ?>
                    <?php $this->textarea_field( 'terms_note', __( 'Programme terms note', 'techzu-rewards' ), $settings['terms_note'] ); ?>
                    <?php $this->render_faq_repeater( $faq_items ); ?>
                </section>

                <section class="tz-rewards-panel tz-rewards-panel--wide">
                    <h2><?php esc_html_e( 'Customer email notifications', 'techzu-rewards' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Send customers automatic emails when they join, earn points, use points, move membership tiers, receive manual admin point updates, or have points expiring soon.', 'techzu-rewards' ); ?></p>
                    <div class="tz-rewards-fields-grid">
                        <?php $this->checkbox_field( 'email_notifications_enabled', __( 'Enable all reward notification emails', 'techzu-rewards' ), $settings['email_notifications_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_welcome_enabled', __( 'Email after account creation / Bronze assignment', 'techzu-rewards' ), $settings['email_welcome_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_points_earned_enabled', __( 'Email when points are earned', 'techzu-rewards' ), $settings['email_points_earned_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_points_used_enabled', __( 'Email when points are used', 'techzu-rewards' ), $settings['email_points_used_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_tier_updated_enabled', __( 'Email when membership tier changes', 'techzu-rewards' ), $settings['email_tier_updated_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_points_expiring_enabled', __( 'Email before points expire', 'techzu-rewards' ), $settings['email_points_expiring_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_points_expired_enabled', __( 'Email when points expire', 'techzu-rewards' ), $settings['email_points_expired_enabled'] ); ?>
                        <?php $this->checkbox_field( 'email_manual_adjustment_enabled', __( 'Email after manual admin balance update', 'techzu-rewards' ), $settings['email_manual_adjustment_enabled'] ); ?>
                        <?php $this->number_field( 'email_points_expiry_days', __( 'Expiry soon reminder days', 'techzu-rewards' ), $settings['email_points_expiry_days'], '1' ); ?>
                        <?php $this->text_field( 'email_brand_title', __( 'Email heading / brand title', 'techzu-rewards' ), $settings['email_brand_title'] ); ?>
                        <?php $this->text_field( 'email_subject_welcome', __( 'Subject: welcome', 'techzu-rewards' ), $settings['email_subject_welcome'] ); ?>
                        <?php $this->text_field( 'email_subject_points_earned', __( 'Subject: points earned', 'techzu-rewards' ), $settings['email_subject_points_earned'] ); ?>
                        <?php $this->text_field( 'email_subject_points_used', __( 'Subject: points used', 'techzu-rewards' ), $settings['email_subject_points_used'] ); ?>
                        <?php $this->text_field( 'email_subject_tier_updated', __( 'Subject: tier updated', 'techzu-rewards' ), $settings['email_subject_tier_updated'] ); ?>
                        <?php $this->text_field( 'email_subject_points_expiring', __( 'Subject: points expiring soon', 'techzu-rewards' ), $settings['email_subject_points_expiring'] ); ?>
                        <?php $this->text_field( 'email_subject_points_expired', __( 'Subject: points expired', 'techzu-rewards' ), $settings['email_subject_points_expired'] ); ?>
                        <?php $this->text_field( 'email_subject_manual_adjustment', __( 'Subject: manual adjustment', 'techzu-rewards' ), $settings['email_subject_manual_adjustment'] ); ?>
                    </div>
                    <?php $this->textarea_field( 'email_intro_welcome', __( 'Intro: welcome / Bronze assignment', 'techzu-rewards' ), $settings['email_intro_welcome'] ); ?>
                    <?php $this->textarea_field( 'email_intro_points_earned', __( 'Intro: points earned', 'techzu-rewards' ), $settings['email_intro_points_earned'] ); ?>
                    <?php $this->textarea_field( 'email_intro_points_used', __( 'Intro: points used', 'techzu-rewards' ), $settings['email_intro_points_used'] ); ?>
                    <?php $this->textarea_field( 'email_intro_tier_updated', __( 'Intro: tier updated', 'techzu-rewards' ), $settings['email_intro_tier_updated'] ); ?>
                    <?php $this->textarea_field( 'email_intro_points_expiring', __( 'Intro: points expiring soon', 'techzu-rewards' ), $settings['email_intro_points_expiring'] ); ?>
                    <?php $this->textarea_field( 'email_intro_points_expired', __( 'Intro: points expired', 'techzu-rewards' ), $settings['email_intro_points_expired'] ); ?>
                    <?php $this->textarea_field( 'email_intro_manual_adjustment', __( 'Intro: manual adjustment', 'techzu-rewards' ), $settings['email_intro_manual_adjustment'] ); ?>
                    <?php $this->textarea_field( 'email_footer_text', __( 'Email footer text', 'techzu-rewards' ), $settings['email_footer_text'] ); ?>
                    <p class="description"><?php esc_html_e( 'Available merge tags: {site_name}, {first_name}, {display_name}, {points}, {points_label}, {balance}, {tier}, {old_tier}, {order_number}, {expiry_days}.', 'techzu-rewards' ); ?></p>
                </section>

                <section class="tz-rewards-panel">
                    <h2><?php esc_html_e( 'Visibility and design', 'techzu-rewards' ); ?></h2>
                    <?php $this->checkbox_field( 'show_catalog_hint', __( 'Show product/catalog earning hints', 'techzu-rewards' ), $settings['show_catalog_hint'] ); ?>
                    <?php $this->checkbox_field( 'show_account_summary', __( 'Show My Account rewards summary card', 'techzu-rewards' ), $settings['show_account_summary'] ); ?>
                    <?php $this->checkbox_field( 'show_my_account_endpoint', __( 'Show Rewards tab inside My Account', 'techzu-rewards' ), $settings['show_my_account_endpoint'] ); ?>
                    <?php $this->checkbox_field( 'show_email_summary', __( 'Show rewards summary in customer emails', 'techzu-rewards' ), $settings['show_email_summary'] ); ?>
                    <?php $this->checkbox_field( 'show_program_shortcode', __( 'Enable public programme shortcode', 'techzu-rewards' ), $settings['show_program_shortcode'] ); ?>
                    <hr>
                    <?php $this->color_field( 'frontend_accent', __( 'Accent color', 'techzu-rewards' ), $settings['frontend_accent'] ); ?>
                    <?php $this->color_field( 'frontend_background', __( 'Main background', 'techzu-rewards' ), $settings['frontend_background'] ); ?>
                    <?php $this->color_field( 'frontend_surface', __( 'Soft surface', 'techzu-rewards' ), $settings['frontend_surface'] ); ?>
                    <?php $this->color_field( 'frontend_border', __( 'Border color', 'techzu-rewards' ), $settings['frontend_border'] ); ?>
                    <?php $this->color_field( 'frontend_text', __( 'Text color', 'techzu-rewards' ), $settings['frontend_text'] ); ?>
                    <?php $this->color_field( 'frontend_muted', __( 'Muted text color', 'techzu-rewards' ), $settings['frontend_muted'] ); ?>
                    <?php $this->number_field( 'frontend_border_radius', __( 'Border radius', 'techzu-rewards' ), $settings['frontend_border_radius'], '1' ); ?>
                    <?php $this->number_field( 'frontend_max_width', __( 'Programme max width', 'techzu-rewards' ), $settings['frontend_max_width'], '1' ); ?>
                </section>

                <section class="tz-rewards-panel">
                    <h2><?php esc_html_e( 'Advanced and shortcodes', 'techzu-rewards' ); ?></h2>
                    <?php $this->checkbox_field( 'debug_log', __( 'Enable WooCommerce logger events', 'techzu-rewards' ), $settings['debug_log'] ); ?>
                    <div class="tz-rewards-preview">
                        <h3><?php esc_html_e( 'Use these with Elementor', 'techzu-rewards' ); ?></h3>
                        <code>[tz_rewards_program]</code>
                        <code>[tz_rewards_dashboard]</code>
                        <code>[tz_rewards_balance]</code>
                        <code>[tz_rewards_checkout_controls]</code>
                        <p><?php esc_html_e( 'Elementor can use the shortcode widget, and this plugin also registers a native ElegantBliss Rewards widget when Elementor is active.', 'techzu-rewards' ); ?></p>
                    </div>
                </section>
            </div>

            <?php submit_button( __( 'Save Rewards Settings', 'techzu-rewards' ) ); ?>
        </form>
        <?php
    }

    /**
     * Render customers page.
     *
     * @return void
     */
    protected function render_customers_page() {
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $args   = array(
            'number'  => 20,
            'orderby' => 'registered',
            'order'   => 'DESC',
        );

        if ( '' !== $search ) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
        }

        $query = new \WP_User_Query( $args );
        $users = $query->get_results();
        ?>
        <section class="tz-rewards-panel tz-rewards-panel--wide">
            <h2><?php esc_html_e( 'Customer reward control', 'techzu-rewards' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Search customers, set exact point balances, edit birthdays, and force a tier override. Leaving tier as Automatic uses the 12-month spend rule.', 'techzu-rewards' ); ?></p>
            <form method="get" class="tz-rewards-customer-search">
                <input type="hidden" name="page" value="tz-rewards-customers">
                <label class="screen-reader-text" for="tz-rewards-customer-search-input"><?php esc_html_e( 'Search customers', 'techzu-rewards' ); ?></label>
                <input id="tz-rewards-customer-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name or email', 'techzu-rewards' ); ?>">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'techzu-rewards' ); ?></button>
            </form>

            <div class="tz-rewards-customer-list">
                <?php if ( empty( $users ) ) : ?>
                    <p><?php esc_html_e( 'No customers found.', 'techzu-rewards' ); ?></p>
                <?php endif; ?>
                <?php foreach ( $users as $user ) : ?>
                    <?php
                    $user_id   = (int) $user->ID;
                    $balance   = $this->points_manager->get_balance( $user_id );
                    $tier      = $this->tier_manager->get_customer_tier( $user_id );
                    $spend     = $this->tier_manager->get_customer_eligible_spend( $user_id );
                    $birthday  = $this->tier_manager->get_birthday( $user_id );
                    $manual    = $this->tier_manager->get_manual_tier( $user_id );
                    $log       = $this->points_manager->get_log( $user_id, 8 );
                    ?>
                    <div class="tz-rewards-customer-card">
                        <div class="tz-rewards-customer-card__main">
                            <div>
                                <h3><?php echo esc_html( $user->display_name ? $user->display_name : $user->user_login ); ?></h3>
                                <p><?php echo esc_html( $user->user_email ); ?> - <?php echo esc_html( sprintf( __( 'User ID: %d', 'techzu-rewards' ), $user_id ) ); ?></p>
                            </div>
                            <div class="tz-rewards-customer-card__stats">
                                <span><strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong><?php esc_html_e( 'Points', 'techzu-rewards' ); ?></span>
                                <span><strong><?php echo esc_html( $tier['name'] ); ?></strong><?php esc_html_e( 'Tier', 'techzu-rewards' ); ?></span>
                                <span><strong><?php echo esc_html( wp_strip_all_tags( wc_price( $spend ) ) ); ?></strong><?php esc_html_e( '12-month spend', 'techzu-rewards' ); ?></span>
                            </div>
                        </div>
                        <form method="post" class="tz-rewards-customer-card__form">
                            <?php wp_nonce_field( 'tz_rewards_update_customer' ); ?>
                            <input type="hidden" name="tz_rewards_customer_action" value="update">
                            <input type="hidden" name="tz_rewards_customer_user_id" value="<?php echo esc_attr( $user_id ); ?>">
                            <label><?php esc_html_e( 'Exact point balance', 'techzu-rewards' ); ?><input type="number" min="0" step="1" name="tz_rewards_customer_balance" value="<?php echo esc_attr( $balance ); ?>"></label>
                            <label><?php esc_html_e( 'Birthday', 'techzu-rewards' ); ?><input type="date" name="tz_rewards_customer_birthday" value="<?php echo esc_attr( $birthday ); ?>"></label>
                            <label><?php esc_html_e( 'Manual tier', 'techzu-rewards' ); ?>
                                <select name="tz_rewards_customer_manual_tier">
                                    <?php foreach ( $this->tier_manager->get_tier_options( true ) as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $manual, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save customer rewards', 'techzu-rewards' ); ?></button>
                        </form>
                        <details class="tz-rewards-customer-card__history">
                            <summary><?php esc_html_e( 'Recent point history', 'techzu-rewards' ); ?></summary>
                            <?php if ( empty( $log ) ) : ?>
                                <p><?php esc_html_e( 'No reward activity yet.', 'techzu-rewards' ); ?></p>
                            <?php else : ?>
                                <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Type', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Points', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Note', 'techzu-rewards' ); ?></th></tr></thead><tbody>
                                <?php foreach ( $log as $entry ) : ?>
                                    <tr><td><?php echo esc_html( isset( $entry['date'] ) ? $entry['date'] : '' ); ?></td><td><?php echo esc_html( isset( $entry['type'] ) ? $entry['type'] : '' ); ?></td><td><?php echo esc_html( isset( $entry['points'] ) ? (int) $entry['points'] : 0 ); ?></td><td><?php echo esc_html( isset( $entry['note'] ) ? $entry['note'] : '' ); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Render Guide app.
     *
     * @return void
     */
    protected function render_guide_page() {
        ?>
        <section class="tz-rewards-panel tz-rewards-panel--wide tz-rewards-guide">
            <h2><?php esc_html_e( 'Guide', 'techzu-rewards' ); ?></h2>
            <p><?php esc_html_e( 'This documentation ships inside the plugin so admins and developers can understand how every feature works without leaving WordPress.', 'techzu-rewards' ); ?></p>

            <div class="tz-rewards-guide-grid">
                <article>
                    <h3><?php esc_html_e( '1. Client screenshot match', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'The public programme page is available with [tz_rewards_program]. It includes the branded header, earning banner, reward tiers, membership tiers, birthday-perk note, FAQs and terms note. Edit every label and row from Settings.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '2. Points earning', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'Points are awarded when an order reaches the selected earning statuses. The eligible amount uses paid product line totals after product discounts and excludes shipping. Negative reward/discount fees can also be subtracted.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '3. Point expiry', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'Every earn creates a point lot with its own expiry date. Redemptions consume the oldest active lots first. The customer dashboard shows remaining lots and expiry dates.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '4. Reward redemption', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'Customers can apply a reward voucher at cart or checkout. In continuous mode, every 150 Bliss Points gives S$5 off and larger vouchers keep increasing in the same step. Fixed-tier mode is also available. Redeemed points are deducted when checkout creates the order and restored if the order is cancelled, failed or refunded.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '5. Membership tiers', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'Tier qualification uses eligible customer spend during the configured rolling month window. Admins can add, edit, disable or remove tiers and can force a manual customer tier override.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '6. Birthday discounts', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'Customers save a birthday in My Account. During the birthday month, the current tier controls the percentage discount. The discount is limited to one eligible order and can require a minimum spend.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '7. Non-stacking rule', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'When enabled, the plugin prevents combining reward vouchers, birthday discounts and coupon/promo codes on the same order.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '8. My Account and Elementor', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'Customers get a Rewards tab in My Account with balance, how-to-earn guidance, tier progress, birthday perk, vouchers, expiry lots and history. Elementor works through shortcodes and the native ElegantBliss Rewards widget when Elementor is active.', 'techzu-rewards' ); ?></p>
                </article>
                <article>
                    <h3><?php esc_html_e( '9. Emails and notifications', 'techzu-rewards' ); ?></h3>
                    <p><?php esc_html_e( 'The plugin can email customers after account creation, point earning, point use, membership tier updates, manual admin balance changes, points expiring soon and points expired. Subjects, intro copy, footer text and each email type are editable from Settings.', 'techzu-rewards' ); ?></p>
                </article>
            </div>

            <h3><?php esc_html_e( 'Shortcodes', 'techzu-rewards' ); ?></h3>
            <ul>
                <li><code>[tz_rewards_program]</code> - <?php esc_html_e( 'public rewards programme page', 'techzu-rewards' ); ?></li>
                <li><code>[tz_rewards_dashboard]</code> - <?php esc_html_e( 'logged-in customer reward dashboard', 'techzu-rewards' ); ?></li>
                <li><code>[tz_rewards_balance]</code> - <?php esc_html_e( 'simple current balance text', 'techzu-rewards' ); ?></li>
                <li><code>[tz_rewards_checkout_controls]</code> - <?php esc_html_e( 'reward and birthday controls for custom/Elementor checkout layouts', 'techzu-rewards' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Developer hooks', 'techzu-rewards' ); ?></h3>
            <p><code>tz_rewards_calculated_points</code>, <code>tz_rewards_available_redemptions</code>, <code>tz_rewards_eligible_subtotal</code>, <code>tz_rewards_customer_tier_spend</code>, <code>tz_rewards_cart_eligible_product_total</code></p>
        </section>
        <?php
    }

    /**
     * Render user profile reward controls.
     *
     * @param \WP_User $user User object.
     * @return void
     */
    public function render_user_profile_fields( $user ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $balance  = $this->points_manager->get_balance( $user->ID );
        $birthday = $this->tier_manager->get_birthday( $user->ID );
        $manual   = $this->tier_manager->get_manual_tier( $user->ID );
        ?>
        <h2><?php esc_html_e( 'ElegantBliss Rewards', 'techzu-rewards' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr><th><label for="tz_rewards_profile_balance"><?php esc_html_e( 'Point balance', 'techzu-rewards' ); ?></label></th><td><input type="number" min="0" step="1" name="tz_rewards_profile_balance" id="tz_rewards_profile_balance" value="<?php echo esc_attr( $balance ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="tz_rewards_profile_birthday"><?php esc_html_e( 'Birthday', 'techzu-rewards' ); ?></label></th><td><input type="date" name="tz_rewards_profile_birthday" id="tz_rewards_profile_birthday" value="<?php echo esc_attr( $birthday ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="tz_rewards_profile_manual_tier"><?php esc_html_e( 'Manual tier', 'techzu-rewards' ); ?></label></th><td><select name="tz_rewards_profile_manual_tier" id="tz_rewards_profile_manual_tier">
                <?php foreach ( $this->tier_manager->get_tier_options( true ) as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $manual, $value ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select></td></tr>
        </table>
        <?php
    }

    /**
     * Save user profile fields.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function save_user_profile_fields( $user_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        if ( isset( $_POST['tz_rewards_profile_balance'] ) ) {
            $this->points_manager->set_balance( $user_id, absint( wp_unslash( $_POST['tz_rewards_profile_balance'] ) ), __( 'Admin user profile update', 'techzu-rewards' ) );
        }
        if ( isset( $_POST['tz_rewards_profile_birthday'] ) ) {
            $this->tier_manager->set_birthday( $user_id, sanitize_text_field( wp_unslash( $_POST['tz_rewards_profile_birthday'] ) ) );
        }
        if ( isset( $_POST['tz_rewards_profile_manual_tier'] ) ) {
            $this->tier_manager->set_manual_tier( $user_id, sanitize_key( wp_unslash( $_POST['tz_rewards_profile_manual_tier'] ) ) );
        }
    }

    /** Field helpers. */
    protected function text_field( $key, $label, $value ) {
        ?>
        <div class="tz-rewards-field"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><input id="<?php echo esc_attr( $key ); ?>" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text"></div>
        <?php
    }

    protected function textarea_field( $key, $label, $value ) {
        ?>
        <div class="tz-rewards-field"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><textarea id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" rows="3" class="large-text"><?php echo esc_textarea( $value ); ?></textarea></div>
        <?php
    }

    protected function checkbox_field( $key, $label, $value ) {
        ?>
        <div class="tz-rewards-field tz-rewards-field--checkbox"><label><input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="no"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( 'yes', $value ); ?>><?php echo esc_html( $label ); ?></label></div>
        <?php
    }

    protected function number_field( $key, $label, $value, $step ) {
        ?>
        <div class="tz-rewards-field"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><input id="<?php echo esc_attr( $key ); ?>" type="number" min="0" step="<?php echo esc_attr( $step ); ?>" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="small-text"></div>
        <?php
    }

    protected function select_field( $key, $label, $value, $options ) {
        ?>
        <div class="tz-rewards-field"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" class="regular-text"><?php foreach ( $options as $option_value => $option_label ) : ?><option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option><?php endforeach; ?></select></div>
        <?php
    }

    protected function color_field( $key, $label, $value ) {
        ?>
        <div class="tz-rewards-field"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><input id="<?php echo esc_attr( $key ); ?>" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="#111827"></div>
        <?php
    }

    protected function status_checkboxes( $key, $label, $values, $order_statuses ) {
        ?>
        <div class="tz-rewards-field"><label><?php echo esc_html( $label ); ?></label><div class="tz-rewards-checkbox-list">
            <?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
                <?php $slug = str_replace( 'wc-', '', $status_key ); ?>
                <label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $values, true ) ); ?>><?php echo esc_html( $status_label ); ?></label>
            <?php endforeach; ?>
        </div></div>
        <?php
    }

    protected function render_redemption_repeater( $tiers ) {
        ?>
        <input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[redemption_tiers_present]" value="yes">
        <div class="tz-repeater" data-repeater data-next-index="<?php echo esc_attr( count( $tiers ) ); ?>"><table class="widefat striped tz-repeater__table"><thead><tr><th><?php esc_html_e( 'Enabled', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Required points', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Voucher discount', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Display label', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Action', 'techzu-rewards' ); ?></th></tr></thead><tbody data-repeater-body><?php foreach ( $tiers as $index => $tier ) : $this->render_redemption_row( $index, $tier ); endforeach; ?></tbody></table><p><button type="button" class="button button-secondary" data-repeater-add="redemption"><?php esc_html_e( 'Add reward tier', 'techzu-rewards' ); ?></button></p><template data-repeater-template="redemption"><?php $this->render_redemption_row( '__index__', array( 'enabled' => 'yes', 'points' => '', 'voucher' => '', 'label' => '' ) ); ?></template></div>
        <?php
    }

    protected function render_redemption_row( $index, $tier ) {
        ?>
        <tr data-repeater-row><td><input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[redemption_tiers][<?php echo esc_attr( $index ); ?>][enabled]" value="no"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[redemption_tiers][<?php echo esc_attr( $index ); ?>][enabled]" value="yes" <?php checked( 'yes', isset( $tier['enabled'] ) ? $tier['enabled'] : 'yes' ); ?>></td><td><input type="number" min="0" step="1" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[redemption_tiers][<?php echo esc_attr( $index ); ?>][points]" value="<?php echo esc_attr( $tier['points'] ); ?>" class="small-text"></td><td><input type="number" min="0" step="0.01" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[redemption_tiers][<?php echo esc_attr( $index ); ?>][voucher]" value="<?php echo esc_attr( $tier['voucher'] ); ?>" class="small-text"></td><td><input type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[redemption_tiers][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( isset( $tier['label'] ) ? $tier['label'] : '' ); ?>"></td><td><button type="button" class="button-link-delete" data-repeater-remove><?php esc_html_e( 'Remove', 'techzu-rewards' ); ?></button></td></tr>
        <?php
    }

    protected function render_membership_repeater( $tiers ) {
        ?>
        <input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers_present]" value="yes">
        <div class="tz-repeater" data-repeater data-next-index="<?php echo esc_attr( count( $tiers ) ); ?>"><table class="widefat striped tz-repeater__table"><thead><tr><th><?php esc_html_e( 'Enabled', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Key', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Tier name', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Qualification text', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Spend threshold', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Birthday %', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Action', 'techzu-rewards' ); ?></th></tr></thead><tbody data-repeater-body><?php foreach ( $tiers as $index => $tier ) : $this->render_membership_row( $index, $tier ); endforeach; ?></tbody></table><p><button type="button" class="button button-secondary" data-repeater-add="membership"><?php esc_html_e( 'Add membership tier', 'techzu-rewards' ); ?></button></p><template data-repeater-template="membership"><?php $this->render_membership_row( '__index__', array( 'enabled' => 'yes', 'key' => '', 'name' => '', 'qualification' => '', 'spend_threshold' => '', 'birthday_discount' => '' ) ); ?></template></div>
        <?php
    }

    protected function render_membership_row( $index, $tier ) {
        ?>
        <tr data-repeater-row><td><input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][enabled]" value="no"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][enabled]" value="yes" <?php checked( 'yes', isset( $tier['enabled'] ) ? $tier['enabled'] : 'yes' ); ?>></td><td><input type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $tier['key'] ); ?>"></td><td><input type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $tier['name'] ); ?>"></td><td><input type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][qualification]" value="<?php echo esc_attr( $tier['qualification'] ); ?>"></td><td><input type="number" min="0" step="0.01" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][spend_threshold]" value="<?php echo esc_attr( $tier['spend_threshold'] ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.01" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[membership_tiers][<?php echo esc_attr( $index ); ?>][birthday_discount]" value="<?php echo esc_attr( $tier['birthday_discount'] ); ?>" class="small-text"></td><td><button type="button" class="button-link-delete" data-repeater-remove><?php esc_html_e( 'Remove', 'techzu-rewards' ); ?></button></td></tr>
        <?php
    }

    protected function render_faq_repeater( $items ) {
        ?>
        <input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[faq_items_present]" value="yes">
        <h3><?php esc_html_e( 'FAQ items', 'techzu-rewards' ); ?></h3><div class="tz-repeater" data-repeater data-next-index="<?php echo esc_attr( count( $items ) ); ?>"><table class="widefat striped tz-repeater__table"><thead><tr><th><?php esc_html_e( 'Question', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Answer', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Action', 'techzu-rewards' ); ?></th></tr></thead><tbody data-repeater-body><?php foreach ( $items as $index => $item ) : $this->render_faq_row( $index, $item ); endforeach; ?></tbody></table><p><button type="button" class="button button-secondary" data-repeater-add="faq"><?php esc_html_e( 'Add FAQ', 'techzu-rewards' ); ?></button></p><template data-repeater-template="faq"><?php $this->render_faq_row( '__index__', array( 'question' => '', 'answer' => '' ) ); ?></template></div>
        <?php
    }

    protected function render_faq_row( $index, $item ) {
        ?>
        <tr data-repeater-row><td><input type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[faq_items][<?php echo esc_attr( $index ); ?>][question]" value="<?php echo esc_attr( $item['question'] ); ?>"></td><td><textarea rows="2" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[faq_items][<?php echo esc_attr( $index ); ?>][answer]"><?php echo esc_textarea( $item['answer'] ); ?></textarea></td><td><button type="button" class="button-link-delete" data-repeater-remove><?php esc_html_e( 'Remove', 'techzu-rewards' ); ?></button></td></tr>
        <?php
    }
}
