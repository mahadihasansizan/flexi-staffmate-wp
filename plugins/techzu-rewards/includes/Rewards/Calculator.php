<?php
namespace Techzu\Rewards\Rewards;

use Techzu\Rewards\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Calculator {
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
     * Calculate points for an order amount.
     *
     * @param float $amount Paid eligible product amount.
     * @return int
     */
    public function calculate_points_for_amount( $amount ) {
        $amount = (float) $amount;

        if ( $amount < (float) $this->settings->get( 'minimum_spend', 0 ) ) {
            return 0;
        }

        $base_points = $this->get_base_points( $amount );
        $bonus_rule  = $this->get_matching_bonus_rule( $amount );

        if ( empty( $bonus_rule ) ) {
            return (int) apply_filters( 'tz_rewards_calculated_points', $base_points, $amount, null, $this->settings->all() );
        }

        if ( 'bonus_points' === $this->settings->get( 'bonus_value_type', 'total_points' ) ) {
            $points = $base_points + (int) $bonus_rule['value'];
        } else {
            $points = (int) $bonus_rule['value'];
        }

        return (int) apply_filters( 'tz_rewards_calculated_points', $points, $amount, $bonus_rule, $this->settings->all() );
    }

    /**
     * Get the base points before bonuses.
     *
     * @param float $amount Amount.
     * @return int
     */
    public function get_base_points( $amount ) {
        $raw_points = (float) $amount * (float) $this->settings->get( 'points_per_dollar', 1 );
        $mode       = $this->settings->get( 'rounding_mode', 'floor' );

        switch ( $mode ) {
            case 'ceil':
                return (int) ceil( $raw_points );
            case 'round':
                return (int) round( $raw_points );
            case 'floor':
            default:
                return (int) floor( $raw_points );
        }
    }

    /**
     * Get the matching bonus rule for an amount.
     *
     * @param float $amount Amount.
     * @return array<string,float>|null
     */
    public function get_matching_bonus_rule( $amount ) {
        $tiers      = $this->get_bonus_tiers();
        $match_mode = $this->settings->get( 'bonus_match_mode', 'highest_matched' );
        $matched    = null;

        foreach ( $tiers as $tier ) {
            if ( 'exact_match' === $match_mode ) {
                if ( abs( (float) $tier['spend'] - (float) $amount ) < 0.01 ) {
                    $matched = $tier;
                    break;
                }
            } elseif ( $amount >= (float) $tier['spend'] ) {
                $matched = $tier;
            }
        }

        return $matched;
    }

    /**
     * Get normalized bonus tiers.
     *
     * @return array<int,array<string,float>>
     */
    public function get_bonus_tiers() {
        return Settings::normalize_bonus_tiers( $this->settings->get( 'bonus_tiers', array() ) );
    }

    /**
     * Get normalized redemption tiers.
     *
     * @param bool $enabled_only Return enabled tiers only.
     * @return array<int,array<string,mixed>>
     */
    public function get_redemption_tiers( $enabled_only = true ) {
        $tiers = Settings::normalize_redemption_tiers( $this->settings->get( 'redemption_tiers', array() ) );

        if ( ! $enabled_only ) {
            return $tiers;
        }

        $enabled = array();
        foreach ( $tiers as $tier ) {
            if ( 'yes' === $tier['enabled'] ) {
                $enabled[] = $tier;
            }
        }

        return $enabled;
    }

    /**
     * Get redemptions available to a customer.
     *
     * @param int        $points_balance Current balance.
     * @param float|null $cart_subtotal Cart subtotal.
     * @return array<int,array<string,float|int|string>>
     */
    public function get_available_redemptions( $points_balance, $cart_subtotal = null ) {
        $tiers     = array();
        $subtotal  = is_null( $cart_subtotal ) ? null : (float) $cart_subtotal;
        $all_tiers = $this->get_redemption_tiers( true );

        foreach ( $all_tiers as $tier ) {
            $required_points = (int) $tier['points'];
            $voucher         = (float) $tier['voucher'];

            if ( $points_balance < $required_points ) {
                continue;
            }

            if ( null !== $subtotal && $voucher > $subtotal ) {
                continue;
            }

            $tiers[ $required_points ] = array(
                'points'  => $required_points,
                'voucher' => $voucher,
                'label'   => isset( $tier['label'] ) ? (string) $tier['label'] : '',
            );
        }

        return (array) apply_filters( 'tz_rewards_available_redemptions', $tiers, $points_balance, $cart_subtotal, $this->settings->all() );
    }

    /**
     * Get a product price for points estimation.
     *
     * @param \WC_Product $product Product object.
     * @return float
     */
    public function get_product_reference_price( $product ) {
        if ( $product->is_type( 'variable' ) ) {
            return (float) $product->get_variation_price( 'min', true );
        }

        return (float) $product->get_price();
    }

    /**
     * Get paid/discounted product total for the current cart.
     *
     * @param \WC_Cart $cart Cart object.
     * @param bool     $exclude_sale_items Whether to exclude sale items.
     * @return float
     */
    public function get_cart_eligible_product_total( $cart, $exclude_sale_items = false ) {
        if ( ! $cart || ! is_callable( array( $cart, 'get_cart' ) ) ) {
            return 0.0;
        }

        $total = 0.0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
            if ( $exclude_sale_items && $product instanceof \WC_Product && $product->is_on_sale() ) {
                continue;
            }

            if ( isset( $cart_item['line_total'] ) ) {
                $total += (float) $cart_item['line_total'];
            }
        }

        return max( 0, (float) apply_filters( 'tz_rewards_cart_eligible_product_total', $total, $cart, $exclude_sale_items ) );
    }
}
