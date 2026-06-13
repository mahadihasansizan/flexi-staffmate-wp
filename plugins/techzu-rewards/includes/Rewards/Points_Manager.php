<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Points_Manager {
    const USER_META_KEY             = 'tz_rewards_points_balance';
    const LOTS_META_KEY             = 'tz_rewards_points_lots';
    const LOG_META_KEY              = 'tz_rewards_points_log';
    const LEGACY_MIGRATED_META_KEY  = 'tz_rewards_legacy_points_migrated';

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
     * Get user balance from unexpired point lots.
     *
     * @param int $user_id User ID.
     * @return int
     */
    public function get_balance( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return 0;
        }

        $this->maybe_migrate_legacy_balance( $user_id );
        $this->expire_points( $user_id );

        $balance = $this->calculate_lot_balance( $this->get_lots( $user_id ) );
        update_user_meta( $user_id, self::USER_META_KEY, $balance );

        return $balance;
    }

    /**
     * Set an exact balance by creating a positive or negative manual adjustment.
     *
     * @param int    $user_id User ID.
     * @param int    $points New balance.
     * @param string $note Admin note.
     * @return int
     */
    public function set_balance( $user_id, $points, $note = '' ) {
        $target  = max( 0, (int) $points );
        $current = $this->get_balance( $user_id );
        $diff    = $target - $current;

        if ( $diff > 0 ) {
            $this->add_points(
                $user_id,
                $diff,
                array(
                    'source' => 'manual',
                    'note'   => $note ? $note : __( 'Manual balance increase', 'techzu-rewards' ),
                )
            );
        } elseif ( $diff < 0 ) {
            $this->subtract_points(
                $user_id,
                absint( $diff ),
                array(
                    'source' => 'manual',
                    'note'   => $note ? $note : __( 'Manual balance decrease', 'techzu-rewards' ),
                )
            );
        }

        if ( 0 !== $diff ) {
            do_action( 'tz_rewards_points_manually_adjusted', $user_id, $diff, $target, $note );
        }

        return $this->get_balance( $user_id );
    }

    /**
     * Add expirable points to the balance.
     *
     * @param int                 $user_id User ID.
     * @param int                 $points Points to add.
     * @param array<string,mixed> $context Context data.
     * @return int
     */
    public function add_points( $user_id, $points, $context = array() ) {
        $user_id = absint( $user_id );
        $points  = absint( $points );

        if ( $user_id <= 0 || $points <= 0 ) {
            return $this->get_balance( $user_id );
        }

        $this->maybe_migrate_legacy_balance( $user_id );
        $this->expire_points( $user_id );

        $now         = $this->now_mysql();
        $expires_at  = $this->get_expiry_mysql();
        $source      = isset( $context['source'] ) ? sanitize_key( $context['source'] ) : 'earn';
        $order_id    = isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0;
        $note        = isset( $context['note'] ) ? sanitize_text_field( $context['note'] ) : '';
        $id          = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'tz_points_', true );

        $lots   = $this->get_lots( $user_id );
        $lots[] = array(
            'id'         => $id,
            'points'     => $points,
            'remaining'  => $points,
            'earned_at'  => $now,
            'expires_at' => $expires_at,
            'order_id'   => $order_id,
            'source'     => $source,
            'note'       => $note,
        );

        $this->save_lots( $user_id, $lots );
        $this->add_log(
            $user_id,
            array(
                'type'       => $source,
                'points'     => $points,
                'order_id'   => $order_id,
                'note'       => $note,
                'lot_id'     => $id,
                'expires_at' => $expires_at,
            )
        );

        return $this->get_balance( $user_id );
    }

    /**
     * Subtract points using FIFO from the oldest unexpired lots.
     *
     * @param int                 $user_id User ID.
     * @param int                 $points Points to subtract.
     * @param array<string,mixed> $context Context data.
     * @return int
     */
    public function subtract_points( $user_id, $points, $context = array() ) {
        $user_id = absint( $user_id );
        $points  = absint( $points );

        if ( $user_id <= 0 || $points <= 0 ) {
            return $this->get_balance( $user_id );
        }

        $this->maybe_migrate_legacy_balance( $user_id );
        $this->expire_points( $user_id );

        $lots      = $this->get_lots( $user_id );
        $remaining = $points;
        $now_ts    = $this->now_timestamp();

        usort(
            $lots,
            static function ( $left, $right ) {
                return strcmp( (string) $left['earned_at'], (string) $right['earned_at'] );
            }
        );

        foreach ( $lots as $index => $lot ) {
            if ( $remaining <= 0 ) {
                break;
            }

            $lot_remaining = isset( $lot['remaining'] ) ? absint( $lot['remaining'] ) : 0;
            $expires_at    = isset( $lot['expires_at'] ) ? (string) $lot['expires_at'] : '';

            if ( $lot_remaining <= 0 || $this->is_expired_mysql( $expires_at, $now_ts ) ) {
                continue;
            }

            $consume = min( $lot_remaining, $remaining );
            $lots[ $index ]['remaining'] = $lot_remaining - $consume;
            $remaining -= $consume;
        }

        $subtracted = $points - $remaining;
        $this->save_lots( $user_id, $lots );

        if ( $subtracted > 0 ) {
            $this->add_log(
                $user_id,
                array(
                    'type'     => isset( $context['source'] ) ? sanitize_key( $context['source'] ) : 'redeem',
                    'points'   => -1 * $subtracted,
                    'order_id' => isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0,
                    'note'     => isset( $context['note'] ) ? sanitize_text_field( $context['note'] ) : '',
                )
            );
        }

        return $this->get_balance( $user_id );
    }

    /**
     * Expire old point lots and write an audit entry.
     *
     * @param int $user_id User ID.
     * @return int Expired points.
     */
    public function expire_points( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return 0;
        }

        $lots    = $this->get_lots( $user_id );
        $expired = 0;
        $now_ts  = $this->now_timestamp();

        foreach ( $lots as $index => $lot ) {
            $remaining  = isset( $lot['remaining'] ) ? absint( $lot['remaining'] ) : 0;
            $expires_at = isset( $lot['expires_at'] ) ? (string) $lot['expires_at'] : '';

            if ( $remaining <= 0 || ! $this->is_expired_mysql( $expires_at, $now_ts ) ) {
                continue;
            }

            $lots[ $index ]['remaining'] = 0;
            $expired += $remaining;

            $this->add_log(
                $user_id,
                array(
                    'type'       => 'expire',
                    'points'     => -1 * $remaining,
                    'order_id'   => isset( $lot['order_id'] ) ? absint( $lot['order_id'] ) : 0,
                    'note'       => __( 'Points expired', 'techzu-rewards' ),
                    'lot_id'     => isset( $lot['id'] ) ? sanitize_text_field( $lot['id'] ) : '',
                    'expires_at' => $expires_at,
                )
            );
        }

        if ( $expired > 0 ) {
            $this->save_lots( $user_id, $lots );
            update_user_meta( $user_id, self::USER_META_KEY, $this->calculate_lot_balance( $lots ) );
            do_action( 'tz_rewards_points_expired', $user_id, $expired );
        }

        return $expired;
    }

    /**
     * Get active point lots expiring within a number of days and not already notified.
     *
     * @param int  $user_id User ID.
     * @param int  $days Number of days to look ahead.
     * @param bool $only_unsent Whether to exclude lots already notified.
     * @return array<int,array<string,mixed>>
     */
    public function get_expiring_lots( $user_id, $days = 30, $only_unsent = true ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return array();
        }

        $this->maybe_migrate_legacy_balance( $user_id );
        $lots      = $this->get_lots( $user_id );
        $now_ts    = $this->now_timestamp();
        $cutoff_ts = $now_ts + ( max( 1, absint( $days ) ) * DAY_IN_SECONDS );
        $matches   = array();

        foreach ( $lots as $lot ) {
            $remaining  = isset( $lot['remaining'] ) ? absint( $lot['remaining'] ) : 0;
            $expires_at = isset( $lot['expires_at'] ) ? (string) $lot['expires_at'] : '';

            if ( $remaining <= 0 || '' === $expires_at ) {
                continue;
            }

            if ( $only_unsent && ! empty( $lot['expiry_notice_sent_at'] ) ) {
                continue;
            }

            $expiry_ts = strtotime( $expires_at . ' UTC' );
            if ( false === $expiry_ts || $expiry_ts <= $now_ts || $expiry_ts > $cutoff_ts ) {
                continue;
            }

            $matches[] = $lot;
        }

        return $matches;
    }

    /**
     * Mark point lots as having received an expiry-soon email.
     *
     * @param int              $user_id User ID.
     * @param array<int,mixed> $lot_ids Lot IDs.
     * @return void
     */
    public function mark_expiry_notice_sent( $user_id, $lot_ids ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 || ! is_array( $lot_ids ) || empty( $lot_ids ) ) {
            return;
        }

        $ids  = array_map( 'sanitize_text_field', $lot_ids );
        $lots = $this->get_lots( $user_id );
        $now  = $this->now_mysql();

        foreach ( $lots as $index => $lot ) {
            $id = isset( $lot['id'] ) ? sanitize_text_field( $lot['id'] ) : '';
            if ( '' !== $id && in_array( $id, $ids, true ) ) {
                $lots[ $index ]['expiry_notice_sent_at'] = $now;
            }
        }

        $this->save_lots( $user_id, $lots );
    }

    /**
     * Get point lots for display.
     *
     * @param int $user_id User ID.
     * @return array<int,array<string,mixed>>
     */
    public function get_lots_for_display( $user_id ) {
        $this->maybe_migrate_legacy_balance( $user_id );
        $this->expire_points( $user_id );
        $lots = $this->get_lots( $user_id );

        usort(
            $lots,
            static function ( $left, $right ) {
                return strcmp( (string) $left['expires_at'], (string) $right['expires_at'] );
            }
        );

        return $lots;
    }

    /**
     * Get user audit log.
     *
     * @param int $user_id User ID.
     * @param int $limit Maximum rows.
     * @return array<int,array<string,mixed>>
     */
    public function get_log( $user_id, $limit = 50 ) {
        $log = get_user_meta( absint( $user_id ), self::LOG_META_KEY, true );
        if ( ! is_array( $log ) ) {
            return array();
        }

        $log = array_reverse( $log );
        return array_slice( $log, 0, max( 1, absint( $limit ) ) );
    }

    /**
     * Whether the plugin is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return 'yes' === $this->settings->get( 'enabled', 'yes' );
    }

    /**
     * Migrate the original single-balance meta to a lot once.
     *
     * @param int $user_id User ID.
     * @return void
     */
    protected function maybe_migrate_legacy_balance( $user_id ) {
        if ( 'yes' === get_user_meta( $user_id, self::LEGACY_MIGRATED_META_KEY, true ) ) {
            return;
        }

        $lots = $this->get_lots( $user_id );
        if ( ! empty( $lots ) ) {
            update_user_meta( $user_id, self::LEGACY_MIGRATED_META_KEY, 'yes' );
            return;
        }

        $legacy_balance = max( 0, (int) get_user_meta( $user_id, self::USER_META_KEY, true ) );
        if ( $legacy_balance <= 0 ) {
            update_user_meta( $user_id, self::LEGACY_MIGRATED_META_KEY, 'yes' );
            return;
        }

        $id    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'tz_legacy_', true );
        $lots  = array(
            array(
                'id'         => $id,
                'points'     => $legacy_balance,
                'remaining'  => $legacy_balance,
                'earned_at'  => $this->now_mysql(),
                'expires_at' => $this->get_expiry_mysql(),
                'order_id'   => 0,
                'source'     => 'legacy',
                'note'       => __( 'Migrated legacy balance', 'techzu-rewards' ),
            ),
        );

        $this->save_lots( $user_id, $lots );
        $this->add_log(
            $user_id,
            array(
                'type'       => 'legacy',
                'points'     => $legacy_balance,
                'order_id'   => 0,
                'note'       => __( 'Migrated legacy balance', 'techzu-rewards' ),
                'lot_id'     => $id,
                'expires_at' => $this->get_expiry_mysql(),
            )
        );
        update_user_meta( $user_id, self::LEGACY_MIGRATED_META_KEY, 'yes' );
    }

    /**
     * Read lots.
     *
     * @param int $user_id User ID.
     * @return array<int,array<string,mixed>>
     */
    protected function get_lots( $user_id ) {
        $lots = get_user_meta( absint( $user_id ), self::LOTS_META_KEY, true );
        return is_array( $lots ) ? $lots : array();
    }

    /**
     * Save lots.
     *
     * @param int                          $user_id User ID.
     * @param array<int,array<string,mixed>> $lots Lots.
     * @return void
     */
    protected function save_lots( $user_id, $lots ) {
        update_user_meta( absint( $user_id ), self::LOTS_META_KEY, array_values( $lots ) );
    }

    /**
     * Add log entry.
     *
     * @param int                 $user_id User ID.
     * @param array<string,mixed> $entry Entry.
     * @return void
     */
    protected function add_log( $user_id, $entry ) {
        $log = get_user_meta( absint( $user_id ), self::LOG_META_KEY, true );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        $log[] = array(
            'date'       => $this->now_mysql(),
            'type'       => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : 'adjust',
            'points'     => isset( $entry['points'] ) ? (int) $entry['points'] : 0,
            'order_id'   => isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0,
            'note'       => isset( $entry['note'] ) ? sanitize_text_field( $entry['note'] ) : '',
            'lot_id'     => isset( $entry['lot_id'] ) ? sanitize_text_field( $entry['lot_id'] ) : '',
            'expires_at' => isset( $entry['expires_at'] ) ? sanitize_text_field( $entry['expires_at'] ) : '',
        );

        $max_entries = 300;
        if ( count( $log ) > $max_entries ) {
            $log = array_slice( $log, -1 * $max_entries );
        }

        update_user_meta( absint( $user_id ), self::LOG_META_KEY, $log );
    }

    /**
     * Calculate active balance from lots.
     *
     * @param array<int,array<string,mixed>> $lots Lots.
     * @return int
     */
    protected function calculate_lot_balance( $lots ) {
        $balance = 0;
        $now_ts  = $this->now_timestamp();

        foreach ( $lots as $lot ) {
            $remaining  = isset( $lot['remaining'] ) ? absint( $lot['remaining'] ) : 0;
            $expires_at = isset( $lot['expires_at'] ) ? (string) $lot['expires_at'] : '';

            if ( $remaining <= 0 || $this->is_expired_mysql( $expires_at, $now_ts ) ) {
                continue;
            }

            $balance += $remaining;
        }

        return max( 0, (int) $balance );
    }

    /**
     * Get current timestamp in GMT.
     *
     * @return int
     */
    protected function now_timestamp() {
        return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
    }

    /**
     * Get current MySQL date in GMT.
     *
     * @return string
     */
    protected function now_mysql() {
        return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * Get expiry date.
     *
     * @return string Empty string means never expires.
     */
    protected function get_expiry_mysql() {
        $months = (int) $this->settings->get( 'points_expiry_months', 12 );
        if ( $months <= 0 ) {
            return '';
        }

        return gmdate( 'Y-m-d H:i:s', strtotime( '+' . $months . ' months', $this->now_timestamp() ) );
    }

    /**
     * Determine whether a MySQL GMT date has expired.
     *
     * @param string $mysql MySQL date.
     * @param int    $now_ts Current timestamp.
     * @return bool
     */
    protected function is_expired_mysql( $mysql, $now_ts ) {
        if ( '' === $mysql ) {
            return false;
        }

        $expiry_ts = strtotime( $mysql . ' UTC' );
        if ( false === $expiry_ts ) {
            return false;
        }

        return $expiry_ts <= $now_ts;
    }
}
