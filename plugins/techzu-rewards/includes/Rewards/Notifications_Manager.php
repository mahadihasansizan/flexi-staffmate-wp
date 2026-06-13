<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notifications_Manager {
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
     * Tier manager.
     *
     * @var Tier_Manager
     */
    protected $tier_manager;

    /**
     * Constructor.
     *
     * @param Settings       $settings Settings object.
     * @param Points_Manager $points_manager Points manager.
     * @param Tier_Manager   $tier_manager Tier manager.
     */
    public function __construct( Settings $settings, Points_Manager $points_manager, Tier_Manager $tier_manager ) {
        $this->settings       = $settings;
        $this->points_manager = $points_manager;
        $this->tier_manager   = $tier_manager;
    }

    /**
     * Register customer email hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'tz_rewards_customer_tier_assigned', array( $this, 'send_welcome_email' ), 10, 2 );
        add_action( 'tz_rewards_customer_tier_updated', array( $this, 'send_tier_updated_email' ), 10, 4 );
        add_action( 'tz_rewards_points_awarded', array( $this, 'send_points_earned_email' ), 10, 4 );
        add_action( 'tz_rewards_points_redeemed', array( $this, 'send_points_used_email' ), 10, 3 );
        add_action( 'tz_rewards_points_expired', array( $this, 'send_points_expired_email' ), 10, 2 );
        add_action( 'tz_rewards_points_manually_adjusted', array( $this, 'send_manual_adjustment_email' ), 10, 4 );
        add_action( Maintenance::CRON_HOOK, array( $this, 'send_expiry_soon_emails' ), 20 );
    }

    /**
     * Send account-created Bronze/default tier email.
     *
     * @param int                 $user_id User ID.
     * @param array<string,mixed> $tier Tier data.
     * @return void
     */
    public function send_welcome_email( $user_id, $tier ) {
        if ( ! $this->should_send( 'email_welcome_enabled' ) ) {
            return;
        }

        $replacements = $this->get_base_replacements( $user_id, array( 'tier' => isset( $tier['name'] ) ? $tier['name'] : '' ) );
        $details      = array(
            __( 'Membership tier', 'techzu-rewards' ) => isset( $tier['name'] ) ? $tier['name'] : '',
            __( 'How to earn', 'techzu-rewards' )     => sprintf( __( 'Earn %1$s %2$s for every S$1 spent on eligible purchases.', 'techzu-rewards' ), wc_format_decimal( (float) $this->settings->get( 'points_per_dollar', 1 ), 2 ), $this->settings->get( 'points_label', 'Bliss Points' ) ),
        );

        $this->send_customer_email(
            $user_id,
            $this->settings->get( 'email_subject_welcome', '' ),
            $this->settings->get( 'email_intro_welcome', '' ),
            $details,
            $replacements
        );
    }

    /**
     * Send tier update email.
     *
     * @param int                       $user_id User ID.
     * @param array<string,mixed>|null  $old_tier Old tier.
     * @param array<string,mixed>       $new_tier New tier.
     * @param string                    $context Context.
     * @return void
     */
    public function send_tier_updated_email( $user_id, $old_tier, $new_tier, $context ) {
        unset( $context );

        if ( ! $this->should_send( 'email_tier_updated_enabled' ) ) {
            return;
        }

        $old_name     = is_array( $old_tier ) && isset( $old_tier['name'] ) ? $old_tier['name'] : __( 'Previous tier', 'techzu-rewards' );
        $new_name     = isset( $new_tier['name'] ) ? $new_tier['name'] : '';
        $birthday_pct = isset( $new_tier['birthday_discount'] ) ? (float) $new_tier['birthday_discount'] : 0;
        $replacements = $this->get_base_replacements(
            $user_id,
            array(
                'old_tier' => $old_name,
                'tier'     => $new_name,
            )
        );
        $details = array(
            __( 'Previous tier', 'techzu-rewards' ) => $old_name,
            __( 'New tier', 'techzu-rewards' )      => $new_name,
            __( 'Birthday perk', 'techzu-rewards' ) => sprintf( __( '%s%% birthday discount', 'techzu-rewards' ), wc_format_decimal( $birthday_pct, 0 ) ),
        );

        $this->send_customer_email(
            $user_id,
            $this->settings->get( 'email_subject_tier_updated', '' ),
            $this->settings->get( 'email_intro_tier_updated', '' ),
            $details,
            $replacements
        );
    }

    /**
     * Send points earned email.
     *
     * @param int    $user_id User ID.
     * @param int    $points Points.
     * @param int    $order_id Order ID.
     * @param string $context Context.
     * @return void
     */
    public function send_points_earned_email( $user_id, $points, $order_id, $context ) {
        unset( $context );

        if ( ! $this->should_send( 'email_points_earned_enabled' ) || absint( $points ) <= 0 ) {
            return;
        }

        $replacements = $this->get_base_replacements(
            $user_id,
            array(
                'points'       => absint( $points ),
                'points_label' => $this->format_points_label( absint( $points ) ),
                'order_number' => $this->get_order_number( $order_id ),
            )
        );
        $details = array(
            __( 'Order', 'techzu-rewards' )           => $this->get_order_number( $order_id ),
            __( 'Points earned', 'techzu-rewards' )   => sprintf( '%1$d %2$s', absint( $points ), $this->format_points_label( absint( $points ) ) ),
            __( 'Current balance', 'techzu-rewards' ) => sprintf( '%1$d %2$s', $this->points_manager->get_balance( $user_id ), $this->settings->get( 'points_label', 'Bliss Points' ) ),
        );

        $this->send_customer_email(
            $user_id,
            $this->settings->get( 'email_subject_points_earned', '' ),
            $this->settings->get( 'email_intro_points_earned', '' ),
            $details,
            $replacements
        );
    }

    /**
     * Send points used email.
     *
     * @param int $user_id User ID.
     * @param int $points Points.
     * @param int $order_id Order ID.
     * @return void
     */
    public function send_points_used_email( $user_id, $points, $order_id ) {
        if ( ! $this->should_send( 'email_points_used_enabled' ) || absint( $points ) <= 0 ) {
            return;
        }

        $replacements = $this->get_base_replacements(
            $user_id,
            array(
                'points'       => absint( $points ),
                'points_label' => $this->format_points_label( absint( $points ) ),
                'order_number' => $this->get_order_number( $order_id ),
            )
        );
        $details = array(
            __( 'Order', 'techzu-rewards' )           => $this->get_order_number( $order_id ),
            __( 'Points used', 'techzu-rewards' )     => sprintf( '%1$d %2$s', absint( $points ), $this->format_points_label( absint( $points ) ) ),
            __( 'Current balance', 'techzu-rewards' ) => sprintf( '%1$d %2$s', $this->points_manager->get_balance( $user_id ), $this->settings->get( 'points_label', 'Bliss Points' ) ),
        );

        $this->send_customer_email(
            $user_id,
            $this->settings->get( 'email_subject_points_used', '' ),
            $this->settings->get( 'email_intro_points_used', '' ),
            $details,
            $replacements
        );
    }

    /**
     * Send point-expired email.
     *
     * @param int $user_id User ID.
     * @param int $points Points.
     * @return void
     */
    public function send_points_expired_email( $user_id, $points ) {
        if ( ! $this->should_send( 'email_points_expired_enabled' ) || absint( $points ) <= 0 ) {
            return;
        }

        $replacements = $this->get_base_replacements(
            $user_id,
            array(
                'points'       => absint( $points ),
                'points_label' => $this->format_points_label( absint( $points ) ),
            )
        );
        $details = array(
            __( 'Expired points', 'techzu-rewards' )  => sprintf( '%1$d %2$s', absint( $points ), $this->format_points_label( absint( $points ) ) ),
            __( 'Current balance', 'techzu-rewards' ) => sprintf( '%1$d %2$s', $this->points_manager->get_balance( $user_id ), $this->settings->get( 'points_label', 'Bliss Points' ) ),
        );

        $this->send_customer_email(
            $user_id,
            $this->settings->get( 'email_subject_points_expired', '' ),
            $this->settings->get( 'email_intro_points_expired', '' ),
            $details,
            $replacements
        );
    }

    /**
     * Send manual admin adjustment email.
     *
     * @param int    $user_id User ID.
     * @param int    $difference Difference applied.
     * @param int    $target New target balance.
     * @param string $note Admin note.
     * @return void
     */
    public function send_manual_adjustment_email( $user_id, $difference, $target, $note ) {
        if ( ! $this->should_send( 'email_manual_adjustment_enabled' ) ) {
            return;
        }

        $difference  = (int) $difference;
        $replacements = $this->get_base_replacements(
            $user_id,
            array(
                'points'       => abs( $difference ),
                'points_label' => $this->format_points_label( abs( $difference ) ),
                'balance'      => absint( $target ),
            )
        );
        $details = array(
            __( 'Adjustment', 'techzu-rewards' )      => sprintf( '%1$s%2$d %3$s', $difference > 0 ? '+' : '-', abs( $difference ), $this->settings->get( 'points_label', 'Bliss Points' ) ),
            __( 'New balance', 'techzu-rewards' )     => sprintf( '%1$d %2$s', absint( $target ), $this->settings->get( 'points_label', 'Bliss Points' ) ),
        );
        if ( '' !== trim( (string) $note ) ) {
            $details[ __( 'Note', 'techzu-rewards' ) ] = $note;
        }

        $this->send_customer_email(
            $user_id,
            $this->settings->get( 'email_subject_manual_adjustment', '' ),
            $this->settings->get( 'email_intro_manual_adjustment', '' ),
            $details,
            $replacements
        );
    }

    /**
     * Daily digest for points expiring soon.
     *
     * @return void
     */
    public function send_expiry_soon_emails() {
        if ( ! $this->should_send( 'email_points_expiring_enabled' ) ) {
            return;
        }

        $days  = max( 1, (int) $this->settings->get( 'email_points_expiry_days', 30 ) );
        $query = new \WP_User_Query(
            array(
                'number'     => 500,
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
            $user_id = absint( $user_id );
            $lots    = $this->points_manager->get_expiring_lots( $user_id, $days, true );
            if ( empty( $lots ) ) {
                continue;
            }

            $points  = 0;
            $lot_ids = array();
            $dates   = array();
            foreach ( $lots as $lot ) {
                $points += isset( $lot['remaining'] ) ? absint( $lot['remaining'] ) : 0;
                if ( ! empty( $lot['id'] ) ) {
                    $lot_ids[] = sanitize_text_field( $lot['id'] );
                }
                if ( ! empty( $lot['expires_at'] ) ) {
                    $dates[] = date_i18n( get_option( 'date_format' ), strtotime( $lot['expires_at'] ) );
                }
            }

            if ( $points <= 0 ) {
                continue;
            }

            $dates        = array_values( array_unique( $dates ) );
            $replacements = $this->get_base_replacements(
                $user_id,
                array(
                    'points'       => $points,
                    'points_label' => $this->format_points_label( $points ),
                    'expiry_days'  => $days,
                )
            );
            $details = array(
                __( 'Points expiring soon', 'techzu-rewards' ) => sprintf( '%1$d %2$s', $points, $this->format_points_label( $points ) ),
                __( 'Expiry date(s)', 'techzu-rewards' )       => implode( ', ', $dates ),
                __( 'Reminder window', 'techzu-rewards' )      => sprintf( __( 'Within %d days', 'techzu-rewards' ), $days ),
            );

            $sent = $this->send_customer_email(
                $user_id,
                $this->settings->get( 'email_subject_points_expiring', '' ),
                $this->settings->get( 'email_intro_points_expiring', '' ),
                $details,
                $replacements
            );

            if ( $sent ) {
                $this->points_manager->mark_expiry_notice_sent( $user_id, $lot_ids );
            }
        }
    }

    /**
     * Whether an email type should be sent.
     *
     * @param string $setting_key Specific toggle key.
     * @return bool
     */
    protected function should_send( $setting_key ) {
        return 'yes' === $this->settings->get( 'email_notifications_enabled', 'yes' ) && 'yes' === $this->settings->get( $setting_key, 'yes' );
    }

    /**
     * Send a customer email through WooCommerce mailer when available.
     *
     * @param int                 $user_id User ID.
     * @param string              $subject_template Subject template.
     * @param string              $intro_template Intro template.
     * @param array<string,mixed> $details Key/value rows.
     * @param array<string,mixed> $replacements Merge tags.
     * @return bool
     */
    protected function send_customer_email( $user_id, $subject_template, $intro_template, $details, $replacements = array() ) {
        $user = get_user_by( 'id', absint( $user_id ) );
        if ( ! $user || empty( $user->user_email ) || ! is_email( $user->user_email ) ) {
            return false;
        }

        $replacements = $this->get_base_replacements( $user_id, $replacements );
        $subject      = $this->replace_merge_tags( $subject_template, $replacements );
        $intro        = $this->replace_merge_tags( $intro_template, $replacements );
        $heading      = $this->settings->get( 'email_brand_title', 'Elegant Bliss Rewards' );
        $account_url  = $this->get_rewards_url();

        if ( '' === trim( $subject ) ) {
            $subject = $heading;
        }

        $content  = '<p>' . esc_html( $intro ) . '</p>';
        if ( ! empty( $details ) ) {
            $content .= '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;" border="1"><tbody>';
            foreach ( $details as $label => $value ) {
                $content .= '<tr><th scope="row" style="text-align:left;width:40%;">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
            }
            $content .= '</tbody></table>';
        }

        if ( $account_url ) {
            $content .= '<p><a href="' . esc_url( $account_url ) . '">' . esc_html__( 'View my rewards', 'techzu-rewards' ) . '</a></p>';
        }

        $footer = trim( (string) $this->settings->get( 'email_footer_text', '' ) );
        if ( '' !== $footer ) {
            $content .= '<p><small>' . esc_html( $this->replace_merge_tags( $footer, $replacements ) ) . '</small></p>';
        }

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        if ( function_exists( 'WC' ) && WC() && is_callable( array( WC(), 'mailer' ) ) ) {
            $mailer  = WC()->mailer();
            $message = is_callable( array( $mailer, 'wrap_message' ) ) ? $mailer->wrap_message( $heading, $content ) : $content;
            return (bool) $mailer->send( $user->user_email, $subject, $message, $headers );
        }

        return (bool) wp_mail( $user->user_email, $subject, $content, $headers );
    }

    /**
     * Base merge tags.
     *
     * @param int                 $user_id User ID.
     * @param array<string,mixed> $extra Extra replacements.
     * @return array<string,string>
     */
    protected function get_base_replacements( $user_id, $extra = array() ) {
        $user    = get_user_by( 'id', absint( $user_id ) );
        $balance = $this->points_manager->get_balance( $user_id );
        $tier    = $this->tier_manager->get_customer_tier( $user_id );
        $base    = array(
            'site_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'first_name'   => $user ? $user->first_name : '',
            'display_name' => $user ? $user->display_name : '',
            'balance'      => (string) $balance,
            'points_label' => $this->settings->get( 'points_label', 'Bliss Points' ),
            'tier'         => isset( $tier['name'] ) ? $tier['name'] : '',
        );

        foreach ( $extra as $key => $value ) {
            $base[ sanitize_key( $key ) ] = (string) $value;
        }

        return $base;
    }

    /**
     * Replace merge tags in a template.
     *
     * @param string              $template Template.
     * @param array<string,mixed> $replacements Replacements.
     * @return string
     */
    protected function replace_merge_tags( $template, $replacements ) {
        $output = (string) $template;
        foreach ( $replacements as $key => $value ) {
            $output = str_replace( '{' . sanitize_key( $key ) . '}', (string) $value, $output );
        }

        return $output;
    }

    /**
     * Format points label.
     *
     * @param int $points Points.
     * @return string
     */
    protected function format_points_label( $points ) {
        return ( 1 === (int) $points ) ? $this->settings->get( 'point_label_singular', 'Bliss Point' ) : $this->settings->get( 'points_label', 'Bliss Points' );
    }

    /**
     * Get order number for an email detail row.
     *
     * @param int $order_id Order ID.
     * @return string
     */
    protected function get_order_number( $order_id ) {
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof \WC_Order ) {
                return '#' . $order->get_order_number();
            }
        }

        return $order_id ? '#' . absint( $order_id ) : '-';
    }

    /**
     * Get My Account Rewards URL.
     *
     * @return string
     */
    protected function get_rewards_url() {
        if ( function_exists( 'wc_get_page_permalink' ) && function_exists( 'wc_get_endpoint_url' ) ) {
            return wc_get_endpoint_url( 'rewards', '', wc_get_page_permalink( 'myaccount' ) );
        }

        return function_exists( 'home_url' ) ? home_url( '/my-account/rewards/' ) : '';
    }
}
