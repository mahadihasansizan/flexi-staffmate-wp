<?php
/**
 * WooCommerce product membership pricing panel.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds product-level pricing rules per membership level.
 */
final class ProductPricingPanel {
    public const PRODUCT_RULES_META = '_membership_product_rules';

    /** @var ProductPricingPanel|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings service.
     */
    private function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Get singleton.
     *
     * @param Settings|null $settings Settings service.
     * @return ProductPricingPanel
     */
    public static function instance( ?Settings $settings = null ): ProductPricingPanel {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings ?: Settings::instance() );
        }
        return self::$instance;
    }

    /**
     * Register WooCommerce hooks.
     *
     * @return void
     */
    public function hooks(): void {
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_rules' ) );
    }

    /**
     * Add product data tab.
     *
     * @param array<string,array<string,mixed>> $tabs Product tabs.
     * @return array<string,array<string,mixed>>
     */
    public function product_data_tab( array $tabs ): array {
        $tabs['membership_pricing'] = array(
            'label'    => __( 'Membership Pricing', 'membership' ),
            'target'   => 'membership_product_pricing_panel',
            'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_external' ),
            'priority' => 70,
        );

        return $tabs;
    }

    /**
     * Render product data panel.
     *
     * @return void
     */
    public function product_data_panel(): void {
        global $post;

        $product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
        $levels     = $this->settings->get_levels();
        $types      = $this->settings->pricing_types();
        $rules      = self::get_product_rules( $product_id );
        ?>
        <div id="membership_product_pricing_panel" class="panel woocommerce_options_panel membership-product-panel hidden">
            <?php wp_nonce_field( 'membership_save_product_rules', 'membership_product_rules_nonce' ); ?>
            <div class="membership-product-panel-inner">
                <h3><?php esc_html_e( 'Membership product pricing', 'membership' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Set optional product-specific pricing per membership level. Product rules override user-specific and global level pricing.', 'membership' ); ?>
                </p>
                <?php if ( empty( $levels ) ) : ?>
                    <div class="membership-product-empty">
                        <?php esc_html_e( 'No membership levels exist yet. Create levels under Membership > Levels & Roles.', 'membership' ); ?>
                    </div>
                <?php else : ?>
                    <div class="membership-product-rules">
                        <table class="widefat striped membership-product-rules-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Use', 'membership' ); ?></th>
                                    <th><?php esc_html_e( 'Membership level', 'membership' ); ?></th>
                                    <th><?php esc_html_e( 'Pricing type', 'membership' ); ?></th>
                                    <th><?php esc_html_e( 'Amount', 'membership' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $levels as $key => $level ) : ?>
                                    <?php
                                    if ( empty( $level['enabled'] ) ) {
                                        continue;
                                    }
                                    $rule = isset( $rules[ $key ] ) && is_array( $rules[ $key ] ) ? $rules[ $key ] : array();
                                    $enabled = ! empty( $rule['enabled'] );
                                    $selected_type = isset( $rule['pricing_type'] ) ? sanitize_key( (string) $rule['pricing_type'] ) : (string) ( $level['pricing_type'] ?? 'none' );
                                    if ( ! isset( $types[ $selected_type ] ) ) {
                                        $selected_type = 'none';
                                    }
                                    $amount = isset( $rule['amount'] ) ? (string) $rule['amount'] : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <label class="membership-switch">
                                                <input type="checkbox" name="membership_product_rules[<?php echo esc_attr( (string) $key ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
                                                <span></span>
                                            </label>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html( (string) $level['name'] ); ?></strong><br />
                                            <code><?php echo esc_html( Roles::ROLE_PREFIX . (string) $key ); ?></code>
                                        </td>
                                        <td>
                                            <select name="membership_product_rules[<?php echo esc_attr( (string) $key ); ?>][pricing_type]">
                                                <?php foreach ( $types as $type => $label ) : ?>
                                                    <option value="<?php echo esc_attr( (string) $type ); ?>" <?php selected( $selected_type, (string) $type ); ?>><?php echo esc_html( (string) $label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="0.0001" min="0" name="membership_product_rules[<?php echo esc_attr( (string) $key ); ?>][amount]" value="<?php echo esc_attr( $amount ); ?>" placeholder="0" />
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="description membership-product-note">
                        <?php esc_html_e( 'Percentage off uses 0-100. Fixed amount off subtracts from the regular price. Fixed final price replaces the product price for that level.', 'membership' ); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Save product rules.
     *
     * @param int $post_id Product ID.
     * @return void
     */
    public function save_product_rules( int $post_id ): void {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( empty( $_POST['membership_product_rules_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['membership_product_rules_nonce'] ) ), 'membership_save_product_rules' ) ) {
            return;
        }

        $raw_rules = isset( $_POST['membership_product_rules'] ) && is_array( $_POST['membership_product_rules'] ) ? wp_unslash( $_POST['membership_product_rules'] ) : array();
        $levels    = $this->settings->get_levels();
        $types     = $this->settings->pricing_types();
        $clean     = array();

        foreach ( $levels as $key => $level ) {
            if ( empty( $level['enabled'] ) || ! isset( $raw_rules[ $key ] ) || ! is_array( $raw_rules[ $key ] ) ) {
                continue;
            }

            $rule = $raw_rules[ $key ];
            if ( empty( $rule['enabled'] ) ) {
                continue;
            }

            $type = isset( $rule['pricing_type'] ) ? sanitize_key( (string) $rule['pricing_type'] ) : 'none';
            if ( ! isset( $types[ $type ] ) ) {
                $type = 'none';
            }

            $clean[ $key ] = array(
                'enabled'      => 1,
                'pricing_type' => $type,
                'amount'       => isset( $rule['amount'] ) ? $this->settings->sanitize_decimal( $rule['amount'] ) : 0,
            );
        }

        if ( empty( $clean ) ) {
            delete_post_meta( $post_id, self::PRODUCT_RULES_META );
            return;
        }

        update_post_meta( $post_id, self::PRODUCT_RULES_META, $clean );
    }

    /**
     * Get saved product rules.
     *
     * @param int $product_id Product ID.
     * @return array<string,array<string,mixed>>
     */
    public static function get_product_rules( int $product_id ): array {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return array();
        }

        $rules = get_post_meta( $product_id, self::PRODUCT_RULES_META, true );
        return is_array( $rules ) ? $rules : array();
    }
}
