<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Logger;
use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Birthday_Discount_Manager {
    const SESSION_KEY         = 'tz_rewards_birthday_discount';
    const NONCE_ACTION        = 'tz_rewards_birthday_action';
    const ORDER_META_DISCOUNT = '_tz_rewards_birthday_discount';
    const ORDER_META_PERCENT  = '_tz_rewards_birthday_percent';
    const ORDER_META_TIER     = '_tz_rewards_birthday_tier';
    const ORDER_META_MARKER   = '_tz_rewards_birthday_marker';
    const USED_META_PREFIX    = 'tz_rewards_birthday_used_';

    /**
     * Settings instance.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Tier manager.
     *
     * @var Tier_Manager
     */
    protected $tier_manager;

    /**
     * Calculator.
     *
     * @var Calculator
     */
    protected $calculator;

    /**
     * Logger.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Settings     $settings Settings object.
     * @param Tier_Manager $tier_manager Tier manager.
     * @param Calculator   $calculator Calculator.
     * @param Logger       $logger Logger.
     */
    public function __construct( Settings $settings, Tier_Manager $tier_manager, Calculator $calculator, Logger $logger ) {
        $this->settings     = $settings;
        $this->tier_manager = $tier_manager;
        $this->calculator   = $calculator;
        $this->logger       = $logger;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'woocommerce_before_cart', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount' ), 30 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_discount_to_order' ), 25, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_discount_used' ), 25, 3 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 25, 4 );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'block_coupon_when_birthday_active' ), 10, 3 );
    }

    /**
     * Handle apply/remove actions.
     *
     * @return void
     */
    public function handle_submission() {
        if ( ! $this->is_enabled() || ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }

        if ( empty( $_POST['tz_rewards_birthday_action'] ) || empty( $_POST['_wpnonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wc_add_notice( esc_html__( 'Security check failed. Please try again.', 'techzu-rewards' ), 'error' );
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['tz_rewards_birthday_action'] ) );

        if ( 'remove_birthday_discount' === $action ) {
            $this->clear_active_discount();
            wc_add_notice( esc_html__( 'Birthday discount removed.', 'techzu-rewards' ), 'notice' );
            return;
        }

        if ( 'apply_birthday_discount' !== $action ) {
            return;
        }

        $eligibility = $this->get_current_user_eligibility();
        if ( empty( $eligibility['eligible'] ) ) {
            wc_add_notice( esc_html( $eligibility['reason'] ), 'error' );
            return;
        }

        $payload = array(
            'percent' => (float) $eligibility['percent'],
            'tier'    => (string) $eligibility['tier']['key'],
            'label'   => (string) $eligibility['tier']['name'],
            'marker'  => $this->get_current_marker(),
        );

        WC()->session->set( self::SESSION_KEY, $payload );
        $this->logger->log( 'birthday_discount_applied', array( 'user_id' => get_current_user_id(), 'payload' => $payload ) );
        wc_add_notice( esc_html__( 'Birthday discount applied successfully.', 'techzu-rewards' ), 'success' );
    }

    /**
     * Apply the birthday fee discount.
     *
     * @param \WC_Cart $cart WooCommerce cart.
     * @return void
     */
    public function apply_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! $this->is_enabled() || ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session || ! $cart ) {
            return;
        }

        $active = $this->get_active_discount();
        if ( empty( $active ) && 'yes' === $this->settings->get( 'birthday_auto_apply', 'no' ) ) {
            $eligibility = $this->get_current_user_eligibility();
            if ( ! empty( $eligibility['eligible'] ) ) {
                $active = array(
                    'percent' => (float) $eligibility['percent'],
                    'tier'    => (string) $eligibility['tier']['key'],
                    'label'   => (string) $eligibility['tier']['name'],
                    'marker'  => $this->get_current_marker(),
                );
                WC()->session->set( self::SESSION_KEY, $active );
            }
        }

        if ( empty( $active['percent'] ) ) {
            return;
        }

        $eligibility = $this->get_current_user_eligibility();
        if ( empty( $eligibility['eligible'] ) ) {
            $this->clear_active_discount();
            if ( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( esc_html( $eligibility['reason'] ), 'notice' );
            }
            return;
        }

        $eligible_total = $this->get_cart_eligible_total();
        $discount       = round( $eligible_total * ( (float) $active['percent'] / 100 ), wc_get_price_decimals() );

        if ( $discount <= 0 ) {
            return;
        }

        $label = sprintf(
            '%1$s (%2$s - %3$s%%)',
            $this->settings->get( 'birthday_label', __( 'Birthday perk', 'techzu-rewards' ) ),
            isset( $active['label'] ) ? $active['label'] : $eligibility['tier']['name'],
            wc_format_decimal( (float) $active['percent'], 0 )
        );

        $cart->add_fee( $label, -1 * $discount, false );
        $this->logger->log( 'birthday_discount_fee_added', array( 'user_id' => get_current_user_id(), 'discount' => $discount, 'percent' => (float) $active['percent'] ) );
    }

    /**
     * Attach birthday discount data to order.
     *
     * @param \WC_Order $order Order.
     * @param array     $data Checkout data.
     * @return void
     */
    public function attach_discount_to_order( $order, $data ) {
        unset( $data );

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        $active = $this->get_active_discount();
        if ( empty( $active['percent'] ) ) {
            return;
        }

        $discount = 0.0;
        foreach ( $order->get_fees() as $fee ) {
            if ( false !== strpos( (string) $fee->get_name(), (string) $this->settings->get( 'birthday_label', 'Birthday perk' ) ) ) {
                $discount += abs( (float) $fee->get_total() );
            }
        }

        $order->update_meta_data( self::ORDER_META_DISCOUNT, wc_format_decimal( $discount ) );
        $order->update_meta_data( self::ORDER_META_PERCENT, wc_format_decimal( (float) $active['percent'] ) );
        $order->update_meta_data( self::ORDER_META_TIER, sanitize_key( $active['tier'] ) );
        $order->update_meta_data( self::ORDER_META_MARKER, sanitize_text_field( $active['marker'] ) );
    }

    /**
     * Mark the birthday discount as used after checkout.
     *
     * @param int       $order_id Order ID.
     * @param array     $posted_data Posted checkout data.
     * @param \WC_Order $order Order.
     * @return void
     */
    public function mark_discount_used( $order_id, $posted_data, $order ) {
        unset( $order_id, $posted_data );

        if ( ! $order instanceof \WC_Order ) {
            $this->clear_active_discount();
            return;
        }

        $user_id = (int) $order->get_user_id();
        $marker  = (string) $order->get_meta( self::ORDER_META_MARKER, true );
        if ( $user_id <= 0 || '' === $marker ) {
            $this->clear_active_discount();
            return;
        }

        update_user_meta( $user_id, self::USED_META_PREFIX . $marker, $order->get_id() );
        $this->logger->log( 'birthday_discount_marked_used', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'marker' => $marker ) );
        $order->add_order_note( esc_html__( 'Birthday discount marked as used for this birthday month.', 'techzu-rewards' ) );

        $this->clear_active_discount();
    }

    /**
     * Restore birthday usage on failed/cancelled/refunded orders.
     *
     * @param int       $order_id Order ID.
     * @param string    $old_status Old status.
     * @param string    $new_status New status.
     * @param \WC_Order $order Order.
     * @return void
     */
    public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
        unset( $order_id, $old_status );

        if ( ! $order instanceof \WC_Order || ! in_array( $new_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        $marker  = (string) $order->get_meta( self::ORDER_META_MARKER, true );
        if ( $user_id <= 0 || '' === $marker ) {
            return;
        }

        $meta_key = self::USED_META_PREFIX . $marker;
        $used_for = (int) get_user_meta( $user_id, $meta_key, true );
        if ( $used_for === (int) $order->get_id() ) {
            delete_user_meta( $user_id, $meta_key );
            $order->add_order_note( esc_html__( 'Birthday discount usage restored because the order is no longer valid.', 'techzu-rewards' ) );
        }
    }

    /**
     * Block coupon stacking when birthday discount is active.
     *
     * @param bool       $valid Coupon valid.
     * @param \WC_Coupon $coupon Coupon.
     * @param mixed      $discount Discount object.
     * @return bool
     * @throws \Exception When coupons are blocked.
     */
    public function block_coupon_when_birthday_active( $valid, $coupon, $discount ) {
        unset( $coupon, $discount );

        if ( ! $valid || 'yes' !== $this->settings->get( 'non_stacking_enabled', 'yes' ) || 'yes' !== $this->settings->get( 'block_coupons_when_reward_active', 'yes' ) ) {
            return $valid;
        }

        if ( ! empty( $this->get_active_discount() ) ) {
            throw new \Exception( esc_html__( 'Only one reward, birthday discount, voucher or promotional code may be used per order.', 'techzu-rewards' ) );
        }

        return $valid;
    }

    /**
     * Get current user birthday eligibility.
     *
     * @return array<string,mixed>
     */
    public function get_current_user_eligibility() {
        if ( ! is_user_logged_in() ) {
            return $this->eligibility_response( false, __( 'Please log in to use your birthday discount.', 'techzu-rewards' ) );
        }

        return $this->get_user_eligibility( get_current_user_id() );
    }

    /**
     * Get birthday eligibility for a user.
     *
     * @param int $user_id User ID.
     * @return array<string,mixed>
     */
    public function get_user_eligibility( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $this->is_enabled() ) {
            return $this->eligibility_response( false, __( 'Birthday discounts are currently disabled.', 'techzu-rewards' ) );
        }

        if ( $user_id <= 0 ) {
            return $this->eligibility_response( false, __( 'Customer account is required.', 'techzu-rewards' ) );
        }

        $birthday = $this->tier_manager->get_birthday( $user_id );
        if ( '' === $birthday ) {
            return $this->eligibility_response( false, __( 'Add your birthday in My Account to unlock birthday treats.', 'techzu-rewards' ) );
        }

        if ( gmdate( 'm', strtotime( $birthday ) ) !== $this->site_month() ) {
            return $this->eligibility_response( false, __( 'Birthday discounts are available during your birthday month only.', 'techzu-rewards' ) );
        }

        $marker = $this->get_current_marker();
        if ( (int) get_user_meta( $user_id, self::USED_META_PREFIX . $marker, true ) > 0 ) {
            return $this->eligibility_response( false, __( 'Your birthday discount has already been used for this birthday month.', 'techzu-rewards' ) );
        }

        if ( function_exists( 'WC' ) && WC()->cart ) {
            if ( 'yes' === $this->settings->get( 'non_stacking_enabled', 'yes' ) && ! empty( WC()->cart->get_applied_coupons() ) ) {
                return $this->eligibility_response( false, __( 'Remove other coupons before using a birthday discount.', 'techzu-rewards' ) );
            }

            if ( 'yes' === $this->settings->get( 'non_stacking_enabled', 'yes' ) && function_exists( 'WC' ) && WC()->session ) {
                $active_reward = WC()->session->get( Redemption_Manager::SESSION_KEY, array() );
                if ( is_array( $active_reward ) && ! empty( $active_reward['points'] ) ) {
                    return $this->eligibility_response( false, __( 'Remove the reward voucher before using a birthday discount.', 'techzu-rewards' ) );
                }
            }

            if ( 'yes' === $this->settings->get( 'birthday_block_sale_items', 'yes' ) && $this->cart_has_sale_item() ) {
                return $this->eligibility_response( false, __( 'Birthday discounts cannot be used with sale-discounted items.', 'techzu-rewards' ) );
            }

            $minimum = (float) $this->settings->get( 'birthday_minimum_spend', 60 );
            if ( $this->get_cart_eligible_total() < $minimum ) {
                return $this->eligibility_response( false, sprintf( __( 'Birthday discount requires a minimum eligible spend of %s.', 'techzu-rewards' ), wp_strip_all_tags( wc_price( $minimum ) ) ) );
            }
        }

        $tier = $this->tier_manager->get_customer_tier( $user_id );
        $percent = isset( $tier['birthday_discount'] ) ? (float) $tier['birthday_discount'] : 0;
        if ( $percent <= 0 ) {
            return $this->eligibility_response( false, __( 'Your current tier does not include a birthday discount.', 'techzu-rewards' ), $tier );
        }

        return $this->eligibility_response( true, __( 'Birthday discount is available.', 'techzu-rewards' ), $tier, $percent );
    }

    /**
     * Get active discount session.
     *
     * @return array<string,mixed>
     */
    public function get_active_discount() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return array();
        }

        $value = WC()->session->get( self::SESSION_KEY, array() );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Clear active discount session.
     *
     * @return void
     */
    public function clear_active_discount() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::SESSION_KEY );
        }
    }

    /**
     * Get cart eligible total.
     *
     * @return float
     */
    public function get_cart_eligible_total() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0.0;
        }

        return $this->calculator->get_cart_eligible_product_total( WC()->cart, 'yes' === $this->settings->get( 'birthday_block_sale_items', 'yes' ) );
    }

    /**
     * Check whether the current cart has sale items.
     *
     * @return bool
     */
    protected function cart_has_sale_item() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
            if ( $product instanceof \WC_Product && $product->is_on_sale() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format an eligibility response.
     *
     * @param bool                    $eligible Eligible flag.
     * @param string                  $reason Reason.
     * @param array<string,mixed>|null $tier Tier.
     * @param float                   $percent Discount percent.
     * @return array<string,mixed>
     */
    protected function eligibility_response( $eligible, $reason, $tier = null, $percent = 0 ) {
        return array(
            'eligible' => (bool) $eligible,
            'reason'   => $reason,
            'tier'     => is_array( $tier ) ? $tier : array(),
            'percent'  => (float) $percent,
        );
    }

    /**
     * Get current marker for the birthday usage meta.
     *
     * @return string
     */
    protected function get_current_marker() {
        return function_exists( 'current_time' ) ? current_time( 'Y-m' ) : gmdate( 'Y-m' );
    }

    /**
     * Get current site month.
     *
     * @return string
     */
    protected function site_month() {
        return function_exists( 'current_time' ) ? current_time( 'm' ) : gmdate( 'm' );
    }

    /**
     * Whether feature is enabled.
     *
     * @return bool
     */
    protected function is_enabled() {
        return 'yes' === $this->settings->get( 'enabled', 'yes' ) && 'yes' === $this->settings->get( 'birthday_enabled', 'yes' );
    }
}
