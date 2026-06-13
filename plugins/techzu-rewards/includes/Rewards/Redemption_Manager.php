<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Logger;
use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Redemption_Manager {
    const SESSION_KEY            = 'tz_rewards_redemption';
    const NONCE_ACTION           = 'tz_rewards_redeem_action';
    const ORDER_META_POINTS      = '_tz_rewards_redeemed_points';
    const ORDER_META_DISCOUNT    = '_tz_rewards_redeemed_discount';
    const ORDER_META_DEDUCTED    = '_tz_rewards_redeemed_points_deducted';
    const ORDER_META_RESTORED    = '_tz_rewards_redeemed_points_restored';

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
     * Logger.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Settings       $settings Settings object.
     * @param Points_Manager $points_manager Points manager.
     * @param Calculator     $calculator Calculator.
     * @param Logger         $logger Logger.
     */
    public function __construct( Settings $settings, Points_Manager $points_manager, Calculator $calculator, Logger $logger ) {
        $this->settings       = $settings;
        $this->points_manager = $points_manager;
        $this->calculator     = $calculator;
        $this->logger         = $logger;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'woocommerce_before_cart', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount' ), 20 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_redemption_to_order' ), 20, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'deduct_points' ), 20, 3 );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'block_coupon_when_reward_active' ), 10, 3 );
    }

    /**
     * Handle cart and checkout form submission.
     *
     * @return void
     */
    public function handle_submission() {
        if ( ! $this->is_enabled() || ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }

        if ( empty( $_POST['tz_rewards_action'] ) || empty( $_POST['_wpnonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wc_add_notice( esc_html__( 'Security check failed. Please try again.', 'techzu-rewards' ), 'error' );
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['tz_rewards_action'] ) );

        if ( 'remove_redemption' === $action ) {
            $this->clear_active_redemption();
            $this->logger->log( 'redemption_removed', array( 'user_id' => get_current_user_id() ) );
            wc_add_notice( esc_html__( 'Reward voucher removed.', 'techzu-rewards' ), 'notice' );
            return;
        }

        if ( 'apply_redemption' !== $action ) {
            return;
        }

        if ( 'yes' === $this->settings->get( 'non_stacking_enabled', 'yes' ) ) {
            if ( ! empty( WC()->cart->get_applied_coupons() ) ) {
                wc_add_notice( esc_html__( 'Remove other coupons before using a reward voucher.', 'techzu-rewards' ), 'error' );
                return;
            }

            $birthday = WC()->session->get( Birthday_Discount_Manager::SESSION_KEY, array() );
            if ( is_array( $birthday ) && ! empty( $birthday['percent'] ) ) {
                wc_add_notice( esc_html__( 'Remove the birthday discount before using a reward voucher.', 'techzu-rewards' ), 'error' );
                return;
            }
        }

        $selected_points = isset( $_POST['tz_reward_tier'] ) ? absint( wp_unslash( $_POST['tz_reward_tier'] ) ) : 0;
        $available       = $this->get_available_redemptions_for_current_user();

        if ( ! isset( $available[ $selected_points ] ) ) {
            wc_add_notice( esc_html__( 'The selected voucher is no longer available.', 'techzu-rewards' ), 'error' );
            return;
        }

        $payload = array(
            'points'   => (int) $available[ $selected_points ]['points'],
            'discount' => (float) $available[ $selected_points ]['voucher'],
            'label'    => isset( $available[ $selected_points ]['label'] ) ? (string) $available[ $selected_points ]['label'] : '',
        );

        WC()->session->set( self::SESSION_KEY, $payload );
        $this->logger->log( 'redemption_applied', array( 'user_id' => get_current_user_id(), 'payload' => $payload ) );
        do_action( 'tz_rewards_redemption_applied', get_current_user_id(), $payload );

        wc_add_notice( esc_html__( 'Reward voucher applied successfully.', 'techzu-rewards' ), 'success' );
    }

    /**
     * Apply discount to cart totals.
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

        $active = $this->get_active_redemption();
        if ( empty( $active['points'] ) || empty( $active['discount'] ) ) {
            return;
        }

        if ( 'yes' === $this->settings->get( 'non_stacking_enabled', 'yes' ) ) {
            if ( ! empty( $cart->get_applied_coupons() ) ) {
                $this->clear_active_redemption();
                wc_add_notice( esc_html__( 'Reward voucher was removed because another coupon is active.', 'techzu-rewards' ), 'notice' );
                return;
            }

            $birthday = WC()->session->get( Birthday_Discount_Manager::SESSION_KEY, array() );
            if ( is_array( $birthday ) && ! empty( $birthday['percent'] ) ) {
                $this->clear_active_redemption();
                wc_add_notice( esc_html__( 'Reward voucher was removed because a birthday discount is active.', 'techzu-rewards' ), 'notice' );
                return;
            }
        }

        $balance = $this->points_manager->get_balance( get_current_user_id() );
        if ( $balance < (int) $active['points'] ) {
            $this->clear_active_redemption();
            wc_add_notice( esc_html__( 'Your reward balance changed, so the voucher was removed.', 'techzu-rewards' ), 'notice' );
            return;
        }

        $subtotal = $this->calculator->get_cart_eligible_product_total( $cart, false );
        if ( $subtotal <= 0 || (float) $active['discount'] > $subtotal ) {
            $this->clear_active_redemption();
            wc_add_notice( esc_html__( 'The voucher no longer matches your cart total and was removed.', 'techzu-rewards' ), 'notice' );
            return;
        }

        $label = sprintf(
            '%s (%d %s)',
            $this->settings->get( 'voucher_label', 'Reward voucher' ),
            (int) $active['points'],
            $this->settings->get( 'points_label', 'Bliss Points' )
        );

        $discount = min( (float) $active['discount'], $subtotal );
        $cart->add_fee( $label, -1 * $discount, false );
        $this->logger->log( 'redemption_discount_applied', array( 'user_id' => get_current_user_id(), 'points' => (int) $active['points'], 'discount' => $discount ) );
    }

    /**
     * Attach redemption data to the order.
     *
     * @param \WC_Order $order Order.
     * @param array     $data Checkout data.
     * @return void
     */
    public function attach_redemption_to_order( $order, $data ) {
        unset( $data );

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        $active = $this->get_active_redemption();
        if ( empty( $active['points'] ) || empty( $active['discount'] ) ) {
            return;
        }

        $order->update_meta_data( self::ORDER_META_POINTS, (int) $active['points'] );
        $order->update_meta_data( self::ORDER_META_DISCOUNT, wc_format_decimal( $active['discount'] ) );
    }

    /**
     * Deduct points after an order is created.
     *
     * @param int       $order_id Order ID.
     * @param array     $posted_data Posted checkout data.
     * @param \WC_Order $order Order object.
     * @return void
     */
    public function deduct_points( $order_id, $posted_data, $order ) {
        unset( $order_id, $posted_data );

        if ( ! $this->is_enabled() || ! is_user_logged_in() || ! $order instanceof \WC_Order ) {
            $this->clear_active_redemption();
            return;
        }

        $already_deducted = (int) $order->get_meta( self::ORDER_META_DEDUCTED, true );
        if ( $already_deducted > 0 ) {
            $this->clear_active_redemption();
            return;
        }

        $required_points = (int) $order->get_meta( self::ORDER_META_POINTS, true );
        if ( $required_points <= 0 ) {
            $this->clear_active_redemption();
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) {
            $this->clear_active_redemption();
            return;
        }

        if ( $this->points_manager->get_balance( $user_id ) < $required_points ) {
            $order->add_order_note( esc_html__( 'Reward points were not deducted because the balance was insufficient at checkout.', 'techzu-rewards' ) );
            $this->clear_active_redemption();
            return;
        }

        $this->points_manager->subtract_points(
            $user_id,
            $required_points,
            array(
                'source'   => 'redeem',
                'order_id' => $order->get_id(),
                'note'     => __( 'Reward voucher redeemed', 'techzu-rewards' ),
            )
        );
        $this->logger->log( 'points_deducted', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'points' => $required_points ) );
        $order->update_meta_data( self::ORDER_META_DEDUCTED, $required_points );
        $order->save();

        do_action( 'tz_rewards_points_redeemed', $user_id, $required_points, $order->get_id() );
        $order->add_order_note( sprintf( esc_html__( 'Reward points redeemed: %d', 'techzu-rewards' ), $required_points ) );

        $this->clear_active_redemption();
    }

    /**
     * Restore redeemed points after order failure/cancellation/refund.
     *
     * @param \WC_Order $order Order.
     * @return void
     */
    public function maybe_restore_points( $order ) {
        $user_id         = (int) $order->get_user_id();
        $deducted_points = (int) $order->get_meta( self::ORDER_META_DEDUCTED, true );
        $restored_points = (int) $order->get_meta( self::ORDER_META_RESTORED, true );

        if ( $user_id <= 0 || $deducted_points <= 0 || $restored_points > 0 ) {
            return;
        }

        $this->points_manager->add_points(
            $user_id,
            $deducted_points,
            array(
                'source'   => 'restore',
                'order_id' => $order->get_id(),
                'note'     => __( 'Reward voucher points restored', 'techzu-rewards' ),
            )
        );
        $this->logger->log( 'points_restored', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'points' => $deducted_points ) );
        $order->update_meta_data( self::ORDER_META_RESTORED, $deducted_points );
        $order->save();

        do_action( 'tz_rewards_points_restored', $user_id, $deducted_points, $order->get_id() );
        $order->add_order_note( sprintf( esc_html__( 'Reward points restored: %d', 'techzu-rewards' ), $deducted_points ) );
    }

    /**
     * Block coupon stacking when reward voucher is active.
     *
     * @param bool       $valid Coupon validity.
     * @param \WC_Coupon $coupon Coupon.
     * @param mixed      $discount Discount object.
     * @return bool
     * @throws \Exception When coupons are blocked.
     */
    public function block_coupon_when_reward_active( $valid, $coupon, $discount ) {
        unset( $coupon, $discount );

        if ( ! $valid || 'yes' !== $this->settings->get( 'non_stacking_enabled', 'yes' ) || 'yes' !== $this->settings->get( 'block_coupons_when_reward_active', 'yes' ) ) {
            return $valid;
        }

        $active = $this->get_active_redemption();
        if ( ! empty( $active['points'] ) ) {
            throw new \Exception( esc_html__( 'Only one reward, birthday discount, voucher or promotional code may be used per order.', 'techzu-rewards' ) );
        }

        return $valid;
    }

    /**
     * Get available redemptions for the current user and cart.
     *
     * @return array<int,array<string,float|int|string>>
     */
    public function get_available_redemptions_for_current_user() {
        if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }

        return $this->calculator->get_available_redemptions(
            $this->points_manager->get_balance( get_current_user_id() ),
            $this->calculator->get_cart_eligible_product_total( WC()->cart, false )
        );
    }

    /**
     * Get the active redemption in the session.
     *
     * @return array<string,mixed>
     */
    public function get_active_redemption() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return array();
        }

        $value = WC()->session->get( self::SESSION_KEY, array() );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Clear the active redemption session.
     *
     * @return void
     */
    public function clear_active_redemption() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::SESSION_KEY );
        }
    }

    /**
     * Whether the feature is enabled.
     *
     * @return bool
     */
    protected function is_enabled() {
        return 'yes' === $this->settings->get( 'enabled', 'yes' );
    }
}
