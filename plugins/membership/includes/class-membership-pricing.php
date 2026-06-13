<?php
/**
 * WooCommerce membership pricing engine.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Applies membership pricing on catalog, product, variation, and cart prices.
 */
final class Pricing {
    /** @var Pricing|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /** @var Roles */
    private $roles;

    /** @var bool */
    private $calculating_cart = false;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings service.
     * @param Roles    $roles Roles service.
     */
    private function __construct( Settings $settings, Roles $roles ) {
        $this->settings = $settings;
        $this->roles    = $roles;
    }

    /**
     * Get singleton.
     *
     * @param Settings|null $settings Settings service.
     * @param Roles|null    $roles Roles service.
     * @return Pricing
     */
    public static function instance( ?Settings $settings = null, ?Roles $roles = null ): Pricing {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings ?: Settings::instance(), $roles ?: Roles::instance() );
        }
        return self::$instance;
    }

    /**
     * Register WooCommerce hooks.
     *
     * @return void
     */
    public function hooks(): void {
        add_filter( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 20, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_product_price' ), 20, 2 );
        add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_price' ), 20, 3 );
        add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_prices_hash' ), 20, 3 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'cart_prices' ), 20, 1 );
        add_action( 'woocommerce_before_single_product', array( $this, 'single_product_notice' ) );
    }

    /**
     * Filter simple and variation product price.
     *
     * @param string|float $price Product price.
     * @param \WC_Product  $product Product object.
     * @return string|float
     */
    public function filter_product_price( $price, $product ) {
        if ( $this->should_skip_price_filter() || ! $product instanceof \WC_Product ) {
            return $price;
        }

        $adjusted = $this->get_adjusted_price( $product, get_current_user_id(), $price );
        if ( false === $adjusted ) {
            return $price;
        }

        return $this->normalize_price_for_woocommerce( $adjusted );
    }

    /**
     * Filter prices in variation price arrays.
     *
     * @param string|float $price Price.
     * @param \WC_Product_Variation $variation Variation.
     * @param \WC_Product $product Parent product.
     * @return string|float
     */
    public function filter_variation_price( $price, $variation, $product ) {
        unset( $product );

        if ( $this->should_skip_price_filter() || ! $variation instanceof \WC_Product ) {
            return $price;
        }

        $adjusted = $this->get_adjusted_price( $variation, get_current_user_id(), $price );
        if ( false === $adjusted ) {
            return $price;
        }

        return $this->normalize_price_for_woocommerce( $adjusted );
    }

    /**
     * Add user and membership data to variation price cache hash.
     *
     * @param array<string,mixed> $hash Hash data.
     * @param \WC_Product         $product Product.
     * @param bool                $for_display For display.
     * @return array<string,mixed>
     */
    public function variation_prices_hash( array $hash, $product, bool $for_display ): array {
        unset( $product, $for_display );

        $user_id = get_current_user_id();
        $level   = $user_id ? $this->roles->get_user_level_key( $user_id ) : '';

        $hash['membership_version']  = MEMBERSHIP_VERSION;
        $hash['membership_user']     = $user_id;
        $hash['membership_level']    = $level;
        $hash['membership_override'] = $user_id ? array(
            (int) get_user_meta( $user_id, Users::OVERRIDE_ENABLED_META, true ),
            (string) get_user_meta( $user_id, Users::OVERRIDE_TYPE_META, true ),
            (string) get_user_meta( $user_id, Users::OVERRIDE_AMOUNT_META, true ),
        ) : array();

        return $hash;
    }

    /**
     * Apply membership prices to cart line items from stored base prices.
     *
     * @param \WC_Cart $cart Cart.
     * @return void
     */
    public function cart_prices( $cart ): void {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        if ( ! is_user_logged_in() || ! $cart instanceof \WC_Cart ) {
            return;
        }

        if ( did_action( 'woocommerce_before_calculate_totals' ) > 10 ) {
            return;
        }

        $this->calculating_cart = true;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
                continue;
            }

            $product    = $cart_item['data'];
            $base_price = $this->stored_base_price( $product );
            if ( '' === $base_price ) {
                continue;
            }

            $adjusted = $this->get_adjusted_price( $product, get_current_user_id(), $base_price );
            if ( false === $adjusted ) {
                continue;
            }

            $product->set_price( $adjusted );
        }

        $this->calculating_cart = false;
    }

    /**
     * Show optional notice on product pages.
     *
     * @return void
     */
    public function single_product_notice(): void {
        $settings = $this->settings->get_settings();
        if ( empty( $settings['show_member_price_notice'] ) || ! is_user_logged_in() || ! function_exists( 'wc_print_notice' ) ) {
            return;
        }

        global $product;
        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $rule = $this->resolve_rule( $product, get_current_user_id() );
        if ( false === $rule || 'none' === (string) $rule['pricing_type'] ) {
            return;
        }

        wc_print_notice( __( 'Your membership price is active for this product.', 'membership' ), 'notice' );
    }

    /**
     * Should price filter be skipped.
     *
     * @return bool
     */
    private function should_skip_price_filter(): bool {
        if ( $this->calculating_cart ) {
            return true;
        }

        if ( ! is_user_logged_in() ) {
            return true;
        }

        if ( is_admin() && ! wp_doing_ajax() ) {
            return true;
        }

        return false;
    }

    /**
     * Calculate adjusted price for product/user.
     *
     * @param \WC_Product $product Product.
     * @param int         $user_id User ID.
     * @param string|float $base_price Base price.
     * @return float|false Adjusted price or false when no membership rule applies.
     */
    public function get_adjusted_price( \WC_Product $product, int $user_id, $base_price ) {
        $base_price = $this->price_to_float( $base_price );
        if ( false === $base_price ) {
            return false;
        }

        $rule = $this->resolve_rule( $product, $user_id );
        if ( false === $rule ) {
            return false;
        }

        return $this->apply_rule( $base_price, $rule );
    }

    /**
     * Resolve rule using priority: product, user override, global level.
     *
     * @param \WC_Product $product Product.
     * @param int         $user_id User ID.
     * @return array<string,mixed>|false
     */
    private function resolve_rule( \WC_Product $product, int $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $level_key = $this->roles->get_user_level_key( $user_id );
        if ( '' === $level_key ) {
            return false;
        }

        $product_rule = $this->product_rule( $product, $level_key );
        if ( false !== $product_rule ) {
            return $product_rule;
        }

        $user_rule = $this->user_override_rule( $user_id );
        if ( false !== $user_rule ) {
            return $user_rule;
        }

        $level = $this->settings->get_level( $level_key );
        if ( ! is_array( $level ) || empty( $level['enabled'] ) ) {
            return false;
        }

        return array(
            'pricing_type' => isset( $level['pricing_type'] ) ? sanitize_key( (string) $level['pricing_type'] ) : 'none',
            'amount'       => isset( $level['amount'] ) ? $this->settings->sanitize_decimal( $level['amount'] ) : 0,
            'source'       => 'global',
        );
    }

    /**
     * Get product-specific rule for membership level.
     *
     * @param \WC_Product $product Product.
     * @param string      $level_key Level key.
     * @return array<string,mixed>|false
     */
    private function product_rule( \WC_Product $product, string $level_key ) {
        $ids = array();
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) {
            $ids[] = absint( $parent_id );
        }
        $ids[] = absint( $product->get_id() );

        foreach ( array_unique( $ids ) as $product_id ) {
            $rules = ProductPricingPanel::get_product_rules( $product_id );
            if ( ! isset( $rules[ $level_key ] ) || ! is_array( $rules[ $level_key ] ) || empty( $rules[ $level_key ]['enabled'] ) ) {
                continue;
            }

            $type = isset( $rules[ $level_key ]['pricing_type'] ) ? sanitize_key( (string) $rules[ $level_key ]['pricing_type'] ) : 'none';
            if ( ! isset( $this->settings->pricing_types()[ $type ] ) ) {
                $type = 'none';
            }

            return array(
                'pricing_type' => $type,
                'amount'       => isset( $rules[ $level_key ]['amount'] ) ? $this->settings->sanitize_decimal( $rules[ $level_key ]['amount'] ) : 0,
                'source'       => 'product',
            );
        }

        return false;
    }

    /**
     * Get user-specific rule if enabled.
     *
     * @param int $user_id User ID.
     * @return array<string,mixed>|false
     */
    private function user_override_rule( int $user_id ) {
        if ( ! (int) get_user_meta( $user_id, Users::OVERRIDE_ENABLED_META, true ) ) {
            return false;
        }

        $type = sanitize_key( (string) get_user_meta( $user_id, Users::OVERRIDE_TYPE_META, true ) );
        if ( ! isset( $this->settings->pricing_types()[ $type ] ) ) {
            $type = 'none';
        }

        return array(
            'pricing_type' => $type,
            'amount'       => $this->settings->sanitize_decimal( get_user_meta( $user_id, Users::OVERRIDE_AMOUNT_META, true ) ),
            'source'       => 'user',
        );
    }

    /**
     * Apply a pricing rule.
     *
     * @param float               $price Base price.
     * @param array<string,mixed> $rule Rule.
     * @return float
     */
    private function apply_rule( float $price, array $rule ): float {
        $type   = isset( $rule['pricing_type'] ) ? sanitize_key( (string) $rule['pricing_type'] ) : 'none';
        $amount = isset( $rule['amount'] ) ? $this->settings->sanitize_decimal( $rule['amount'] ) : 0;

        switch ( $type ) {
            case 'percent':
                $amount = min( 100, max( 0, $amount ) );
                $price  = $price - ( $price * ( $amount / 100 ) );
                break;
            case 'fixed_discount':
                $price = $price - $amount;
                break;
            case 'fixed_price':
                $price = $amount;
                break;
            case 'none':
            default:
                break;
        }

        return max( 0, round( $price, wc_get_price_decimals() ) );
    }

    /**
     * Convert price to float.
     *
     * @param string|float|int $price Price.
     * @return float|false
     */
    private function price_to_float( $price ) {
        if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
            return false;
        }

        return (float) $price;
    }

    /**
     * Normalize price return value for WooCommerce filters.
     *
     * @param float $price Price.
     * @return string
     */
    private function normalize_price_for_woocommerce( float $price ): string {
        return wc_format_decimal( $price, wc_get_price_decimals() );
    }

    /**
     * Get stored raw base price for cart recalculation.
     *
     * @param \WC_Product $product Product.
     * @return string
     */
    private function stored_base_price( \WC_Product $product ): string {
        $product_id = absint( $product->get_id() );
        if ( ! $product_id ) {
            return '';
        }

        $price = get_post_meta( $product_id, '_price', true );
        if ( '' !== $price && is_numeric( $price ) ) {
            return (string) $price;
        }

        $edit_price = $product->get_price( 'edit' );
        return is_numeric( $edit_price ) ? (string) $edit_price : '';
    }
}
