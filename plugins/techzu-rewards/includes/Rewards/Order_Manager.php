<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Logger;
use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Order_Manager {
    const ORDER_META_AWARDED          = '_tz_rewards_awarded_points';
    const ORDER_META_AWARD_REVERSED   = '_tz_rewards_awarded_points_reversed';
    const ORDER_META_AWARD_ADJUSTED   = '_tz_rewards_awarded_points_adjusted';
    const ORDER_META_ELIGIBLE_TOTAL   = '_tz_rewards_eligible_paid_product_total';

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
     * Logger.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Settings           $settings Settings object.
     * @param Points_Manager     $points_manager Points manager.
     * @param Calculator         $calculator Calculator.
     * @param Redemption_Manager $redemption_manager Redemption manager.
     * @param Logger             $logger Logger.
     */
    public function __construct( Settings $settings, Points_Manager $points_manager, Calculator $calculator, Redemption_Manager $redemption_manager, Logger $logger ) {
        $this->settings           = $settings;
        $this->points_manager     = $points_manager;
        $this->calculator         = $calculator;
        $this->redemption_manager = $redemption_manager;
        $this->logger             = $logger;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 20, 4 );
        add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refunded' ), 20, 2 );
    }

    /**
     * Handle order status changes.
     *
     * @param int       $order_id Order ID.
     * @param string    $old_status Previous status.
     * @param string    $new_status New status.
     * @param \WC_Order $order Order object.
     * @return void
     */
    public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
        unset( $order_id, $old_status );

        if ( ! $this->is_enabled() || ! $order instanceof \WC_Order ) {
            return;
        }

        if ( in_array( $new_status, $this->get_earning_statuses(), true ) ) {
            $this->maybe_award_points( $order );
            return;
        }

        if ( in_array( $new_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
            $this->maybe_reverse_award( $order );
            $this->redemption_manager->maybe_restore_points( $order );
        }
    }

    /**
     * Award points if the order qualifies.
     *
     * @param \WC_Order $order Order.
     * @return void
     */
    public function maybe_award_points( $order ) {
        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) {
            return;
        }

        $awarded_points  = (int) $order->get_meta( self::ORDER_META_AWARDED, true );
        $reversed_points = (int) $order->get_meta( self::ORDER_META_AWARD_REVERSED, true );

        if ( $awarded_points > 0 && $reversed_points <= 0 ) {
            return;
        }

        if ( $awarded_points > 0 && $reversed_points > 0 ) {
            $this->points_manager->add_points(
                $user_id,
                $reversed_points,
                array(
                    'source'   => 'reapplied',
                    'order_id' => $order->get_id(),
                    'note'     => __( 'Reward points re-applied after order status changed back to earning status', 'techzu-rewards' ),
                )
            );
            $this->logger->log( 'points_reapplied', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'points' => $reversed_points ) );
            $order->update_meta_data( self::ORDER_META_AWARD_REVERSED, 0 );
            $order->save();
            $order->add_order_note( sprintf( esc_html__( 'Reward points re-applied: %d', 'techzu-rewards' ), $reversed_points ) );
            do_action( 'tz_rewards_points_awarded', $user_id, $reversed_points, $order->get_id(), 'reapplied' );
            return;
        }

        $eligible_total = $this->get_eligible_subtotal( $order );
        $points         = $this->calculator->calculate_points_for_amount( $eligible_total );

        if ( $points <= 0 ) {
            return;
        }

        $this->points_manager->add_points(
            $user_id,
            $points,
            array(
                'source'   => 'order',
                'order_id' => $order->get_id(),
                'note'     => __( 'Points earned from eligible paid product amount', 'techzu-rewards' ),
            )
        );
        $this->logger->log( 'points_awarded', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'points' => $points, 'eligible_total' => $eligible_total ) );
        $order->update_meta_data( self::ORDER_META_AWARDED, $points );
        $order->update_meta_data( self::ORDER_META_AWARD_REVERSED, 0 );
        $order->update_meta_data( self::ORDER_META_AWARD_ADJUSTED, 0 );
        $order->update_meta_data( self::ORDER_META_ELIGIBLE_TOTAL, wc_format_decimal( $eligible_total ) );
        $order->save();

        $order->add_order_note( sprintf( esc_html__( 'Reward points awarded: %1$d based on eligible paid product amount %2$s.', 'techzu-rewards' ), $points, wp_strip_all_tags( wc_price( $eligible_total ) ) ) );
        do_action( 'tz_rewards_points_awarded', $user_id, $points, $order->get_id(), 'awarded' );
    }

    /**
     * Reverse awarded points if the order later becomes invalid.
     *
     * @param \WC_Order $order Order.
     * @return void
     */
    public function maybe_reverse_award( $order ) {
        $user_id         = (int) $order->get_user_id();
        $awarded_points  = (int) $order->get_meta( self::ORDER_META_AWARDED, true );
        $reversed_points = (int) $order->get_meta( self::ORDER_META_AWARD_REVERSED, true );
        $adjusted_points = (int) $order->get_meta( self::ORDER_META_AWARD_ADJUSTED, true );
        $remaining       = max( 0, $awarded_points - $reversed_points - $adjusted_points );

        if ( $user_id <= 0 || $awarded_points <= 0 || $remaining <= 0 ) {
            return;
        }

        $this->points_manager->subtract_points(
            $user_id,
            $remaining,
            array(
                'source'   => 'reverse',
                'order_id' => $order->get_id(),
                'note'     => __( 'Points reversed because order became cancelled, failed or refunded', 'techzu-rewards' ),
            )
        );
        $this->logger->log( 'points_reversed', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'points' => $remaining ) );
        $order->update_meta_data( self::ORDER_META_AWARD_REVERSED, $reversed_points + $remaining );
        $order->save();

        $order->add_order_note( sprintf( esc_html__( 'Reward points reversed: %d', 'techzu-rewards' ), $remaining ) );
        do_action( 'tz_rewards_points_reversed', $user_id, $remaining, $order->get_id() );
    }

    /**
     * Adjust earned points on partial refunds.
     *
     * @param int $order_id Order ID.
     * @param int $refund_id Refund ID.
     * @return void
     */
    public function handle_order_refunded( $order_id, $refund_id ) {
        if ( ! $this->is_enabled() || ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );

        if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
            return;
        }

        $user_id         = (int) $order->get_user_id();
        $awarded_points  = (int) $order->get_meta( self::ORDER_META_AWARDED, true );
        $reversed_points = (int) $order->get_meta( self::ORDER_META_AWARD_REVERSED, true );
        $adjusted_points = (int) $order->get_meta( self::ORDER_META_AWARD_ADJUSTED, true );
        $remaining       = max( 0, $awarded_points - $reversed_points - $adjusted_points );

        if ( $user_id <= 0 || $awarded_points <= 0 || $remaining <= 0 ) {
            return;
        }

        $refunded_total = $this->get_refund_eligible_total( $refund );
        if ( $refunded_total <= 0 ) {
            return;
        }

        $points_to_reverse = min( $remaining, $this->calculator->calculate_points_for_amount( $refunded_total ) );
        if ( $points_to_reverse <= 0 ) {
            return;
        }

        $this->points_manager->subtract_points(
            $user_id,
            $points_to_reverse,
            array(
                'source'   => 'refund_adjustment',
                'order_id' => $order->get_id(),
                'note'     => __( 'Points adjusted after refund', 'techzu-rewards' ),
            )
        );

        $order->update_meta_data( self::ORDER_META_AWARD_ADJUSTED, $adjusted_points + $points_to_reverse );
        $order->save();
        $order->add_order_note( sprintf( esc_html__( 'Reward points adjusted after refund: %d', 'techzu-rewards' ), $points_to_reverse ) );
        $this->logger->log( 'points_refund_adjustment', array( 'user_id' => $user_id, 'order_id' => $order->get_id(), 'refund_id' => $refund_id, 'points' => $points_to_reverse, 'refunded_total' => $refunded_total ) );
    }

    /**
     * Get the order subtotal eligible for earning points.
     *
     * @param \WC_Order $order Order.
     * @return float
     */
    public function get_eligible_subtotal( $order ) {
        $subtotal = 0.0;
        $exclude  = 'yes' === $this->settings->get( 'exclude_sale_items', 'no' );

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            if ( $exclude && $product instanceof \WC_Product && $product->is_on_sale() ) {
                continue;
            }

            // get_total() is the line product total after product discounts, excluding shipping and fees.
            $subtotal += (float) $item->get_total();
        }

        if ( 'yes' === $this->settings->get( 'subtract_negative_fees', 'yes' ) ) {
            foreach ( $order->get_fees() as $fee ) {
                $fee_total = (float) $fee->get_total();
                if ( $fee_total < 0 ) {
                    $subtotal += $fee_total;
                }
            }
        }

        return max( 0, (float) apply_filters( 'tz_rewards_eligible_subtotal', $subtotal, $order ) );
    }

    /**
     * Get eligible product total from a refund object.
     *
     * @param \WC_Order_Refund $refund Refund.
     * @return float
     */
    protected function get_refund_eligible_total( $refund ) {
        $total = 0.0;

        foreach ( $refund->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $total += abs( (float) $item->get_total() );
        }

        return max( 0, (float) apply_filters( 'tz_rewards_refund_eligible_total', $total, $refund ) );
    }

    /**
     * Get statuses that can trigger earning.
     *
     * @return array<int,string>
     */
    protected function get_earning_statuses() {
        $statuses = $this->settings->get( 'earn_order_statuses', array( 'processing', 'completed' ) );
        return is_array( $statuses ) ? array_values( array_unique( $statuses ) ) : array();
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
