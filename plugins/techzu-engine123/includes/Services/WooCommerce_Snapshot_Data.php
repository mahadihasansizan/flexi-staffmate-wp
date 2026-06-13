<?php
namespace Techzu\Engine\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves WooCommerce order metrics using Analytics (wc_order_stats) when possible,
 * with a live order-store fallback when reports are empty or unavailable.
 */
class WooCommerce_Snapshot_Data {
    const CACHE_GROUP = 'tz_engine_wc_snapshot';
    const CACHE_TTL   = 120;

    /**
     * @return array{today: array, month: array, source: string}
     */
    public function get_snapshot() {
        $ranges    = self::get_date_ranges();
        $bust      = (string) get_option( 'tz_engine_wc_snap_ver', '1' );
        $cache_key = 'tz_engine_wc_snap_' . md5( wp_json_encode( $ranges ) . '|' . $bust );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $today  = $this->resolve_period( $ranges['today_after'], $ranges['today_before'] );
        $month  = $this->resolve_period( $ranges['month_after'], $ranges['month_before'] );

        $reviews_month = $this->count_product_reviews_in_range(
            $ranges['month_start_mysql'],
            $ranges['month_end_mysql']
        );

        $out = array(
            'today'               => $today,
            'month'               => $month,
            'reviews_this_month'  => $reviews_month,
            'source'              => $today['source'],
        );

        wp_cache_set( $cache_key, $out, self::CACHE_GROUP, self::CACHE_TTL );

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function get_date_ranges() {
        $tz_string = function_exists( 'wc_timezone_string' ) ? wc_timezone_string() : wp_timezone_string();
        $tz        = $tz_string ? new \DateTimeZone( $tz_string ) : wp_timezone();

        $today_start = new \DateTimeImmutable( 'today', $tz );
        $today_end   = $today_start->modify( '+1 day' )->modify( '-1 second' );

        $month_start = new \DateTimeImmutable( 'first day of this month 00:00:00', $tz );
        $month_end   = new \DateTimeImmutable( 'last day of this month 23:59:59', $tz );

        return array(
            'today_after'       => $today_start->format( 'Y-m-d\TH:i:s' ),
            'today_before'      => $today_end->format( 'Y-m-d\TH:i:s' ),
            'month_after'       => $month_start->format( 'Y-m-d\TH:i:s' ),
            'month_before'      => $month_end->format( 'Y-m-d\TH:i:s' ),
            'month_start_mysql' => $month_start->format( 'Y-m-d H:i:s' ),
            'month_end_mysql'   => $month_end->format( 'Y-m-d H:i:s' ),
        );
    }

    /**
     * @param string $after Local datetime string for WC reports.
     * @param string $before Local datetime string for WC reports.
     * @return array{orders:int, revenue:float, items:int, source:string}
     */
    protected function resolve_period( $after, $before ) {
        $from_analytics = $this->query_orders_stats_report( $after, $before );
        $from_orders    = $this->aggregate_orders_direct( $after, $before );

        if ( null === $from_analytics ) {
            return array(
                'orders'  => $from_orders['orders'],
                'revenue' => $from_orders['revenue'],
                'items'   => $from_orders['items'],
                'source'  => 'orders',
            );
        }

        $a_orders  = (int) ( $from_analytics['orders_count'] ?? 0 );
        $a_revenue = (float) ( $from_analytics['net_revenue'] ?? 0 );
        $a_items   = (int) ( $from_analytics['num_items_sold'] ?? 0 );

        if ( $a_orders === 0 && $from_orders['orders'] > 0 ) {
            return array(
                'orders'  => $from_orders['orders'],
                'revenue' => $from_orders['revenue'],
                'items'   => $from_orders['items'],
                'source'  => 'orders_fallback',
            );
        }

        return array(
            'orders'  => $a_orders,
            'revenue' => $a_revenue,
            'items'   => $a_items,
            'source'  => 'analytics',
        );
    }

    /**
     * @param string $after After (local).
     * @param string $before Before (local).
     * @return array<string, float|int>|null
     */
    protected function query_orders_stats_report( $after, $before ) {
        if ( ! class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\Query' ) ) {
            return null;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->is_wc_admin_active() ) {
            return null;
        }

        try {
            $query = new \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\Query(
                array(
                    'after'     => $after,
                    'before'    => $before,
                    'interval'  => 'day',
                    'per_page'  => 100,
                    'page'      => 1,
                    'fields'    => array(
                        'orders_count',
                        'net_revenue',
                        'num_items_sold',
                    ),
                )
            );
            $data = $query->get_data();
        } catch ( \Throwable $e ) {
            return null;
        }

        if ( is_wp_error( $data ) || ! is_object( $data ) || empty( $data->totals ) ) {
            return null;
        }

        $totals = (array) $data->totals;

        return array(
            'orders_count'   => isset( $totals['orders_count'] ) ? (int) $totals['orders_count'] : 0,
            'net_revenue'    => isset( $totals['net_revenue'] ) ? (float) $totals['net_revenue'] : 0.0,
            'num_items_sold' => isset( $totals['num_items_sold'] ) ? (int) $totals['num_items_sold'] : 0,
        );
    }

    /**
     * Live aggregate from the order data store (HPOS-safe via wc_get_orders).
     *
     * @param string $after Local WC-format start.
     * @param string $before Local WC-format end.
     * @return array{orders:int, revenue:float, items:int}
     */
    protected function aggregate_orders_direct( $after, $before ) {
        $statuses = apply_filters(
            'tz_engine_wc_snapshot_order_statuses',
            function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' )
        );

        $tz_string = function_exists( 'wc_timezone_string' ) ? wc_timezone_string() : wp_timezone_string();
        $tz        = $tz_string ? new \DateTimeZone( $tz_string ) : wp_timezone();

        $after_dt  = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s', $after, $tz );
        $before_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s', $before, $tz );

        if ( ! $after_dt || ! $before_dt ) {
            return array( 'orders' => 0, 'revenue' => 0.0, 'items' => 0 );
        }

        $range = $after_dt->format( 'Y-m-d H:i:s' ) . '...' . $before_dt->format( 'Y-m-d H:i:s' );

        $limit = (int) apply_filters( 'tz_engine_wc_snapshot_order_query_limit', 3000 );

        $args = array(
            'status'       => $statuses,
            'date_created' => $range,
            'limit'        => max( 1, $limit ),
            'return'       => 'objects',
            'type'         => 'shop_order',
        );

        $orders = wc_get_orders( $args );

        if ( ! is_array( $orders ) ) {
            return array( 'orders' => 0, 'revenue' => 0.0, 'items' => 0 );
        }

        $count   = count( $orders );
        $revenue = 0.0;
        $items   = 0;

        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }
            $revenue += (float) $order->get_total();
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $items += (int) $item->get_quantity();
            }
        }

        return array(
            'orders'  => $count,
            'revenue' => $revenue,
            'items'   => $items,
        );
    }

    /**
     * @param string $start_mysql Local datetime.
     * @param string $end_mysql Local datetime.
     * @return int
     */
    protected function count_product_reviews_in_range( $start_mysql, $end_mysql ) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
			WHERE c.comment_approved = '1'
			AND c.comment_type = 'review'
			AND p.post_type = 'product'
			AND c.comment_date_gmt >= %s
			AND c.comment_date_gmt <= %s",
            get_gmt_from_date( $start_mysql ),
            get_gmt_from_date( $end_mysql )
        );

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * @return string
     */
    public static function get_analytics_url() {
        return admin_url( 'admin.php?page=wc-admin&path=/analytics/overview' );
    }

    /**
     * @return string
     */
    public static function get_revenue_analytics_url() {
        return admin_url( 'admin.php?page=wc-admin&path=/analytics/revenue' );
    }
}
