<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tier_Manager {
    const USER_META_BIRTHDAY        = 'tz_rewards_birthday';
    const USER_META_MANUAL_TIER     = 'tz_rewards_manual_tier';
    const USER_META_LAST_AUTO_TIER  = 'tz_rewards_last_auto_tier';

    /**
     * Settings instance.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings object.
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register hooks for automatic tier assignment and syncing.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'user_register', array( $this, 'assign_default_tier_on_registration' ), 20 );
        add_action( 'woocommerce_created_customer', array( $this, 'assign_default_tier_on_registration' ), 20 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_sync_tier_after_order_status' ), 30, 4 );
        add_action( 'woocommerce_order_refunded', array( $this, 'maybe_sync_tier_after_refund' ), 30, 2 );
    }

    /**
     * Store Bronze/default tier when a customer account is created.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function assign_default_tier_on_registration( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 || '' !== get_user_meta( $user_id, self::USER_META_LAST_AUTO_TIER, true ) ) {
            return;
        }

        $tier = $this->get_customer_tier( $user_id );
        update_user_meta( $user_id, self::USER_META_LAST_AUTO_TIER, sanitize_key( $tier['key'] ) );
        do_action( 'tz_rewards_customer_tier_assigned', $user_id, $tier );
    }

    /**
     * Sync tier after an order status change that may affect rolling 12-month spend.
     *
     * @param int       $order_id Order ID.
     * @param string    $old_status Old status.
     * @param string    $new_status New status.
     * @param \WC_Order $order Order object.
     * @return void
     */
    public function maybe_sync_tier_after_order_status( $order_id, $old_status, $new_status, $order ) {
        unset( $order_id, $old_status );

        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) {
            return;
        }

        $statuses = $this->settings->get( 'tier_order_statuses', array( 'processing', 'completed' ) );
        if ( ! is_array( $statuses ) ) {
            $statuses = array( 'processing', 'completed' );
        }

        if ( in_array( $new_status, $statuses, true ) || in_array( $new_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
            $this->sync_customer_tier( $user_id, 'order_status_' . sanitize_key( $new_status ) );
        }
    }

    /**
     * Sync tier after a refund.
     *
     * @param int $order_id Order ID.
     * @param int $refund_id Refund ID.
     * @return void
     */
    public function maybe_sync_tier_after_refund( $order_id, $refund_id ) {
        unset( $refund_id );

        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id > 0 ) {
            $this->sync_customer_tier( $user_id, 'order_refund' );
        }
    }

    /**
     * Compare stored tier with current calculated tier and notify on changes.
     *
     * @param int    $user_id User ID.
     * @param string $context Sync context.
     * @return array<string,mixed> Current tier.
     */
    public function sync_customer_tier( $user_id, $context = 'sync' ) {
        $user_id = absint( $user_id );
        $current = $this->get_customer_tier( $user_id );

        if ( $user_id <= 0 ) {
            return $current;
        }

        $current_key = sanitize_key( isset( $current['key'] ) ? $current['key'] : '' );
        $old_key     = sanitize_key( get_user_meta( $user_id, self::USER_META_LAST_AUTO_TIER, true ) );

        if ( '' === $old_key ) {
            update_user_meta( $user_id, self::USER_META_LAST_AUTO_TIER, $current_key );
            return $current;
        }

        if ( $current_key !== $old_key ) {
            $old_tier = $this->get_tier_by_key( $old_key );
            update_user_meta( $user_id, self::USER_META_LAST_AUTO_TIER, $current_key );
            do_action( 'tz_rewards_customer_tier_updated', $user_id, $old_tier, $current, sanitize_key( $context ) );
        }

        return $current;
    }

    /**
     * Get all enabled membership tiers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_tiers() {
        $tiers   = Settings::normalize_membership_tiers( $this->settings->get( 'membership_tiers', array() ) );
        $enabled = array();

        foreach ( $tiers as $tier ) {
            if ( 'yes' === $tier['enabled'] ) {
                $enabled[] = $tier;
            }
        }

        return $enabled;
    }

    /**
     * Get all tiers, including disabled, for admin display.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_all_tiers() {
        return Settings::normalize_membership_tiers( $this->settings->get( 'membership_tiers', array() ) );
    }

    /**
     * Get a tier by key.
     *
     * @param string $key Tier key.
     * @return array<string,mixed>|null
     */
    public function get_tier_by_key( $key ) {
        $key = sanitize_key( $key );
        foreach ( $this->get_all_tiers() as $tier ) {
            if ( $key === $tier['key'] ) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Get tier options.
     *
     * @param bool $include_empty Include the automatic option.
     * @return array<string,string>
     */
    public function get_tier_options( $include_empty = false ) {
        $options = array();
        if ( $include_empty ) {
            $options[''] = __( 'Automatic', 'techzu-rewards' );
        }

        foreach ( $this->get_all_tiers() as $tier ) {
            $options[ $tier['key'] ] = $tier['name'];
        }

        return $options;
    }

    /**
     * Get the current customer tier.
     *
     * @param int $user_id User ID.
     * @return array<string,mixed>
     */
    public function get_customer_tier( $user_id ) {
        $manual_key = sanitize_key( get_user_meta( absint( $user_id ), self::USER_META_MANUAL_TIER, true ) );
        if ( '' !== $manual_key ) {
            $manual = $this->get_tier_by_key( $manual_key );
            if ( is_array( $manual ) && 'yes' === $manual['enabled'] ) {
                $manual['manual'] = true;
                return $manual;
            }
        }

        $spend = $this->get_customer_eligible_spend( $user_id );
        $tiers = $this->get_tiers();
        $match = null;

        foreach ( $tiers as $tier ) {
            if ( $spend + 0.0001 >= (float) $tier['spend_threshold'] ) {
                $match = $tier;
            }
        }

        if ( ! $match && ! empty( $tiers ) ) {
            $match = reset( $tiers );
        }

        if ( ! is_array( $match ) ) {
            $match = array(
                'enabled'           => 'yes',
                'key'               => 'member',
                'name'              => __( 'Member', 'techzu-rewards' ),
                'qualification'     => __( 'Create a customer account', 'techzu-rewards' ),
                'spend_threshold'   => 0,
                'birthday_discount' => 0,
            );
        }

        $match['manual'] = false;
        return $match;
    }

    /**
     * Get next tier progress.
     *
     * @param int $user_id User ID.
     * @return array<string,mixed>
     */
    public function get_next_tier_progress( $user_id ) {
        $spend   = $this->get_customer_eligible_spend( $user_id );
        $current = $this->get_customer_tier( $user_id );
        $next    = null;

        foreach ( $this->get_tiers() as $tier ) {
            if ( (float) $tier['spend_threshold'] > (float) $current['spend_threshold'] ) {
                $next = $tier;
                break;
            }
        }

        if ( ! $next ) {
            return array(
                'current_spend' => $spend,
                'next_tier'     => null,
                'remaining'     => 0,
                'percent'       => 100,
            );
        }

        $threshold = (float) $next['spend_threshold'];
        $remaining = max( 0, $threshold - $spend );
        $percent   = $threshold > 0 ? min( 100, max( 0, ( $spend / $threshold ) * 100 ) ) : 100;

        return array(
            'current_spend' => $spend,
            'next_tier'     => $next,
            'remaining'     => $remaining,
            'percent'       => $percent,
        );
    }

    /**
     * Get customer spend within the tier window.
     *
     * @param int $user_id User ID.
     * @return float
     */
    public function get_customer_eligible_spend( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
            return 0.0;
        }

        $months   = max( 1, (int) $this->settings->get( 'tier_window_months', 12 ) );
        $since_ts = strtotime( '-' . $months . ' months', $this->now_timestamp() );
        $statuses = $this->settings->get( 'tier_order_statuses', array( 'processing', 'completed' ) );
        if ( ! is_array( $statuses ) || empty( $statuses ) ) {
            $statuses = array( 'processing', 'completed' );
        }

        $orders = wc_get_orders(
            array(
                'customer_id' => $user_id,
                'status'      => $statuses,
                'limit'       => -1,
                'return'      => 'objects',
                'date_created'=> '>' . gmdate( 'Y-m-d H:i:s', $since_ts ),
            )
        );

        $spend = 0.0;
        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }

            $spend += $this->get_order_eligible_product_total( $order );
        }

        return (float) apply_filters( 'tz_rewards_customer_tier_spend', max( 0, $spend ), $user_id, $months );
    }

    /**
     * Calculate eligible paid product total for tier qualification.
     *
     * @param \WC_Order $order Order object.
     * @return float
     */
    public function get_order_eligible_product_total( $order ) {
        $total   = 0.0;
        $exclude = 'yes' === $this->settings->get( 'exclude_sale_items', 'no' );

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            if ( $exclude && $product instanceof \WC_Product && $product->is_on_sale() ) {
                continue;
            }

            $total += (float) $item->get_total();
        }

        if ( 'yes' === $this->settings->get( 'subtract_negative_fees', 'yes' ) ) {
            foreach ( $order->get_fees() as $fee ) {
                $fee_total = (float) $fee->get_total();
                if ( $fee_total < 0 ) {
                    $total += $fee_total;
                }
            }
        }

        return max( 0, (float) apply_filters( 'tz_rewards_tier_order_eligible_total', $total, $order ) );
    }

    /**
     * Save customer birthday.
     *
     * @param int    $user_id User ID.
     * @param string $date Date in Y-m-d.
     * @return void
     */
    public function set_birthday( $user_id, $date ) {
        $date = $this->sanitize_date( $date );
        if ( '' === $date ) {
            delete_user_meta( absint( $user_id ), self::USER_META_BIRTHDAY );
            return;
        }

        update_user_meta( absint( $user_id ), self::USER_META_BIRTHDAY, $date );
    }

    /**
     * Get customer birthday.
     *
     * @param int $user_id User ID.
     * @return string
     */
    public function get_birthday( $user_id ) {
        return $this->sanitize_date( get_user_meta( absint( $user_id ), self::USER_META_BIRTHDAY, true ) );
    }

    /**
     * Save manual tier override.
     *
     * @param int    $user_id User ID.
     * @param string $tier_key Tier key or empty.
     * @return void
     */
    public function set_manual_tier( $user_id, $tier_key ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return;
        }

        $before  = $this->get_customer_tier( $user_id );
        $tier_key = sanitize_key( $tier_key );
        $context  = 'manual_tier_set';

        if ( '' === $tier_key || ! $this->get_tier_by_key( $tier_key ) ) {
            delete_user_meta( $user_id, self::USER_META_MANUAL_TIER );
            $context = 'manual_tier_removed';
        } else {
            update_user_meta( $user_id, self::USER_META_MANUAL_TIER, $tier_key );
        }

        $after      = $this->get_customer_tier( $user_id );
        $before_key = sanitize_key( isset( $before['key'] ) ? $before['key'] : '' );
        $after_key  = sanitize_key( isset( $after['key'] ) ? $after['key'] : '' );

        update_user_meta( $user_id, self::USER_META_LAST_AUTO_TIER, $after_key );

        if ( $before_key !== $after_key ) {
            do_action( 'tz_rewards_customer_tier_updated', $user_id, $before, $after, $context );
        }
    }

    /**
     * Get manual tier override.
     *
     * @param int $user_id User ID.
     * @return string
     */
    public function get_manual_tier( $user_id ) {
        return sanitize_key( get_user_meta( absint( $user_id ), self::USER_META_MANUAL_TIER, true ) );
    }

    /**
     * Sanitize a date.
     *
     * @param mixed $date Raw date.
     * @return string
     */
    protected function sanitize_date( $date ) {
        $date = sanitize_text_field( (string) $date );
        if ( '' === $date ) {
            return '';
        }

        $timestamp = strtotime( $date );
        if ( false === $timestamp ) {
            return '';
        }

        return gmdate( 'Y-m-d', $timestamp );
    }

    /**
     * Get current timestamp.
     *
     * @return int
     */
    protected function now_timestamp() {
        return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
    }
}
