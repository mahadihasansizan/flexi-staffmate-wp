<?php
namespace Techzu\Rewards\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Maintenance {
    const CRON_HOOK = 'tz_rewards_daily_maintenance';

    /**
     * Points manager.
     *
     * @var Points_Manager
     */
    protected $points_manager;

    /**
     * Constructor.
     *
     * @param Points_Manager $points_manager Points manager.
     */
    public function __construct( Points_Manager $points_manager ) {
        $this->points_manager = $points_manager;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'init', array( __CLASS__, 'schedule' ) );
        add_action( self::CRON_HOOK, array( $this, 'expire_all_user_points' ) );
    }

    /**
     * Schedule daily maintenance.
     *
     * @return void
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Clear scheduled maintenance.
     *
     * @return void
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Expire point lots for users with point lots.
     *
     * @return void
     */
    public function expire_all_user_points() {
        $query = new \WP_User_Query(
            array(
                'number'     => 200,
                'fields'     => 'ID',
                'meta_query' => array(
                    array(
                        'key'     => Points_Manager::LOTS_META_KEY,
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        foreach ( $query->get_results() as $user_id ) {
            $this->points_manager->expire_points( absint( $user_id ) );
        }
    }
}
