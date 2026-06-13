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
        add_action( 'template_redirect', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_before_cart', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'handle_submission' ) );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_redemption_to_order' ), 20, 2 );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_reward_coupon_owner' ), 10, 3 );
    }

    /**
     * Handle cart, checkout and My Account coupon conversion submissions.
     *
     * @return void
     */
    public function handle_submission() {
        static $handled = false;

        if ( $handled || ! $this->is_enabled() || ! is_user_logged_in() ) {
            return;
        }

        if ( empty( $_POST['tz_rewards_action'] ) || empty( $_POST['_wpnonce'] ) ) {
            return;
        }

        $handled = true;
        $nonce   = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wc_add_notice( esc_html__( 'Security check failed. Please try again.', 'techzu-rewards' ), 'error' );
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['tz_rewards_action'] ) );
        if ( 'convert_coupon' !== $action && 'apply_redemption' !== $action ) {
            return;
        }

        $selected_points = isset( $_POST['tz_reward_tier'] ) ? absint( wp_unslash( $_POST['tz_reward_tier'] ) ) : 0;
        $available       = $this->get_available_redemptions_for_current_user( false );

        if ( ! isset( $available[ $selected_points ] ) ) {
            wc_add_notice( esc_html__( 'The selected coupon conversion is no longer available.', 'techzu-rewards' ), 'error' );
            return;
        }

        $user_id  = get_current_user_id();
        $points   = (int) $available[ $selected_points ]['points'];
        $discount = (float) $available[ $selected_points ]['voucher'];

        if ( $this->points_manager->get_balance( $user_id ) < $points ) {
            wc_add_notice( esc_html__( 'Your reward balance is not enough to convert this coupon.', 'techzu-rewards' ), 'error' );
            return;
        }

        $coupon_id = $this->create_reward_coupon( $user_id, $points, $discount );
        if ( is_wp_error( $coupon_id ) ) {
            wc_add_notice( $coupon_id->get_error_message(), 'error' );
            return;
        }

        $this->points_manager->subtract_points(
            $user_id,
            $points,
            array(
                'source' => 'reward_coupon',
                'note'   => sprintf( __( 'Converted to coupon %s', 'techzu-rewards' ), get_the_title( $coupon_id ) ),
            )
        );

        $this->save_user_coupon_record( $user_id, $coupon_id, $points, $discount );
        $code = (string) get_the_title( $coupon_id );

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'tz_rewards_last_coupon_code', $code );
        }

        $this->logger->log( 'coupon_converted', array( 'user_id' => $user_id, 'coupon_id' => $coupon_id, 'code' => $code, 'points' => $points, 'discount' => $discount ) );
        do_action( 'tz_rewards_coupon_converted', $user_id, $coupon_id, $points, $discount );

        wc_add_notice( sprintf( esc_html__( 'Reward coupon created: %s. Enter this coupon code manually at checkout when you want to use it.', 'techzu-rewards' ), $code ), 'success' );
    }

    /**
     * Disable the old automatic fee-style discount. Reward conversions now create real WooCommerce coupons only.
     *
     * @param \WC_Cart $cart WooCommerce cart.
     * @return void
     */
    public function apply_discount( $cart ) {
        unset( $cart );
    }

    /**
     * Attach reward coupon data to orders when a generated coupon is manually applied.
     *
     * @param \WC_Order $order Order.
     * @param array     $data Checkout data.
     * @return void
     */
    public function attach_redemption_to_order( $order, $data ) {
        unset( $data );

        if ( ! $order instanceof \WC_Order || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
            return;
        }

        foreach ( $order->get_coupon_codes() as $code ) {
            $coupon_id = wc_get_coupon_id_by_code( $code );
            if ( ! $coupon_id || 'yes' !== get_post_meta( $coupon_id, '_tz_rewards_coupon', true ) ) {
                continue;
            }

            $points   = (int) get_post_meta( $coupon_id, '_tz_rewards_points', true );
            $discount = (float) get_post_meta( $coupon_id, '_tz_rewards_discount', true );

            $order->update_meta_data( self::ORDER_META_POINTS, $points );
            $order->update_meta_data( self::ORDER_META_DISCOUNT, wc_format_decimal( $discount ) );
            $order->update_meta_data( '_tz_rewards_coupon_code', sanitize_text_field( $code ) );
            update_post_meta( $coupon_id, '_tz_rewards_order_id', $order->get_id() );
            break;
        }
    }

    /**
     * Kept for backward compatibility with old orders. New coupon conversions deduct points immediately.
     *
     * @param int       $order_id Order ID.
     * @param array     $posted_data Posted checkout data.
     * @param \WC_Order $order Order object.
     * @return void
     */
    public function deduct_points( $order_id, $posted_data, $order ) {
        unset( $order_id, $posted_data, $order );
    }

    /**
     * Kept for backward compatibility with old orders. New generated coupons are not restored automatically.
     *
     * @param \WC_Order $order Order.
     * @return void
     */
    public function maybe_restore_points( $order ) {
        unset( $order );
    }

    /**
     * Validate that reward coupons can only be used by the assigned user.
     *
     * @param bool       $valid Coupon validity.
     * @param \WC_Coupon $coupon Coupon.
     * @param mixed      $discount Discount object.
     * @return bool
     * @throws \Exception When the coupon belongs to another user.
     */
    public function validate_reward_coupon_owner( $valid, $coupon, $discount ) {
        unset( $discount );

        if ( ! $valid || ! $coupon instanceof \WC_Coupon ) {
            return $valid;
        }

        if ( 'yes' !== $coupon->get_meta( '_tz_rewards_coupon', true ) ) {
            return $valid;
        }

        $assigned_user_id = (int) $coupon->get_meta( '_tz_rewards_user_id', true );
        if ( $assigned_user_id <= 0 || ! is_user_logged_in() || get_current_user_id() !== $assigned_user_id ) {
            throw new \Exception( esc_html__( 'This reward coupon is assigned to another customer.', 'techzu-rewards' ) );
        }

        return $valid;
    }

    /**
     * Backward-compatible alias for older filters.
     *
     * @param bool       $valid Coupon validity.
     * @param \WC_Coupon $coupon Coupon.
     * @param mixed      $discount Discount object.
     * @return bool
     */
    public function block_coupon_when_reward_active( $valid, $coupon, $discount ) {
        return $this->validate_reward_coupon_owner( $valid, $coupon, $discount );
    }

    /**
     * Create a real WooCommerce coupon restricted to one user.
     *
     * @param int   $user_id User ID.
     * @param int   $points Points converted.
     * @param float $discount Coupon amount.
     * @return int|\WP_Error Coupon ID or error.
     */
    protected function create_reward_coupon( $user_id, $points, $discount ) {
        if ( ! class_exists( '\WC_Coupon' ) ) {
            return new \WP_Error( 'tz_rewards_no_wc_coupon', esc_html__( 'WooCommerce coupons are not available.', 'techzu-rewards' ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return new \WP_Error( 'tz_rewards_no_user', esc_html__( 'Could not find the customer account for this coupon.', 'techzu-rewards' ) );
        }

        $code   = $this->generate_coupon_code( $user_id );
        $coupon = new \WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'fixed_cart' );
        $coupon->set_amount( wc_format_decimal( $discount ) );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        $coupon->set_individual_use( 'yes' === $this->settings->get( 'non_stacking_enabled', 'yes' ) );
        $coupon->set_email_restrictions( array( $user->user_email ) );
        $coupon->set_description( sprintf( __( 'Reward coupon converted by user #%1$d for %2$d points.', 'techzu-rewards' ), $user_id, $points ) );
        $coupon->update_meta_data( '_tz_rewards_coupon', 'yes' );
        $coupon->update_meta_data( '_tz_rewards_user_id', $user_id );
        $coupon->update_meta_data( '_tz_rewards_user_email', sanitize_email( $user->user_email ) );
        $coupon->update_meta_data( '_tz_rewards_points', $points );
        $coupon->update_meta_data( '_tz_rewards_discount', wc_format_decimal( $discount ) );
        $coupon->update_meta_data( '_tz_rewards_created_at', current_time( 'mysql' ) );

        try {
            $coupon_id = $coupon->save();
        } catch ( \Exception $e ) {
            return new \WP_Error( 'tz_rewards_coupon_failed', $e->getMessage() );
        }

        return absint( $coupon_id );
    }

    /**
     * Generate a unique reward coupon code.
     *
     * @param int $user_id User ID.
     * @return string
     */
    protected function generate_coupon_code( $user_id ) {
        $prefix = (string) apply_filters( 'tz_rewards_coupon_prefix', 'TZR' );

        do {
            $code = strtoupper( sanitize_title( $prefix . '-' . $user_id . '-' . wp_generate_password( 8, false, false ) ) );
        } while ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) );

        return $code;
    }

    /**
     * Store generated coupon details on the user account for display.
     *
     * @param int   $user_id User ID.
     * @param int   $coupon_id Coupon ID.
     * @param int   $points Points converted.
     * @param float $discount Coupon amount.
     * @return void
     */
    protected function save_user_coupon_record( $user_id, $coupon_id, $points, $discount ) {
        $records = get_user_meta( $user_id, 'tz_rewards_generated_coupons', true );
        if ( ! is_array( $records ) ) {
            $records = array();
        }

        array_unshift(
            $records,
            array(
                'coupon_id'  => absint( $coupon_id ),
                'code'       => (string) get_the_title( $coupon_id ),
                'points'     => absint( $points ),
                'discount'   => wc_format_decimal( $discount ),
                'created_at' => current_time( 'mysql' ),
            )
        );

        update_user_meta( $user_id, 'tz_rewards_generated_coupons', array_slice( $records, 0, 50 ) );
    }

    /**
     * Get generated reward coupons for a user.
     *
     * @param int $user_id User ID.
     * @return array<int,array<string,mixed>>
     */
    public function get_user_reward_coupons( $user_id ) {
        $records = get_user_meta( absint( $user_id ), 'tz_rewards_generated_coupons', true );
        if ( ! is_array( $records ) ) {
            return array();
        }

        foreach ( $records as $index => $record ) {
            $coupon_id = isset( $record['coupon_id'] ) ? absint( $record['coupon_id'] ) : 0;
            $usage     = $coupon_id ? (int) get_post_meta( $coupon_id, 'usage_count', true ) : 0;
            $records[ $index ]['status'] = $usage > 0 ? __( 'Used', 'techzu-rewards' ) : __( 'Available', 'techzu-rewards' );
        }

        return $records;
    }

    /**
     * Get available redemptions for the current user and cart.
     *
     * @return array<int,array<string,float|int|string>>
     */
    public function get_available_redemptions_for_current_user( $require_cart_fit = false ) {
        if ( ! is_user_logged_in() ) {
            return array();
        }

        $cart_subtotal = null;
        if ( $require_cart_fit && function_exists( 'WC' ) && WC()->cart ) {
            $cart_subtotal = $this->calculator->get_cart_eligible_product_total( WC()->cart, false );
        }

        return $this->calculator->get_available_redemptions(
            $this->points_manager->get_balance( get_current_user_id() ),
            $cart_subtotal
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
