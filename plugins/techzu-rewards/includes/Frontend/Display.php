<?php
namespace Techzu\Rewards\Frontend;

use Techzu\Rewards\Rewards\Birthday_Discount_Manager;
use Techzu\Rewards\Rewards\Calculator;
use Techzu\Rewards\Rewards\Order_Manager;
use Techzu\Rewards\Rewards\Points_Manager;
use Techzu\Rewards\Rewards\Redemption_Manager;
use Techzu\Rewards\Rewards\Tier_Manager;
use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Display {
    /**
     * Settings instance.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Points manager.
     *
     * @var Points_Manager
     */
    protected $points_manager;

    /**
     * Calculator.
     *
     * @var Calculator
     */
    protected $calculator;

    /**
     * Redemption manager.
     *
     * @var Redemption_Manager
     */
    protected $redemption_manager;

    /**
     * Tier manager.
     *
     * @var Tier_Manager
     */
    protected $tier_manager;

    /**
     * Birthday discount manager.
     *
     * @var Birthday_Discount_Manager
     */
    protected $birthday_manager;

    /**
     * Constructor.
     *
     * @param Settings                  $settings Settings object.
     * @param Points_Manager            $points_manager Points manager.
     * @param Calculator                $calculator Calculator.
     * @param Redemption_Manager        $redemption_manager Redemption manager.
     * @param Tier_Manager              $tier_manager Tier manager.
     * @param Birthday_Discount_Manager $birthday_manager Birthday manager.
     */
    public function __construct( Settings $settings, Points_Manager $points_manager, Calculator $calculator, Redemption_Manager $redemption_manager, Tier_Manager $tier_manager, Birthday_Discount_Manager $birthday_manager ) {
        $this->settings           = $settings;
        $this->points_manager     = $points_manager;
        $this->calculator         = $calculator;
        $this->redemption_manager = $redemption_manager;
        $this->tier_manager       = $tier_manager;
        $this->birthday_manager   = $birthday_manager;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'init', array( $this, 'register_account_endpoint' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_item' ) );
        add_action( 'woocommerce_account_rewards_endpoint', array( $this, 'render_account_rewards_endpoint' ) );
        add_action( 'woocommerce_edit_account_form', array( $this, 'render_birthday_account_field' ) );
        add_action( 'woocommerce_save_account_details', array( $this, 'save_birthday_account_field' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'woocommerce_get_price_html', array( $this, 'append_loop_points_hint' ), 20, 2 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_product_hint' ), 31 );
        add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'render_checkout_rewards_panel' ) );
        add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_checkout_rewards_panel' ) );
        add_action( 'woocommerce_before_my_account', array( $this, 'render_account_summary' ) );
        add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_summary' ), 10, 4 );

        add_shortcode( 'tz_rewards_program', array( $this, 'render_program_shortcode' ) );
        add_shortcode( 'tz_rewards_dashboard', array( $this, 'render_dashboard_shortcode' ) );
        add_shortcode( 'tz_rewards_balance', array( $this, 'render_balance_shortcode' ) );
        add_shortcode( 'tz_rewards_checkout_controls', array( $this, 'render_checkout_controls_shortcode' ) );
    }

    /**
     * Register My Account rewards endpoint.
     *
     * @return void
     */
    public function register_account_endpoint() {
        if ( 'yes' !== $this->settings->get( 'show_my_account_endpoint', 'yes' ) ) {
            return;
        }

        add_rewrite_endpoint( 'rewards', EP_ROOT | EP_PAGES );
    }

    /**
     * Register query var.
     *
     * @param array<int,string> $vars Existing vars.
     * @return array<int,string>
     */
    public function register_query_vars( $vars ) {
        $vars[] = 'rewards';
        return $vars;
    }

    /**
     * Add rewards item to My Account menu.
     *
     * @param array<string,string> $items Menu items.
     * @return array<string,string>
     */
    public function add_account_menu_item( $items ) {
        if ( 'yes' !== $this->settings->get( 'show_my_account_endpoint', 'yes' ) ) {
            return $items;
        }

        $new_items = array();
        foreach ( $items as $key => $label ) {
            if ( 'customer-logout' === $key ) {
                $new_items['rewards'] = __( 'Rewards', 'techzu-rewards' );
            }
            $new_items[ $key ] = $label;
        }

        if ( ! isset( $new_items['rewards'] ) ) {
            $new_items['rewards'] = __( 'Rewards', 'techzu-rewards' );
        }

        return $new_items;
    }

    /**
     * Enqueue frontend assets.
     *
     * @return void
     */
    public function enqueue_assets() {
        if ( is_admin() || ! $this->is_enabled() ) {
            return;
        }

        $should_load = false;

        if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
            $should_load = true;
        }

        if ( function_exists( 'is_cart' ) && is_cart() ) {
            $should_load = true;
        }

        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            $should_load = true;
        }

        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            $should_load = true;
        }

        if ( ! $should_load && is_singular() ) {
            global $post;
            if ( $post instanceof \WP_Post && ( has_shortcode( $post->post_content, 'tz_rewards_program' ) || has_shortcode( $post->post_content, 'tz_rewards_dashboard' ) || has_shortcode( $post->post_content, 'tz_rewards_balance' ) || has_shortcode( $post->post_content, 'tz_rewards_checkout_controls' ) ) ) {
                $should_load = true;
            }
        }

        if ( ! $should_load ) {
            return;
        }

        wp_enqueue_style(
            'tz-rewards-frontend',
            TZ_REWARDS_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            TZ_REWARDS_VERSION
        );

        wp_enqueue_script(
            'tz-rewards-frontend',
            TZ_REWARDS_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            TZ_REWARDS_VERSION,
            true
        );

        $custom_css = sprintf(
            ':root{--tz-rewards-accent:%1$s;--tz-rewards-background:%2$s;--tz-rewards-surface:%3$s;--tz-rewards-border:%4$s;--tz-rewards-text:%5$s;--tz-rewards-muted:%6$s;--tz-rewards-radius:%7$dpx;--tz-rewards-max-width:%8$dpx;}',
            esc_html( $this->settings->get( 'frontend_accent', '#2b2118' ) ),
            esc_html( $this->settings->get( 'frontend_background', '#ffffff' ) ),
            esc_html( $this->settings->get( 'frontend_surface', '#f7f5f0' ) ),
            esc_html( $this->settings->get( 'frontend_border', '#d9d5cc' ) ),
            esc_html( $this->settings->get( 'frontend_text', '#2f2a24' ) ),
            esc_html( $this->settings->get( 'frontend_muted', '#6f675e' ) ),
            (int) $this->settings->get( 'frontend_border_radius', 14 ),
            (int) $this->settings->get( 'frontend_max_width', 1080 )
        );

        wp_add_inline_style( 'tz-rewards-frontend', $custom_css );
    }

    /**
     * Append points hints in loop prices.
     *
     * @param string      $price_html Existing price HTML.
     * @param \WC_Product $product Product object.
     * @return string
     */
    public function append_loop_points_hint( $price_html, $product ) {
        if ( 'yes' !== $this->settings->get( 'show_catalog_hint', 'yes' ) || ! $product instanceof \WC_Product ) {
            return $price_html;
        }

        $amount = $this->calculator->get_product_reference_price( $product );
        $points = $this->calculator->calculate_points_for_amount( $amount );

        if ( $points <= 0 ) {
            return $price_html;
        }

        $hint = sprintf(
            '<br><span class="tz-rewards-inline-hint">%s</span>',
            esc_html( sprintf( __( 'Earn about %1$d %2$s', 'techzu-rewards' ), $points, $this->format_points_label( $points ) ) )
        );

        return $price_html . $hint;
    }

    /**
     * Render the single product rewards box.
     *
     * @return void
     */
    public function render_single_product_hint() {
        global $product;

        if ( 'yes' !== $this->settings->get( 'show_catalog_hint', 'yes' ) || ! $product instanceof \WC_Product ) {
            return;
        }

        $points = $this->calculator->calculate_points_for_amount( $this->calculator->get_product_reference_price( $product ) );
        if ( $points <= 0 ) {
            return;
        }

        echo '<div class="tz-rewards-card tz-rewards-card--compact">';
        echo '<h4 class="tz-rewards-card__title">' . esc_html__( 'Rewards estimate', 'techzu-rewards' ) . '</h4>';
        echo '<p class="tz-rewards-card__text">';
        echo esc_html( sprintf( __( 'This item can earn about %1$d %2$s based on the current rules.', 'techzu-rewards' ), $points, $this->format_points_label( $points ) ) );
        echo '</p>';
        echo '</div>';
    }

    /**
     * Render combined checkout rewards panels.
     *
     * @return void
     */
    public function render_checkout_rewards_panel() {
        if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $available = $this->redemption_manager->get_available_redemptions_for_current_user( false );
        $coupons   = $this->redemption_manager->get_user_reward_coupons( get_current_user_id() );
        if ( empty( $available ) && empty( $coupons ) ) {
            return;
        }

        ob_start();
        echo '<div class="tz-rewards-checkout-stack">';
        $this->render_redemption_panel_inner();
        $this->render_birthday_panel_inner();
        echo '</div>';
        $html = (string) ob_get_clean();

        echo '<tr class="tz-rewards-checkout-row"><td colspan="2">';
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</td></tr>';
    }

    /**
     * Render redemption panel body.
     *
     * @return void
     */
    protected function render_redemption_panel_inner() {
        $balance    = $this->points_manager->get_balance( get_current_user_id() );
        $available  = $this->redemption_manager->get_available_redemptions_for_current_user( false );
        $coupons    = $this->redemption_manager->get_user_reward_coupons( get_current_user_id() );
        $point_name = $this->settings->get( 'points_label', 'Bliss Points' );

        if ( empty( $available ) && empty( $coupons ) ) {
            return;
        }

        echo '<div class="tz-rewards-card tz-rewards-card--checkout">';
        echo '<div class="tz-rewards-card__header">';
        echo '<h3 class="tz-rewards-card__title">' . esc_html__( 'Reward coupon', 'techzu-rewards' ) . '</h3>';
        echo '<span class="tz-rewards-badge">' . esc_html( sprintf( __( '%1$d %2$s available', 'techzu-rewards' ), $balance, $point_name ) ) . '</span>';
        echo '</div>';

        if ( ! empty( $available ) ) {
            echo '<p class="tz-rewards-card__text tz-rewards-card__text--strong">' . esc_html__( 'You got points. You can convert them into a coupon.', 'techzu-rewards' ) . '</p>';
            $this->render_coupon_conversion_form( $available, esc_html__( 'Convert to coupon', 'techzu-rewards' ), true );
            echo '<p class="tz-rewards-card__note">' . esc_html__( 'Converted coupons are not added automatically. Copy the coupon code and apply it manually in the WooCommerce coupon field when you want to use it.', 'techzu-rewards' ) . '</p>';
        }

        $this->render_user_coupon_codes( $coupons, true );
        echo '</div>';
    }

    /**
     * Render birthday discount panel body.
     *
     * @return void
     */
    protected function render_birthday_panel_inner() {
        if ( 'yes' !== $this->settings->get( 'birthday_enabled', 'yes' ) ) {
            return;
        }

        $eligibility = $this->birthday_manager->get_current_user_eligibility();
        $active      = $this->birthday_manager->get_active_discount();
        $tier        = $this->tier_manager->get_customer_tier( get_current_user_id() );
        $percent     = isset( $tier['birthday_discount'] ) ? (float) $tier['birthday_discount'] : 0;

        echo '<div class="tz-rewards-card tz-rewards-card--checkout">';
        echo '<div class="tz-rewards-card__header">';
        echo '<h3 class="tz-rewards-card__title">' . esc_html__( 'Birthday perk', 'techzu-rewards' ) . '</h3>';
        echo '<span class="tz-rewards-badge">' . esc_html( sprintf( __( '%1$s: %2$s%% birthday discount', 'techzu-rewards' ), $tier['name'], wc_format_decimal( $percent, 0 ) ) ) . '</span>';
        echo '</div>';

        if ( ! empty( $active['percent'] ) ) {
            echo '<p class="tz-rewards-card__text tz-rewards-card__text--strong">';
            echo esc_html( sprintf( __( 'Birthday discount applied: %s%% off eligible products.', 'techzu-rewards' ), wc_format_decimal( (float) $active['percent'], 0 ) ) );
            echo '</p>';
            echo '<form method="post" class="tz-rewards-form tz-rewards-form--secondary">';
            wp_nonce_field( Birthday_Discount_Manager::NONCE_ACTION );
            echo '<input type="hidden" name="tz_rewards_birthday_action" value="remove_birthday_discount">';
            echo '<button type="submit" class="button tz-rewards-form__button">' . esc_html__( 'Remove birthday discount', 'techzu-rewards' ) . '</button>';
            echo '</form>';
        } elseif ( ! empty( $eligibility['eligible'] ) ) {
            echo '<p class="tz-rewards-card__text">' . esc_html__( 'Your birthday discount is available for this order.', 'techzu-rewards' ) . '</p>';
            echo '<form method="post" class="tz-rewards-form">';
            wp_nonce_field( Birthday_Discount_Manager::NONCE_ACTION );
            echo '<input type="hidden" name="tz_rewards_birthday_action" value="apply_birthday_discount">';
            echo '<button type="submit" class="button alt tz-rewards-form__button">' . esc_html__( 'Apply birthday discount', 'techzu-rewards' ) . '</button>';
            echo '</form>';
        } else {
            echo '<p class="tz-rewards-card__text">' . esc_html( $eligibility['reason'] ) . '</p>';
        }

        echo '<p class="tz-rewards-card__note">' . esc_html( $this->settings->get( 'birthday_terms', '' ) ) . '</p>';
        echo '</div>';
    }

    /**
     * Render My Account summary card.
     *
     * @return void
     */
    public function render_account_summary() {
        if ( 'yes' !== $this->settings->get( 'show_account_summary', 'yes' ) || ! is_user_logged_in() ) {
            return;
        }

        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'rewards' ) ) {
            return;
        }

        $user_id    = get_current_user_id();
        $balance    = $this->points_manager->get_balance( $user_id );
        $tier       = $this->tier_manager->get_customer_tier( $user_id );
        $point_name = $this->settings->get( 'points_label', 'Bliss Points' );

        echo '<div class="tz-rewards-card tz-rewards-account">';
        echo '<div class="tz-rewards-card__header">';
        echo '<h3 class="tz-rewards-card__title">' . esc_html__( 'My rewards', 'techzu-rewards' ) . '</h3>';
        echo '<span class="tz-rewards-badge">' . esc_html( sprintf( __( '%1$d %2$s', 'techzu-rewards' ), $balance, $point_name ) ) . '</span>';
        echo '</div>';
        echo '<p class="tz-rewards-card__text">' . esc_html( sprintf( __( 'Current tier: %s. Open the Rewards tab to view vouchers, birthday perks, point expiry and history.', 'techzu-rewards' ), $tier['name'] ) ) . '</p>';
        if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
            echo '<p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'rewards' ) ) . '">' . esc_html__( 'View Rewards', 'techzu-rewards' ) . '</a></p>';
        }
        echo '</div>';
    }

    /**
     * Render the account endpoint.
     *
     * @return void
     */
    public function render_account_rewards_endpoint() {
        echo $this->get_dashboard_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Add birthday field to account edit form.
     *
     * @return void
     */
    public function render_birthday_account_field() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $birthday = $this->tier_manager->get_birthday( get_current_user_id() );
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="tz_rewards_birthday"><?php esc_html_e( 'Birthday', 'techzu-rewards' ); ?></label>
            <input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="tz_rewards_birthday" id="tz_rewards_birthday" value="<?php echo esc_attr( $birthday ); ?>">
            <span><em><?php esc_html_e( 'Used only to unlock birthday-month rewards.', 'techzu-rewards' ); ?></em></span>
        </p>
        <?php
    }

    /**
     * Save birthday from account edit form.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function save_birthday_account_field( $user_id ) {
        if ( ! isset( $_POST['tz_rewards_birthday'] ) ) {
            return;
        }

        $this->tier_manager->set_birthday( $user_id, wc_clean( wp_unslash( $_POST['tz_rewards_birthday'] ) ) );
    }

    /**
     * Add rewards summary to customer emails.
     *
     * @param \WC_Order $order Order.
     * @param bool      $sent_to_admin Sent to admin flag.
     * @param bool      $plain_text Plain text flag.
     * @param mixed     $email Email object.
     * @return void
     */
    public function render_email_summary( $order, $sent_to_admin, $plain_text, $email ) {
        unset( $email );

        if ( 'yes' !== $this->settings->get( 'show_email_summary', 'yes' ) || $sent_to_admin || ! $order instanceof \WC_Order ) {
            return;
        }

        $awarded   = (int) $order->get_meta( Order_Manager::ORDER_META_AWARDED, true );
        $redeemed  = (int) $order->get_meta( Redemption_Manager::ORDER_META_POINTS, true );
        $birthday  = (float) $order->get_meta( Birthday_Discount_Manager::ORDER_META_DISCOUNT, true );

        if ( $awarded <= 0 && $redeemed <= 0 && $birthday <= 0 ) {
            return;
        }

        if ( $plain_text ) {
            if ( $awarded > 0 ) {
                echo "\n" . sprintf( __( 'Reward points earned: %d', 'techzu-rewards' ), $awarded ) . "\n";
            }
            if ( $redeemed > 0 ) {
                echo sprintf( __( 'Reward points redeemed: %d', 'techzu-rewards' ), $redeemed ) . "\n";
            }
            if ( $birthday > 0 ) {
                echo sprintf( __( 'Birthday discount used: %s', 'techzu-rewards' ), wp_strip_all_tags( wc_price( $birthday ) ) ) . "\n";
            }
            return;
        }

        echo '<div class="tz-rewards-card tz-rewards-card--email">';
        echo '<h3 class="tz-rewards-card__title">' . esc_html__( 'Rewards summary', 'techzu-rewards' ) . '</h3>';
        if ( $awarded > 0 ) {
            echo '<p class="tz-rewards-card__text">' . esc_html( sprintf( __( 'Points earned from this order: %d', 'techzu-rewards' ), $awarded ) ) . '</p>';
        }
        if ( $redeemed > 0 ) {
            echo '<p class="tz-rewards-card__text">' . esc_html( sprintf( __( 'Points redeemed on this order: %d', 'techzu-rewards' ), $redeemed ) ) . '</p>';
        }
        if ( $birthday > 0 ) {
            echo '<p class="tz-rewards-card__text">' . esc_html( sprintf( __( 'Birthday discount used: %s', 'techzu-rewards' ), wp_strip_all_tags( wc_price( $birthday ) ) ) ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Render the public rewards programme page shortcode.
     *
     * @return string
     */
    public function render_program_shortcode() {
        if ( 'yes' !== $this->settings->get( 'show_program_shortcode', 'yes' ) ) {
            return '';
        }

        ob_start();
        $this->render_program_page();
        return (string) ob_get_clean();
    }

    /**
     * Render the dashboard shortcode.
     *
     * @return string
     */
    public function render_dashboard_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to see your rewards dashboard.', 'techzu-rewards' ) . '</p>';
        }

        return $this->get_dashboard_html();
    }


    /**
     * Render checkout controls as a shortcode for Elementor or custom checkout pages.
     *
     * @return string
     */
    public function render_checkout_controls_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to use rewards at checkout.', 'techzu-rewards' ) . '</p>';
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return '<p>' . esc_html__( 'Rewards checkout controls are available when the WooCommerce cart is loaded.', 'techzu-rewards' ) . '</p>';
        }

        $available = $this->redemption_manager->get_available_redemptions_for_current_user( false );
        $coupons   = $this->redemption_manager->get_user_reward_coupons( get_current_user_id() );
        if ( empty( $available ) && empty( $coupons ) ) {
            return '';
        }

        ob_start();
        echo '<div class="tz-rewards-checkout-stack tz-rewards-checkout-stack--shortcode">';
        $this->render_redemption_panel_inner();
        $this->render_birthday_panel_inner();
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Render the balance shortcode.
     *
     * @return string
     */
    public function render_balance_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to see your balance.', 'techzu-rewards' ) . '</p>';
        }

        $balance = $this->points_manager->get_balance( get_current_user_id() );
        return esc_html( sprintf( __( '%1$d %2$s', 'techzu-rewards' ), $balance, $this->format_points_label( $balance ) ) );
    }

    /**
     * Render public program page matching the client screenshot.
     *
     * @return void
     */
    protected function render_program_page() {
        $redemptions = $this->calculator->get_redemption_display_tiers();
        $tiers       = $this->tier_manager->get_tiers();
        $faqs        = $this->settings->get( 'faq_items', array() );
        ?>
        <section class="tz-rewards-program" aria-label="<?php echo esc_attr( $this->settings->get( 'program_title', 'Rewards' ) ); ?>">
            <div class="tz-rewards-program__inner">
                <p class="tz-rewards-program__brand"><?php echo esc_html( $this->settings->get( 'program_brand_text', '' ) ); ?></p>
                <p class="tz-rewards-program__overline"><?php echo esc_html( $this->settings->get( 'program_overline', '' ) ); ?></p>
                <h2 class="tz-rewards-program__title"><?php echo esc_html( $this->settings->get( 'program_title', '' ) ); ?></h2>
                <p class="tz-rewards-program__tagline"><?php echo esc_html( $this->settings->get( 'program_tagline', '' ) ); ?></p>
                <div class="tz-rewards-program__earn-banner"><?php echo esc_html( $this->settings->get( 'program_earning_message', '' ) ); ?></div>

                <div class="tz-rewards-program__section-title"><strong><?php echo esc_html( $this->settings->get( 'rewards_section_title', 'Our Rewards' ) ); ?></strong><span><?php echo esc_html( $this->settings->get( 'rewards_section_subtitle', '' ) ); ?></span></div>
                <div class="tz-rewards-table-wrap">
                    <table class="tz-rewards-table">
                        <thead><tr><th><?php echo esc_html( $this->settings->get( 'points_label', 'Bliss Points' ) ); ?></th><th><?php esc_html_e( 'Reward', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'How It Works', 'techzu-rewards' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $redemptions as $tier ) : ?>
                            <tr>
                                <td><?php echo esc_html( sprintf( __( '%1$d %2$s', 'techzu-rewards' ), (int) $tier['points'], $this->settings->get( 'points_label', 'Bliss Points' ) ) ); ?></td>
                                <td><?php echo esc_html( $tier['label'] ? $tier['label'] : sprintf( __( '%s off', 'techzu-rewards' ), wp_strip_all_tags( wc_price( (float) $tier['voucher'] ) ) ) ); ?></td>
                                <td><?php echo esc_html( sprintf( __( 'Spend %s to unlock', 'techzu-rewards' ), wp_strip_all_tags( wc_price( (float) $tier['points'] / max( 0.0001, (float) $this->settings->get( 'points_per_dollar', 1 ) ) ) ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="tz-rewards-program__note"><?php esc_html_e( 'Points are awarded based on the final paid product amount, excluding delivery fees, discounts, vouchers, cancelled orders and refunded items.', 'techzu-rewards' ); ?></p>
                <?php if ( 'continuous' === $this->settings->get( 'redemption_mode', 'continuous' ) ) : ?>
                    <p class="tz-rewards-program__note"><?php echo esc_html( sprintf( __( 'Voucher conversion continues automatically: every %1$d %2$s gives %3$s off.', 'techzu-rewards' ), (int) $this->settings->get( 'redemption_step_points', 150 ), $this->settings->get( 'points_label', 'Bliss Points' ), wp_strip_all_tags( wc_price( (float) $this->settings->get( 'redemption_step_discount', 5 ) ) ) ) ); ?></p>
                <?php endif; ?>

                <div class="tz-rewards-program__section-title"><strong><?php echo esc_html( $this->settings->get( 'tiers_section_title', 'Membership Tiers' ) ); ?></strong><span><?php echo esc_html( $this->settings->get( 'tiers_section_subtitle', '' ) ); ?></span></div>
                <div class="tz-rewards-table-wrap">
                    <table class="tz-rewards-table">
                        <thead><tr><th><?php esc_html_e( 'Tier', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'How to Qualify', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Birthday Perk', 'techzu-rewards' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $tiers as $tier ) : ?>
                            <tr>
                                <td><?php echo esc_html( $tier['name'] ); ?></td>
                                <td><?php echo esc_html( $tier['qualification'] ); ?></td>
                                <td><?php echo esc_html( sprintf( __( '%s%% birthday discount', 'techzu-rewards' ), wc_format_decimal( (float) $tier['birthday_discount'], 0 ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="tz-rewards-program__note"><?php echo esc_html( $this->settings->get( 'birthday_terms', '' ) ); ?></p>

                <div class="tz-rewards-program__section-title"><strong><?php echo esc_html( $this->settings->get( 'faq_section_title', 'FAQs' ) ); ?></strong></div>
                <div class="tz-rewards-faq-grid">
                    <?php foreach ( $faqs as $faq ) : ?>
                        <div class="tz-rewards-faq-item">
                            <strong><?php echo esc_html( $faq['question'] ); ?></strong>
                            <span> <?php echo esc_html( $faq['answer'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="tz-rewards-program__terms"><?php echo esc_html( $this->settings->get( 'terms_note', '' ) ); ?></p>
            </div>
        </section>
        <?php
    }

    /**
     * Get full dashboard HTML.
     *
     * @return string
     */
    protected function get_dashboard_html() {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your rewards.', 'techzu-rewards' ) . '</p>';
        }

        $user_id     = get_current_user_id();
        $balance     = $this->points_manager->get_balance( $user_id );
        $tier        = $this->tier_manager->get_customer_tier( $user_id );
        $progress    = $this->tier_manager->get_next_tier_progress( $user_id );
        $available   = $this->calculator->get_available_redemptions( $balance );
        $lots        = $this->points_manager->get_lots_for_display( $user_id );
        $log         = $this->points_manager->get_log( $user_id, 12 );
        $birthday    = $this->tier_manager->get_birthday( $user_id );
        $eligibility = $this->birthday_manager->get_user_eligibility( $user_id );

        ob_start();
        ?>
        <div class="tz-rewards-dashboard">
            <div class="tz-rewards-dashboard__hero">
                <div>
                    <p class="tz-rewards-dashboard__eyebrow"><?php esc_html_e( 'Rewards dashboard', 'techzu-rewards' ); ?></p>
                    <h2><?php echo esc_html( $this->settings->get( 'program_title', 'Rewards' ) ); ?></h2>
                    <p><?php esc_html_e( 'Track your balance, tier, birthday perks, point expiry and reward history.', 'techzu-rewards' ); ?></p>
                </div>
                <div class="tz-rewards-dashboard__balance">
                    <strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong>
                    <span><?php echo esc_html( $this->format_points_label( $balance ) ); ?></span>
                </div>
            </div>

            <div class="tz-rewards-dashboard__grid">
                <section class="tz-rewards-card">
                    <div class="tz-rewards-card__header"><h3 class="tz-rewards-card__title"><?php esc_html_e( 'Membership tier', 'techzu-rewards' ); ?></h3><span class="tz-rewards-badge"><?php echo esc_html( $tier['name'] ); ?></span></div>
                    <p class="tz-rewards-card__text"><?php echo esc_html( sprintf( __( 'Eligible spend in the last %1$d months: %2$s', 'techzu-rewards' ), (int) $this->settings->get( 'tier_window_months', 12 ), wp_strip_all_tags( wc_price( (float) $progress['current_spend'] ) ) ) ); ?></p>
                    <?php if ( ! empty( $progress['next_tier'] ) ) : ?>
                        <div class="tz-rewards-progress" aria-label="<?php esc_attr_e( 'Tier progress', 'techzu-rewards' ); ?>"><span style="width: <?php echo esc_attr( (float) $progress['percent'] ); ?>%"></span></div>
                        <p class="tz-rewards-card__note"><?php echo esc_html( sprintf( __( 'Spend %1$s more to reach %2$s.', 'techzu-rewards' ), wp_strip_all_tags( wc_price( (float) $progress['remaining'] ) ), $progress['next_tier']['name'] ) ); ?></p>
                    <?php else : ?>
                        <p class="tz-rewards-card__note"><?php esc_html_e( 'You are on the highest available tier.', 'techzu-rewards' ); ?></p>
                    <?php endif; ?>
                </section>

                <section class="tz-rewards-card">
                    <div class="tz-rewards-card__header"><h3 class="tz-rewards-card__title"><?php esc_html_e( 'Birthday perk', 'techzu-rewards' ); ?></h3><span class="tz-rewards-badge"><?php echo esc_html( sprintf( __( '%s%% off', 'techzu-rewards' ), wc_format_decimal( (float) $tier['birthday_discount'], 0 ) ) ); ?></span></div>
                    <?php if ( $birthday ) : ?>
                        <p class="tz-rewards-card__text"><?php echo esc_html( sprintf( __( 'Birthday saved: %s', 'techzu-rewards' ), date_i18n( get_option( 'date_format' ), strtotime( $birthday ) ) ) ); ?></p>
                    <?php else : ?>
                        <p class="tz-rewards-card__text"><?php esc_html_e( 'Add your birthday under Account details to unlock birthday-month rewards.', 'techzu-rewards' ); ?></p>
                    <?php endif; ?>
                    <p class="tz-rewards-card__note"><?php echo esc_html( $eligibility['reason'] ); ?></p>
                </section>
            </div>

            <section class="tz-rewards-card tz-rewards-card--how-to-earn">
                <div class="tz-rewards-card__header"><h3 class="tz-rewards-card__title"><?php esc_html_e( 'How to earn and use rewards', 'techzu-rewards' ); ?></h3></div>
                <div class="tz-rewards-steps">
                    <div><strong><?php esc_html_e( 'Earn', 'techzu-rewards' ); ?></strong><span><?php echo esc_html( sprintf( __( 'Spend S$1 on eligible products = %1$s %2$s.', 'techzu-rewards' ), wc_format_decimal( (float) $this->settings->get( 'points_per_dollar', 1 ), 2 ), $this->settings->get( 'point_label_singular', 'Bliss Point' ) ) ); ?></span></div>
                    <div><strong><?php esc_html_e( 'Redeem', 'techzu-rewards' ); ?></strong><span><?php echo esc_html( sprintf( __( 'Every %1$d %2$s = %3$s off. Larger vouchers continue in the same step.', 'techzu-rewards' ), (int) $this->settings->get( 'redemption_step_points', 150 ), $this->settings->get( 'points_label', 'Bliss Points' ), wp_strip_all_tags( wc_price( (float) $this->settings->get( 'redemption_step_discount', 5 ) ) ) ) ); ?></span></div>
                    <div><strong><?php esc_html_e( 'Tier', 'techzu-rewards' ); ?></strong><span><?php esc_html_e( 'Bronze starts at account creation. Silver and Gold update automatically from eligible spend within the 12-month tier window.', 'techzu-rewards' ); ?></span></div>
                    <div><strong><?php esc_html_e( 'Expiry', 'techzu-rewards' ); ?></strong><span><?php echo esc_html( sprintf( __( 'Points are valid for %d months from the date earned.', 'techzu-rewards' ), (int) $this->settings->get( 'points_expiry_months', 12 ) ) ); ?></span></div>
                </div>
                <p class="tz-rewards-card__note"><?php esc_html_e( 'Points are based on the final paid eligible product amount and exclude shipping, cancelled orders and refunded items.', 'techzu-rewards' ); ?></p>
            </section>

            <section class="tz-rewards-card">
                <div class="tz-rewards-card__header"><h3 class="tz-rewards-card__title"><?php esc_html_e( 'Convert points to coupon', 'techzu-rewards' ); ?></h3></div>
                <?php if ( ! empty( $available ) ) : ?>
                    <p class="tz-rewards-card__text tz-rewards-card__text--strong"><?php esc_html_e( 'You got points. You can convert them into a WooCommerce coupon.', 'techzu-rewards' ); ?></p>
                    <?php $this->render_coupon_conversion_form( $available, __( 'Convert to coupon', 'techzu-rewards' ) ); ?>
                    <p class="tz-rewards-card__note"><?php esc_html_e( 'After conversion, the coupon is saved to your account. It is assigned only to you and must be applied manually at checkout.', 'techzu-rewards' ); ?></p>
                <?php else : ?>
                    <p class="tz-rewards-card__text"><?php esc_html_e( 'Keep shopping to unlock your first coupon conversion.', 'techzu-rewards' ); ?></p>
                <?php endif; ?>
                <?php $this->render_user_coupon_codes( $this->redemption_manager->get_user_reward_coupons( $user_id ), false ); ?>
            </section>

            <section class="tz-rewards-card">
                <div class="tz-rewards-card__header"><h3 class="tz-rewards-card__title"><?php esc_html_e( 'Point expiry', 'techzu-rewards' ); ?></h3></div>
                <div class="tz-rewards-table-wrap">
                    <table class="tz-rewards-table tz-rewards-table--compact">
                        <thead><tr><th><?php esc_html_e( 'Earned', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Remaining', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Expires', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Source', 'techzu-rewards' ); ?></th></tr></thead>
                        <tbody>
                        <?php
                        $shown_lots = 0;
                        foreach ( $lots as $lot ) :
                            if ( empty( $lot['remaining'] ) ) {
                                continue;
                            }
                            $shown_lots++;
                            ?>
                            <tr>
                                <td><?php echo esc_html( ! empty( $lot['earned_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $lot['earned_at'] ) ) : '-' ); ?></td>
                                <td><?php echo esc_html( absint( $lot['remaining'] ) ); ?></td>
                                <td><?php echo esc_html( ! empty( $lot['expires_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $lot['expires_at'] ) ) : __( 'Never', 'techzu-rewards' ) ); ?></td>
                                <td><?php echo esc_html( isset( $lot['source'] ) ? $lot['source'] : '-' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ( 0 === $shown_lots ) : ?>
                            <tr><td colspan="4"><?php esc_html_e( 'No active points yet.', 'techzu-rewards' ); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="tz-rewards-card">
                <div class="tz-rewards-card__header"><h3 class="tz-rewards-card__title"><?php esc_html_e( 'Reward history', 'techzu-rewards' ); ?></h3></div>
                <div class="tz-rewards-table-wrap">
                    <table class="tz-rewards-table tz-rewards-table--compact">
                        <thead><tr><th><?php esc_html_e( 'Date', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Type', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Points', 'techzu-rewards' ); ?></th><th><?php esc_html_e( 'Note', 'techzu-rewards' ); ?></th></tr></thead>
                        <tbody>
                        <?php if ( ! empty( $log ) ) : ?>
                            <?php foreach ( $log as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( ! empty( $entry['date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $entry['date'] ) ) : '-' ); ?></td>
                                    <td><?php echo esc_html( isset( $entry['type'] ) ? $entry['type'] : '-' ); ?></td>
                                    <td><?php echo esc_html( isset( $entry['points'] ) ? (int) $entry['points'] : 0 ); ?></td>
                                    <td><?php echo esc_html( isset( $entry['note'] ) ? $entry['note'] : '' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="4"><?php esc_html_e( 'No reward activity yet.', 'techzu-rewards' ); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render the coupon conversion form.
     *
     * @param array<int,array<string,mixed>> $available Available conversion options.
     * @param string                         $button_label Button label.
     * @param bool                           $as_modal Whether to show the form in a popup.
     * @return void
     */
    protected function render_coupon_conversion_form( $available, $button_label, $as_modal = false ) {
        if ( empty( $available ) ) {
            return;
        }

        $point_name = $this->settings->get( 'points_label', 'Bliss Points' );
        $target_id  = 'tz_rewards_coupon_modal_' . wp_rand( 1000, 999999 );

        if ( $as_modal ) {
            echo '<button type="button" class="button alt tz-rewards-form__button" data-tz-rewards-open="' . esc_attr( $target_id ) . '">' . esc_html( $button_label ) . '</button>';
            echo '<div id="' . esc_attr( $target_id ) . '" class="tz-rewards-modal" hidden>';
            echo '<div class="tz-rewards-modal__overlay" data-tz-rewards-close></div>';
            echo '<div class="tz-rewards-modal__dialog" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Convert points to coupon', 'techzu-rewards' ) . '">';
            echo '<button type="button" class="tz-rewards-modal__close" data-tz-rewards-close aria-label="' . esc_attr__( 'Close', 'techzu-rewards' ) . '">&times;</button>';
            echo '<h4 class="tz-rewards-card__title">' . esc_html__( 'Convert points to coupon', 'techzu-rewards' ) . '</h4>';
        }

        echo '<form method="post" class="tz-rewards-form tz-rewards-form--coupon-convert">';
        wp_nonce_field( Redemption_Manager::NONCE_ACTION );
        echo '<input type="hidden" name="tz_rewards_action" value="convert_coupon">';
        echo '<label class="screen-reader-text" for="tz_reward_tier">' . esc_html__( 'Choose points to convert', 'techzu-rewards' ) . '</label>';
        echo '<select id="tz_reward_tier" name="tz_reward_tier" class="tz-rewards-form__select">';

        foreach ( $available as $tier ) {
            printf(
                '<option value="%1$d">%2$s</option>',
                (int) $tier['points'],
                esc_html( sprintf( __( '%1$d %2$s -> %3$s coupon', 'techzu-rewards' ), (int) $tier['points'], $point_name, wp_strip_all_tags( wc_price( (float) $tier['voucher'] ) ) ) )
            );
        }

        echo '</select>';
        echo '<button type="submit" class="button alt tz-rewards-form__button">' . esc_html( $button_label ) . '</button>';
        echo '</form>';

        if ( $as_modal ) {
            echo '</div></div>';
        }
    }

    /**
     * Render saved reward coupon codes for the current user.
     *
     * @param array<int,array<string,mixed>> $coupons Coupon records.
     * @param bool                           $compact Compact display.
     * @return void
     */
    protected function render_user_coupon_codes( $coupons, $compact = false ) {
        if ( empty( $coupons ) ) {
            return;
        }

        echo '<div class="tz-rewards-coupon-list">';
        echo '<h4 class="tz-rewards-card__title">' . esc_html__( 'Your saved coupon codes', 'techzu-rewards' ) . '</h4>';
        echo '<div class="tz-rewards-table-wrap">';
        echo '<table class="tz-rewards-table tz-rewards-table--compact">';
        echo '<thead><tr><th>' . esc_html__( 'Coupon code', 'techzu-rewards' ) . '</th><th>' . esc_html__( 'Value', 'techzu-rewards' ) . '</th>';
        if ( ! $compact ) {
            echo '<th>' . esc_html__( 'Points', 'techzu-rewards' ) . '</th><th>' . esc_html__( 'Created', 'techzu-rewards' ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Status', 'techzu-rewards' ) . '</th></tr></thead><tbody>';

        foreach ( $coupons as $coupon ) {
            $code     = isset( $coupon['code'] ) ? (string) $coupon['code'] : '';
            $discount = isset( $coupon['discount'] ) ? (float) $coupon['discount'] : 0;
            $points   = isset( $coupon['points'] ) ? (int) $coupon['points'] : 0;
            $created  = ! empty( $coupon['created_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $coupon['created_at'] ) ) : '-';
            $status   = isset( $coupon['status'] ) ? (string) $coupon['status'] : __( 'Available', 'techzu-rewards' );

            echo '<tr>';
            echo '<td><code>' . esc_html( $code ) . '</code></td>';
            echo '<td>' . esc_html( wp_strip_all_tags( wc_price( $discount ) ) ) . '</td>';
            if ( ! $compact ) {
                echo '<td>' . esc_html( $points ) . '</td>';
                echo '<td>' . esc_html( $created ) . '</td>';
            }
            echo '<td>' . esc_html( $status ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    }

    /**
     * Format point label.
     *
     * @param int $points Points.
     * @return string
     */
    protected function format_points_label( $points ) {
        return ( 1 === (int) $points ) ? $this->settings->get( 'point_label_singular', 'Bliss Point' ) : $this->settings->get( 'points_label', 'Bliss Points' );
    }

    /**
     * Whether the plugin is enabled.
     *
     * @return bool
     */
    protected function is_enabled() {
        return 'yes' === $this->settings->get( 'enabled', 'yes' );
    }
}
