<?php
/**
 * Plugin Name: WooCommerce TreeDots ERP Connector
 * Description: Sends WooCommerce orders to the TreeDots ERP external API using Bearer token authorization. Includes product mapping, thank-you auto send, manual resend, bulk resend, logs, and order debug data.
 * Version: 1.1.0
 * Author: OpenAI
 * License: GPL-2.0-or-later
 * Text Domain: wc-treedots-erp-full
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

if ( ! class_exists( 'WC_TreeDots_ERP_Full' ) ) {
    final class WC_TreeDots_ERP_Full {
        private const OPTION_KEY = 'wc_treedots_erp_full_settings';
        private const LOG_OPTION = 'wc_treedots_erp_full_logs';

        private const META_SENT = '_wc_treedots_erp_sent';
        private const META_SENT_AT = '_wc_treedots_erp_sent_at';
        private const META_ERP_ORDER_ID = '_wc_treedots_erp_order_id';
        private const META_LAST_REQUEST = '_wc_treedots_erp_last_request';
        private const META_LAST_RESPONSE = '_wc_treedots_erp_last_response';
        private const META_LAST_HTTP_CODE = '_wc_treedots_erp_last_http_code';
        private const META_LAST_ERROR = '_wc_treedots_erp_last_error';
        private const META_LAST_DEBUG = '_wc_treedots_erp_last_debug';

        private const PRODUCT_SKU_ID = '_treedots_sku_id';
        private const PRODUCT_ACTUAL_SKU_ID = '_treedots_actual_sku_id';
        private const PRODUCT_BUNDLE_JSON = '_treedots_bundle_options_json';

        /** @var self|null */
        private static $instance = null;

        public static function instance(): self {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
            add_action( 'admin_init', [ $this, 'register_settings' ] );
            add_action( 'admin_post_wc_treedots_test_connection', [ $this, 'handle_test_connection' ] );
            add_action( 'admin_post_wc_treedots_clear_logs', [ $this, 'handle_clear_logs' ] );
            add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );

            add_action( 'woocommerce_thankyou', [ $this, 'send_order_on_thankyou' ], 20, 1 );
            add_filter( 'woocommerce_order_actions', [ $this, 'add_order_action' ] );
            add_action( 'woocommerce_order_action_wc_treedots_send_to_erp', [ $this, 'handle_order_action' ] );
            add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_bulk_action' ] );
            add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'handle_bulk_action' ], 10, 3 );

            add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_simple_product_fields' ] );
            add_action( 'woocommerce_process_product_meta', [ $this, 'save_simple_product_fields' ] );
            add_action( 'woocommerce_variation_options_inventory', [ $this, 'add_variation_fields' ], 10, 3 );
            add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_fields' ], 10, 2 );

            add_action( 'add_meta_boxes', [ $this, 'add_order_meta_box' ] );
        }

        public function add_settings_page(): void {
            add_submenu_page(
                'woocommerce',
                __( 'TreeDots ERP Connector', 'wc-treedots-erp-full' ),
                __( 'TreeDots ERP', 'wc-treedots-erp-full' ),
                'manage_woocommerce',
                'wc-treedots-erp-full',
                [ $this, 'render_settings_page' ]
            );
        }

        public function register_settings(): void {
            register_setting(
                'wc_treedots_erp_full_group',
                self::OPTION_KEY,
                [
                    'type'              => 'array',
                    'sanitize_callback' => [ $this, 'sanitize_settings' ],
                    'default'           => $this->get_default_settings(),
                ]
            );
        }

        public function sanitize_settings( $input ): array {
            $input = is_array( $input ) ? $input : [];
            $defaults = $this->get_default_settings();

            return [
                'enabled'                    => ! empty( $input['enabled'] ) ? 'yes' : 'no',
                'base_url'                   => isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : $defaults['base_url'],
                'orders_path'                => $this->sanitize_path( $input['orders_path'] ?? $defaults['orders_path'] ),
                'me_path'                    => $this->sanitize_path( $input['me_path'] ?? $defaults['me_path'] ),
                'bearer_token'               => sanitize_text_field( (string) ( $input['bearer_token'] ?? '' ) ),
                'request_timeout'            => max( 5, absint( $input['request_timeout'] ?? $defaults['request_timeout'] ) ),
                'delivery_date_meta_key'     => sanitize_text_field( (string) ( $input['delivery_date_meta_key'] ?? '' ) ),
                'delivery_time_meta_key'     => sanitize_text_field( (string) ( $input['delivery_time_meta_key'] ?? '' ) ),
                'payment_method_override'    => sanitize_text_field( (string) ( $input['payment_method_override'] ?? '' ) ),
                'default_contact_email'      => sanitize_email( (string) ( $input['default_contact_email'] ?? '' ) ),
                'company_name_fallback'      => sanitize_text_field( (string) ( $input['company_name_fallback'] ?? '' ) ),
                'bypass_delivery_slot_check' => ! empty( $input['bypass_delivery_slot_check'] ) ? 'yes' : 'no',
                'bypass_inventory_check'     => ! empty( $input['bypass_inventory_check'] ) ? 'yes' : 'no',
                'disallow_merge_order'       => ! empty( $input['disallow_merge_order'] ) ? 'yes' : 'no',
                'success_url'                => isset( $input['success_url'] ) ? esc_url_raw( trim( (string) $input['success_url'] ) ) : '',
                'fail_url'                   => isset( $input['fail_url'] ) ? esc_url_raw( trim( (string) $input['fail_url'] ) ) : '',
                'log_enabled'                => ! empty( $input['log_enabled'] ) ? 'yes' : 'no',
                'mark_processing_on_success' => ! empty( $input['mark_processing_on_success'] ) ? 'yes' : 'no',
            ];
        }

        public function render_settings_page(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }

            $settings = $this->get_settings();
            $test_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_treedots_test_connection' ), 'wc_treedots_test_connection' );
            $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_treedots_clear_logs' ), 'wc_treedots_clear_logs' );
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__( 'TreeDots ERP Connector', 'wc-treedots-erp-full' ); ?></h1>
                <p><?php echo esc_html__( 'Bearer-token WooCommerce to TreeDots order connector.', 'wc-treedots-erp-full' ); ?></p>

                <form method="post" action="options.php">
                    <?php settings_fields( 'wc_treedots_erp_full_group' ); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable integration', 'wc-treedots-erp-full' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( 'yes', $settings['enabled'] ); ?> /> <?php esc_html_e( 'Send order on thank-you page', 'wc-treedots-erp-full' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'API Base URL', 'wc-treedots-erp-full' ); ?></th>
                            <td>
                                <input type="url" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[base_url]" value="<?php echo esc_attr( $settings['base_url'] ); ?>" />
                                <p class="description">https://staging-external-api.thetreedots.com</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Orders path', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[orders_path]" value="<?php echo esc_attr( $settings['orders_path'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Test path', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[me_path]" value="<?php echo esc_attr( $settings['me_path'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Bearer token', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bearer_token]" value="<?php echo esc_attr( $settings['bearer_token'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Request timeout', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="number" min="5" class="small-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[request_timeout]" value="<?php echo esc_attr( (string) $settings['request_timeout'] ); ?>" /> <?php esc_html_e( 'seconds', 'wc-treedots-erp-full' ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Delivery date meta key', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delivery_date_meta_key]" value="<?php echo esc_attr( $settings['delivery_date_meta_key'] ); ?>" /><p class="description"><?php esc_html_e( 'Optional. Example: _delivery_date', 'wc-treedots-erp-full' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Delivery time meta key', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delivery_time_meta_key]" value="<?php echo esc_attr( $settings['delivery_time_meta_key'] ); ?>" /><p class="description"><?php esc_html_e( 'Optional. Example: _delivery_time', 'wc-treedots-erp-full' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Payment method override', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[payment_method_override]" value="<?php echo esc_attr( $settings['payment_method_override'] ); ?>" /><p class="description"><?php esc_html_e( 'Leave blank to send the WooCommerce payment method title.', 'wc-treedots-erp-full' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Default contact email', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="email" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_contact_email]" value="<?php echo esc_attr( $settings['default_contact_email'] ); ?>" /><p class="description"><?php esc_html_e( 'Used if the order email is blank or invalid. Example: realname@gmail.com', 'wc-treedots-erp-full' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Company name fallback', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[company_name_fallback]" value="<?php echo esc_attr( $settings['company_name_fallback'] ); ?>" /><p class="description"><?php esc_html_e( 'Used when the order has no company name.', 'wc-treedots-erp-full' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Optional request flags', 'wc-treedots-erp-full' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bypass_delivery_slot_check]" value="1" <?php checked( 'yes', $settings['bypass_delivery_slot_check'] ); ?> /> <?php esc_html_e( 'Bypass delivery slot check', 'wc-treedots-erp-full' ); ?></label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bypass_inventory_check]" value="1" <?php checked( 'yes', $settings['bypass_inventory_check'] ); ?> /> <?php esc_html_e( 'Bypass inventory check', 'wc-treedots-erp-full' ); ?></label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disallow_merge_order]" value="1" <?php checked( 'yes', $settings['disallow_merge_order'] ); ?> /> <?php esc_html_e( 'Disallow merge order', 'wc-treedots-erp-full' ); ?></label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[log_enabled]" value="1" <?php checked( 'yes', $settings['log_enabled'] ); ?> /> <?php esc_html_e( 'Keep plugin logs', 'wc-treedots-erp-full' ); ?></label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mark_processing_on_success]" value="1" <?php checked( 'yes', $settings['mark_processing_on_success'] ); ?> /> <?php esc_html_e( 'Add “sent successfully” order note', 'wc-treedots-erp-full' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Stripe success URL', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="url" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[success_url]" value="<?php echo esc_attr( $settings['success_url'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Stripe fail URL', 'wc-treedots-erp-full' ); ?></th>
                            <td><input type="url" class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fail_url]" value="<?php echo esc_attr( $settings['fail_url'] ); ?>" /></td>
                        </tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Save settings', 'wc-treedots-erp-full' ) ); ?>
                </form>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Test connection', 'wc-treedots-erp-full' ); ?></a>
                    <a class="button" href="<?php echo esc_url( $clear_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear plugin logs?', 'wc-treedots-erp-full' ) ); ?>');"><?php esc_html_e( 'Clear logs', 'wc-treedots-erp-full' ); ?></a>
                </p>

                <h2><?php esc_html_e( 'Product mapping', 'wc-treedots-erp-full' ); ?></h2>
                <p><?php esc_html_e( 'Each WooCommerce product or variation must have a TreeDots SKU ID. TreeDots Actual SKU ID is required for simple items and optional for bundle parent items. Optional bundle JSON can be set for bundle items.', 'wc-treedots-erp-full' ); ?></p>
                <p><code>[{"bundleOptionId":123,"skuId":456,"actualSkuId":789,"quantity":1}]</code></p>

                <h2><?php esc_html_e( 'Recent logs', 'wc-treedots-erp-full' ); ?></h2>
                <?php $this->render_logs_table(); ?>
            </div>
            <?php
        }

        public function render_admin_notice(): void {
            if ( empty( $_GET['wc_treedots_notice'] ) || empty( $_GET['wc_treedots_message'] ) ) {
                return;
            }

            $type = sanitize_key( wp_unslash( $_GET['wc_treedots_notice'] ) );
            if ( ! in_array( $type, [ 'success', 'error', 'warning' ], true ) ) {
                $type = 'success';
            }

            $message = sanitize_text_field( wp_unslash( $_GET['wc_treedots_message'] ) );
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        public function handle_test_connection(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.', 'wc-treedots-erp-full' ) );
            }

            check_admin_referer( 'wc_treedots_test_connection' );
            $response = $this->request( 'GET', $this->get_settings()['me_path'] );

            if ( is_wp_error( $response ) ) {
                $this->redirect_notice( 'error', $response->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $this->redirect_notice( 'success', sprintf( 'Connection test complete. HTTP %1$d. %2$s', (int) $code, $body ? wp_strip_all_tags( $body ) : 'OK' ) );
        }

        public function handle_clear_logs(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.', 'wc-treedots-erp-full' ) );
            }

            check_admin_referer( 'wc_treedots_clear_logs' );
            delete_option( self::LOG_OPTION );
            $this->redirect_notice( 'success', 'Logs cleared.' );
        }

        public function send_order_on_thankyou( $order_id ): void {
            $order_id = absint( $order_id );
            if ( $order_id <= 0 ) {
                return;
            }

            if ( 'yes' !== $this->get_settings()['enabled'] ) {
                return;
            }

            $this->send_order( $order_id, false );
        }

        public function add_order_action( array $actions ): array {
            $actions['wc_treedots_send_to_erp'] = __( 'Send to TreeDots ERP', 'wc-treedots-erp-full' );
            return $actions;
        }

        public function handle_order_action( $order ): void {
            if ( ! is_a( $order, 'WC_Order' ) ) {
                return;
            }

            $this->send_order( $order->get_id(), true );
        }

        public function add_bulk_action( array $actions ): array {
            $actions['wc_treedots_bulk_send'] = __( 'Send to TreeDots ERP', 'wc-treedots-erp-full' );
            return $actions;
        }

        public function handle_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
            if ( 'wc_treedots_bulk_send' !== $action ) {
                return $redirect_to;
            }

            $sent = 0;
            $failed = 0;
            foreach ( $post_ids as $post_id ) {
                $result = $this->send_order( (int) $post_id, true );
                if ( true === $result ) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            return add_query_arg(
                [
                    'wc_treedots_notice'  => $failed > 0 ? 'warning' : 'success',
                    'wc_treedots_message' => sprintf( 'TreeDots bulk send complete: %1$d sent, %2$d failed.', $sent, $failed ),
                ],
                $redirect_to
            );
        }

        public function add_simple_product_fields(): void {
            echo '<div class="options_group">';
            woocommerce_wp_text_input(
                [
                    'id'          => self::PRODUCT_SKU_ID,
                    'label'       => __( 'TreeDots SKU ID', 'wc-treedots-erp-full' ),
                    'description' => __( 'Required TreeDots skuId.', 'wc-treedots-erp-full' ),
                    'desc_tip'    => true,
                ]
            );
            woocommerce_wp_text_input(
                [
                    'id'          => self::PRODUCT_ACTUAL_SKU_ID,
                    'label'       => __( 'TreeDots Actual SKU ID', 'wc-treedots-erp-full' ),
                    'description' => __( 'Required TreeDots actualSkuId.', 'wc-treedots-erp-full' ),
                    'desc_tip'    => true,
                ]
            );
            woocommerce_wp_textarea_input(
                [
                    'id'          => self::PRODUCT_BUNDLE_JSON,
                    'label'       => __( 'TreeDots bundle options JSON', 'wc-treedots-erp-full' ),
                    'description' => __( 'Optional bundleOptions JSON.', 'wc-treedots-erp-full' ),
                    'desc_tip'    => true,
                ]
            );
            echo '</div>';
        }

        public function save_simple_product_fields( int $product_id ): void {
            $this->save_mapping_meta( $product_id );
        }

        public function add_variation_fields( int $loop, array $variation_data, WP_Post $variation ): void {
            woocommerce_wp_text_input(
                [
                    'id'            => self::PRODUCT_SKU_ID . '[' . $loop . ']',
                    'label'         => __( 'TreeDots SKU ID', 'wc-treedots-erp-full' ),
                    'wrapper_class' => 'form-row form-row-first',
                    'value'         => get_post_meta( $variation->ID, self::PRODUCT_SKU_ID, true ),
                ]
            );
            woocommerce_wp_text_input(
                [
                    'id'            => self::PRODUCT_ACTUAL_SKU_ID . '[' . $loop . ']',
                    'label'         => __( 'TreeDots Actual SKU ID', 'wc-treedots-erp-full' ),
                    'wrapper_class' => 'form-row form-row-last',
                    'value'         => get_post_meta( $variation->ID, self::PRODUCT_ACTUAL_SKU_ID, true ),
                ]
            );
            woocommerce_wp_textarea_input(
                [
                    'id'            => self::PRODUCT_BUNDLE_JSON . '[' . $loop . ']',
                    'label'         => __( 'TreeDots bundle options JSON', 'wc-treedots-erp-full' ),
                    'wrapper_class' => 'form-row form-row-full',
                    'value'         => get_post_meta( $variation->ID, self::PRODUCT_BUNDLE_JSON, true ),
                ]
            );
        }

        public function save_variation_fields( int $variation_id, int $loop ): void {
            $sku_id = $_POST[ self::PRODUCT_SKU_ID ][ $loop ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $actual_id = $_POST[ self::PRODUCT_ACTUAL_SKU_ID ][ $loop ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $bundle_json = $_POST[ self::PRODUCT_BUNDLE_JSON ][ $loop ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

            $this->save_mapping_meta_values( $variation_id, $sku_id, $actual_id, $bundle_json );
        }

        public function add_order_meta_box(): void {
            $screen = class_exists( '\\Automattic\\WooCommerce\\Internal\\Admin\\Orders\\PageController' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
            add_meta_box(
                'wc_treedots_erp_box',
                __( 'TreeDots ERP', 'wc-treedots-erp-full' ),
                [ $this, 'render_order_meta_box' ],
                $screen,
                'side',
                'default'
            );
        }

        public function render_order_meta_box( $post_or_order ): void {
            $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0 );
            if ( ! $order ) {
                echo '<p>' . esc_html__( 'Order not found.', 'wc-treedots-erp-full' ) . '</p>';
                return;
            }

            echo '<p><strong>' . esc_html__( 'Sent:', 'wc-treedots-erp-full' ) . '</strong> ' . esc_html( $order->get_meta( self::META_SENT ) ? 'Yes' : 'No' ) . '</p>';
            echo '<p><strong>' . esc_html__( 'ERP Order ID:', 'wc-treedots-erp-full' ) . '</strong> ' . esc_html( (string) $order->get_meta( self::META_ERP_ORDER_ID ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'HTTP Code:', 'wc-treedots-erp-full' ) . '</strong> ' . esc_html( (string) $order->get_meta( self::META_LAST_HTTP_CODE ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Last Error:', 'wc-treedots-erp-full' ) . '</strong><br />' . esc_html( (string) $order->get_meta( self::META_LAST_ERROR ) ) . '</p>';
            echo '<details><summary>' . esc_html__( 'Last request', 'wc-treedots-erp-full' ) . '</summary><pre style="white-space:pre-wrap;max-height:180px;overflow:auto;">' . esc_html( (string) $order->get_meta( self::META_LAST_REQUEST ) ) . '</pre></details>';
            echo '<details><summary>' . esc_html__( 'Last debug snapshot', 'wc-treedots-erp-full' ) . '</summary><pre style="white-space:pre-wrap;max-height:220px;overflow:auto;">' . esc_html( (string) $order->get_meta( self::META_LAST_DEBUG ) ) . '</pre></details>';
            echo '<details><summary>' . esc_html__( 'Last response', 'wc-treedots-erp-full' ) . '</summary><pre style="white-space:pre-wrap;max-height:180px;overflow:auto;">' . esc_html( (string) $order->get_meta( self::META_LAST_RESPONSE ) ) . '</pre></details>';
        }

        private function save_mapping_meta( int $product_id ): void {
            $sku_id = $_POST[ self::PRODUCT_SKU_ID ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $actual_id = $_POST[ self::PRODUCT_ACTUAL_SKU_ID ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $bundle_json = $_POST[ self::PRODUCT_BUNDLE_JSON ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $this->save_mapping_meta_values( $product_id, $sku_id, $actual_id, $bundle_json );
        }

        private function save_mapping_meta_values( int $product_id, $sku_id, $actual_id, $bundle_json ): void {
            $sku_id = trim( (string) $sku_id );
            $actual_id = trim( (string) $actual_id );
            $bundle_json = trim( (string) $bundle_json );

            if ( '' === $sku_id ) {
                delete_post_meta( $product_id, self::PRODUCT_SKU_ID );
            } else {
                update_post_meta( $product_id, self::PRODUCT_SKU_ID, preg_replace( '/[^0-9]/', '', $sku_id ) );
            }

            if ( '' === $actual_id ) {
                delete_post_meta( $product_id, self::PRODUCT_ACTUAL_SKU_ID );
            } else {
                update_post_meta( $product_id, self::PRODUCT_ACTUAL_SKU_ID, preg_replace( '/[^0-9]/', '', $actual_id ) );
            }

            if ( '' === $bundle_json ) {
                delete_post_meta( $product_id, self::PRODUCT_BUNDLE_JSON );
            } else {
                update_post_meta( $product_id, self::PRODUCT_BUNDLE_JSON, wp_kses_post( $bundle_json ) );
            }
        }

        private function send_order( int $order_id, bool $force ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return false;
            }

            if ( ! $force && $order->get_meta( self::META_SENT ) ) {
                return true;
            }

            $lock_key = 'wc_treedots_send_lock_' . $order_id;
            if ( get_transient( $lock_key ) ) {
                $this->log( $order_id, 'info', 'Skipped because another send is already in progress.' );
                return false;
            }
            set_transient( $lock_key, 1, 30 );

            try {
                $payload = $this->build_order_payload( $order );
                $payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
                $debug_json = wp_json_encode( $this->build_debug_snapshot( $order, $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

                $order->update_meta_data( self::META_LAST_REQUEST, $payload_json );
                $order->update_meta_data( self::META_LAST_DEBUG, $debug_json );
                $order->delete_meta_data( self::META_LAST_ERROR );
                $order->save();

                $response = $this->request( 'POST', $this->get_settings()['orders_path'], $payload );
                if ( is_wp_error( $response ) ) {
                    $this->save_failure( $order, $response->get_error_message(), 0, '' );
                    return false;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = (string) wp_remote_retrieve_body( $response );
                $json = json_decode( $body, true );

                $order->update_meta_data( self::META_LAST_HTTP_CODE, $code );
                $order->update_meta_data( self::META_LAST_RESPONSE, $body );

                if ( $code >= 200 && $code < 300 ) {
                    $erp_order_id = is_array( $json ) && isset( $json['order']['id'] ) ? (string) $json['order']['id'] : '';
                    $order->update_meta_data( self::META_SENT, 1 );
                    $order->update_meta_data( self::META_SENT_AT, current_time( 'mysql' ) );
                    $order->update_meta_data( self::META_ERP_ORDER_ID, $erp_order_id );
                    $order->delete_meta_data( self::META_LAST_ERROR );
                    $order->save();

                    if ( 'yes' === $this->get_settings()['mark_processing_on_success'] ) {
                        $note = 'TreeDots ERP send succeeded.';
                        if ( '' !== $erp_order_id ) {
                            $note .= ' ERP order ID: ' . $erp_order_id;
                        }
                        $order->add_order_note( $note );
                    }

                    $this->log( $order_id, 'success', 'TreeDots ERP send succeeded. HTTP ' . $code . ( '' !== $erp_order_id ? ' ERP order ID: ' . $erp_order_id : '' ) );
                    return true;
                }

                $message = 'HTTP ' . $code . '. ' . ( $body !== '' ? wp_strip_all_tags( $body ) : 'Empty response body.' );
                $this->save_failure( $order, $message, $code, $body );
                return false;
            } catch ( Exception $e ) {
                $this->save_failure( $order, $e->getMessage(), 0, '' );
                return false;
            } finally {
                delete_transient( $lock_key );
            }
        }

        private function build_order_payload( WC_Order $order ): array {
            $settings = $this->get_settings();
            $sku_items = [];

            foreach ( $order->get_items( 'line_item' ) as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }

                $mapping = $this->resolve_line_item_mapping( $item );
                $line = [
                    'quantity' => (float) max( 1, (float) $item->get_quantity() ),
                    'skuId'    => (float) $mapping['sku_id'],
                ];

                if ( '' !== $mapping['actual_sku_id'] ) {
                    $line['actualSkuId'] = (float) $mapping['actual_sku_id'];
                }

                $bundle_options = ! empty( $mapping['marker_bundle_options'] )
                    ? $mapping['marker_bundle_options']
                    : $this->parse_bundle_options( $mapping['bundle_json'] );

                if ( ! empty( $bundle_options ) ) {
                    $line['bundleOptions'] = $bundle_options;
                }

                $sku_items[] = $line;
            }

            if ( empty( $sku_items ) ) {
                throw new Exception( 'Order has no sendable line items.' );
            }

            $contact_name = $this->get_contact_name( $order );
            $contact_email = $this->get_contact_email( $order );
            $contact_number = $this->sanitize_phone( (string) $order->get_billing_phone() );
            $delivery_address = $this->get_delivery_address( $order );
            $delivery_postal_code = $this->get_delivery_postcode( $order );
            $delivery_date = $this->get_delivery_date( $order );
            $delivery_time = $this->get_delivery_time( $order );
            $company_name = $this->get_company_name( $order );

            if ( '' === $contact_name ) {
                throw new Exception( 'Missing contact_name on order.' );
            }
            if ( '' === $contact_email ) {
                throw new Exception( 'Missing contact_email on order.' );
            }
            if ( '' === $contact_number ) {
                throw new Exception( 'Missing contact_number on order.' );
            }
            if ( '' === $delivery_address ) {
                throw new Exception( 'Missing delivery_address on order.' );
            }
            if ( '' === $delivery_postal_code ) {
                throw new Exception( 'Missing delivery_postal_code on order.' );
            }
            if ( '' === $delivery_date ) {
                throw new Exception( 'Missing deliveryDate on order.' );
            }
            if ( '' === $delivery_time ) {
                throw new Exception( 'Missing deliveryTime on order.' );
            }

            return [
                'skuItems'                => $sku_items,
                'disallowMergeOrder'      => 'yes' === $settings['disallow_merge_order'],
                'bypassDeliverySlotCheck' => 'yes' === $settings['bypass_delivery_slot_check'],
                'bypassInventoryCheck'    => 'yes' === $settings['bypass_inventory_check'],
                'paymentReferenceNumber'  => $this->get_payment_reference_number( $order ),
                'paymentMethod'           => $this->get_payment_method_for_payload( $order ),
                'deliveryTime'            => $delivery_time,
                'deliveryDate'            => $delivery_date,
                'delivery_postal_code'    => $delivery_postal_code,
                'delivery_address'        => $delivery_address,
                'contact_email'           => $contact_email,
                'contact_number'          => $contact_number,
                'contact_name'            => $contact_name,
                'company_name'            => $company_name,
            ];
        }

        private function resolve_line_item_mapping( WC_Order_Item_Product $item ): array {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $product_name = $item->get_name();

            $sku_id = '';
            $actual_sku_id = '';
            $bundle_json = '';
            $mapping_source = '';

            if ( $variation_id > 0 ) {
                $sku_id = (string) get_post_meta( $variation_id, self::PRODUCT_SKU_ID, true );
                $actual_sku_id = (string) get_post_meta( $variation_id, self::PRODUCT_ACTUAL_SKU_ID, true );
                $bundle_json = (string) get_post_meta( $variation_id, self::PRODUCT_BUNDLE_JSON, true );
                if ( '' !== $sku_id || '' !== $actual_sku_id || '' !== $bundle_json ) {
                    $mapping_source = 'variation';
                }
            }

            if ( '' === $sku_id && $product_id > 0 ) {
                $sku_id = (string) get_post_meta( $product_id, self::PRODUCT_SKU_ID, true );
                if ( '' !== $sku_id && '' === $mapping_source ) {
                    $mapping_source = 'product';
                }
            }
            if ( '' === $actual_sku_id && $product_id > 0 ) {
                $actual_sku_id = (string) get_post_meta( $product_id, self::PRODUCT_ACTUAL_SKU_ID, true );
                if ( '' !== $actual_sku_id && '' === $mapping_source ) {
                    $mapping_source = 'product';
                }
            }
            if ( '' === $bundle_json && $product_id > 0 ) {
                $bundle_json = (string) get_post_meta( $product_id, self::PRODUCT_BUNDLE_JSON, true );
                if ( '' !== $bundle_json && '' === $mapping_source ) {
                    $mapping_source = 'product';
                }
            }

            $marker_bundle_options = $this->extract_marker_bundle_options( $item );
            $is_bundle = ! empty( $marker_bundle_options ) || '' !== trim( $bundle_json );

            if ( '' === $sku_id ) {
                throw new Exception( sprintf( 'Missing TreeDots SKU mapping for product "%1$s" (product ID %2$d).', $product_name, $product_id ) );
            }

            if ( ! $is_bundle && '' === $actual_sku_id ) {
                throw new Exception( sprintf( 'Missing TreeDots Actual SKU mapping for product "%1$s" (product ID %2$d).', $product_name, $product_id ) );
            }

            return [
                'sku_id'               => preg_replace( '/[^0-9]/', '', $sku_id ),
                'actual_sku_id'        => preg_replace( '/[^0-9]/', '', $actual_sku_id ),
                'bundle_json'          => $bundle_json,
                'marker_bundle_options'=> $marker_bundle_options,
                'mapping_source'       => $mapping_source,
            ];
        }

        private function parse_bundle_options( string $bundle_json ): array {
            $bundle_json = trim( $bundle_json );
            if ( '' === $bundle_json ) {
                return [];
            }

            $decoded = json_decode( wp_kses_post( $bundle_json ), true );
            if ( ! is_array( $decoded ) ) {
                throw new Exception( 'Invalid TreeDots bundle options JSON.' );
            }

            $bundle_options = [];
            foreach ( $decoded as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                if ( empty( $row['bundleOptionId'] ) || empty( $row['skuId'] ) || empty( $row['actualSkuId'] ) ) {
                    continue;
                }

                $bundle_options[] = [
                    'bundleOptionId' => (float) preg_replace( '/[^0-9]/', '', (string) $row['bundleOptionId'] ),
                    'skuId'          => (float) preg_replace( '/[^0-9]/', '', (string) $row['skuId'] ),
                    'actualSkuId'    => (float) preg_replace( '/[^0-9]/', '', (string) $row['actualSkuId'] ),
                    'quantity'       => isset( $row['quantity'] ) ? (float) max( 1, (float) $row['quantity'] ) : 1.0,
                ];
            }

            return $bundle_options;
        }

        private function extract_marker_bundle_options( WC_Order_Item_Product $item ): array {
            $raw_blocks = [];

            foreach ( $item->get_meta_data() as $meta ) {
                if ( ! is_object( $meta ) || ! isset( $meta->value ) ) {
                    continue;
                }

                $value = maybe_unserialize( $meta->value );
                if ( is_array( $value ) || is_object( $value ) ) {
                    $json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                    if ( is_string( $json ) ) {
                        $raw_blocks[] = $json;
                    }
                    continue;
                }

                $raw_blocks[] = (string) $value;
            }

            $blob = implode( "
", array_filter( $raw_blocks ) );
            if ( '' === trim( $blob ) ) {
                return [];
            }

            preg_match_all( '/mbundleOptionId[^>]*>\s*([^<|]+)/i', $blob, $matches_bundle );
            preg_match_all( '/msku[^>]*>\s*([^<|]+)/i', $blob, $matches_sku );
            preg_match_all( '/macutalskuid[^>]*>\s*([^<|]+)/i', $blob, $matches_actual );

            $raw_bundles = ! empty( $matches_bundle[1] ) ? array_map( 'strval', $matches_bundle[1] ) : [];
            $raw_skus    = ! empty( $matches_sku[1] ) ? array_map( 'strval', $matches_sku[1] ) : [];
            $raw_actuals = ! empty( $matches_actual[1] ) ? array_map( 'strval', $matches_actual[1] ) : [];

            $count = min( count( $raw_bundles ), count( $raw_skus ), count( $raw_actuals ) );
            if ( $count <= 0 ) {
                return [];
            }

            $bundle_options = [];
            for ( $i = 0; $i < $count; $i++ ) {
                foreach ( $this->expand_marker_csv_triplets( $raw_bundles[ $i ], $raw_skus[ $i ], $raw_actuals[ $i ] ) as $row ) {
                    $bundle_options[] = [
                        'quantity'       => 1.0,
                        'bundleOptionId' => (float) $row['bundleOptionId'],
                        'skuId'          => (float) $row['skuId'],
                        'actualSkuId'    => (float) $row['actualSkuId'],
                    ];
                }
            }

            return $bundle_options;
        }

        /**
         * Split comma-separated numeric ids inside one marker capture (e.g. "745,736,755").
         *
         * @return string[]
         */
        private function split_marker_csv_ids( string $raw ): array {
            $raw = trim( $raw );
            if ( '' === $raw ) {
                return [];
            }
            $parts = preg_split( '/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY );
            $out = [];
            foreach ( $parts as $part ) {
                $d = preg_replace( '/[^0-9]/', '', (string) $part );
                if ( '' !== $d ) {
                    $out[] = $d;
                }
            }
            return $out;
        }

        /**
         * Expand one logical marker row: several bundleOptionIds with one skuId and one actualSkuId
         * become multiple bundle rows (sku/actual repeated). Lists of unequal length are padded:
         * a single value is repeated to match the longest list; shorter multi-value lists repeat their last id.
         *
         * @return array<int, array{bundleOptionId: string, skuId: string, actualSkuId: string}>
         */
        private function expand_marker_csv_triplets( string $raw_bundle, string $raw_sku, string $raw_actual ): array {
            $b = $this->split_marker_csv_ids( $raw_bundle );
            $s = $this->split_marker_csv_ids( $raw_sku );
            $a = $this->split_marker_csv_ids( $raw_actual );
            if ( empty( $b ) || empty( $s ) || empty( $a ) ) {
                return [];
            }

            $n = max( count( $b ), count( $s ), count( $a ) );
            $b = $this->normalize_marker_csv_ids_to_length( $b, $n );
            $s = $this->normalize_marker_csv_ids_to_length( $s, $n );
            $a = $this->normalize_marker_csv_ids_to_length( $a, $n );

            $out = [];
            for ( $i = 0; $i < $n; $i++ ) {
                if ( '' !== $b[ $i ] && '' !== $s[ $i ] && '' !== $a[ $i ] ) {
                    $out[] = [
                        'bundleOptionId' => $b[ $i ],
                        'skuId'          => $s[ $i ],
                        'actualSkuId'    => $a[ $i ],
                    ];
                }
            }
            return $out;
        }

        /**
         * @param string[] $ids
         * @return string[]
         */
        private function normalize_marker_csv_ids_to_length( array $ids, int $n ): array {
            $c = count( $ids );
            if ( $c === $n ) {
                return $ids;
            }
            if ( 1 === $c ) {
                return array_fill( 0, $n, $ids[0] );
            }
            if ( $c > $n ) {
                return array_slice( $ids, 0, $n );
            }
            $last = $ids[ $c - 1 ];
            while ( count( $ids ) < $n ) {
                $ids[] = $last;
            }
            return $ids;
        }

        private function get_delivery_date( WC_Order $order ): string {
            $meta_key = trim( (string) $this->get_settings()['delivery_date_meta_key'] );
            $value = '';
            if ( '' !== $meta_key ) {
                $value = trim( (string) $order->get_meta( $meta_key ) );
            }

            if ( '' === $value ) {
                $created = $order->get_date_created();
                if ( $created ) {
                    $value = $created->date_i18n( 'Y-m-d' );
                }
            }

            return $this->normalize_date( $value );
        }

        private function get_delivery_time( WC_Order $order ): string {
            $meta_key = trim( (string) $this->get_settings()['delivery_time_meta_key'] );
            $value = '';
            if ( '' !== $meta_key ) {
                $value = trim( (string) $order->get_meta( $meta_key ) );
            }

            if ( '' === $value ) {
                $value = '09:00';
            }

            return $this->normalize_time( $value );
        }

        private function normalize_date( string $value ): string {
            $value = trim( $value );
            if ( '' === $value ) {
                return '';
            }

            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                return $value;
            }

            $timestamp = strtotime( $value );
            return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : $value;
        }

        private function normalize_time( string $value ): string {
            $value = trim( $value );
            if ( '' === $value ) {
                return '';
            }

            if ( preg_match( '/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $value, $matches ) ) {
                $start = $this->normalize_hhmm( $matches[1] );
                $end = $this->normalize_hhmm( $matches[2] );
                return $start . '-' . $end;
            }

            if ( preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', $value ) ) {
                $base = substr( $value, 0, 5 );
                $start = $this->normalize_hhmm( $base );
                $end_timestamp = strtotime( $start . ':00 +1 hour' );
                $end = $end_timestamp ? gmdate( 'H:i', $end_timestamp ) : $start;
                return $start . '-' . $end;
            }

            $timestamp = strtotime( $value );
            if ( $timestamp ) {
                $start = gmdate( 'H:i', $timestamp );
                $end = gmdate( 'H:i', strtotime( '+1 hour', $timestamp ) );
                return $start . '-' . $end;
            }

            return $value;
        }

        private function normalize_hhmm( string $value ): string {
            $parts = explode( ':', trim( $value ) );
            $hour = isset( $parts[0] ) ? str_pad( preg_replace( '/[^0-9]/', '', (string) $parts[0] ), 2, '0', STR_PAD_LEFT ) : '00';
            $minute = isset( $parts[1] ) ? str_pad( preg_replace( '/[^0-9]/', '', (string) $parts[1] ), 2, '0', STR_PAD_LEFT ) : '00';
            return $hour . ':' . $minute;
        }

        private function get_delivery_address( WC_Order $order ): string {
            $parts = [
                $order->get_shipping_address_1(),
                $order->get_shipping_address_2(),
                $order->get_shipping_city(),
                $order->get_shipping_state(),
                $order->get_shipping_country(),
            ];
            $parts = $this->clean_parts( $parts );

            if ( empty( $parts ) ) {
                $parts = $this->clean_parts(
                    [
                        $order->get_billing_address_1(),
                        $order->get_billing_address_2(),
                        $order->get_billing_city(),
                        $order->get_billing_state(),
                        $order->get_billing_country(),
                    ]
                );
            }

            return implode( ', ', $parts );
        }

        private function get_delivery_postcode( WC_Order $order ): string {
            $postcode = trim( (string) $order->get_shipping_postcode() );
            if ( '' === $postcode ) {
                $postcode = trim( (string) $order->get_billing_postcode() );
            }
            return $postcode;
        }

        private function get_contact_name( WC_Order $order ): string {
            $name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
            if ( '' === $name ) {
                $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            }
            return $name;
        }

        private function get_contact_email( WC_Order $order ): string {
            $email = sanitize_email( (string) $order->get_billing_email() );
            if ( '' === $email || ! is_email( $email ) ) {
                $email = sanitize_email( (string) $this->get_settings()['default_contact_email'] );
            }
            return $email;
        }

        private function sanitize_phone( string $phone ): string {
            $phone = trim( $phone );
            if ( '' === $phone ) {
                return '';
            }
            $phone = preg_replace( '/[^0-9+]/', '', $phone );
            if ( strpos( $phone, '+' ) === 0 ) {
                return '+' . preg_replace( '/[^0-9]/', '', substr( $phone, 1 ) );
            }
            return preg_replace( '/[^0-9]/', '', $phone );
        }

        private function get_company_name( WC_Order $order ): string {
            $company = trim( (string) $order->get_shipping_company() );
            if ( '' === $company ) {
                $company = trim( (string) $order->get_billing_company() );
            }
            if ( '' === $company ) {
                $company = trim( (string) $this->get_settings()['company_name_fallback'] );
            }
            if ( '' === $company ) {
                $company = get_bloginfo( 'name' );
            }
            return $company;
        }

        private function get_payment_method_for_payload( WC_Order $order ): string {
            $override = trim( (string) $this->get_settings()['payment_method_override'] );
            if ( '' !== $override ) {
                return $override;
            }

            $method = trim( (string) $order->get_payment_method_title() );
            if ( '' === $method ) {
                $method = trim( (string) $order->get_payment_method() );
            }
            if ( '' === $method ) {
                $method = 'WooCommerce';
            }
            return $method;
        }

        private function get_payment_reference_number( WC_Order $order ): string {
            $txn = trim( (string) $order->get_transaction_id() );
            if ( '' !== $txn ) {
                return $txn;
            }
            return (string) $order->get_order_number();
        }

        private function clean_parts( array $parts ): array {
            $parts = array_map(
                static function ( $value ) {
                    return trim( (string) $value );
                },
                $parts
            );
            return array_values( array_filter( $parts ) );
        }

        private function build_debug_snapshot( WC_Order $order, array $payload ): array {
            $lines = [];
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }

                $mapping = $this->resolve_line_item_mapping( $item );
                $lines[] = [
                    'name'                => $item->get_name(),
                    'quantity'            => $item->get_quantity(),
                    'product_id'          => $item->get_product_id(),
                    'variation_id'        => $item->get_variation_id(),
                    'mapping_source'      => $mapping['mapping_source'],
                    'parent_sku_id'       => $mapping['sku_id'],
                    'parent_actual_sku_id'=> $mapping['actual_sku_id'],
                    'bundle_options_from_markers' => $mapping['marker_bundle_options'],
                    'bundle_json'         => $mapping['bundle_json'],
                ];
            }

            return [
                'generated_at' => current_time( 'mysql' ),
                'order_id'     => $order->get_id(),
                'payload'      => $payload,
                'line_items'   => $lines,
            ];
        }

        private function request( string $method, string $path, ?array $body = null ) {
            $settings = $this->get_settings();
            $url = untrailingslashit( $settings['base_url'] ) . $this->sanitize_path( $path );

            $args = [
                'method'  => strtoupper( $method ),
                'timeout' => (int) $settings['request_timeout'],
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $settings['bearer_token'],
                ],
            ];

            if ( null !== $body ) {
                $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }

            return wp_remote_request( $url, $args );
        }

        private function save_failure( WC_Order $order, string $message, int $code, string $body ): void {
            $order_id = $order->get_id();
            $order->update_meta_data( self::META_LAST_ERROR, $message );
            if ( $code > 0 ) {
                $order->update_meta_data( self::META_LAST_HTTP_CODE, $code );
            }
            if ( '' !== $body ) {
                $order->update_meta_data( self::META_LAST_RESPONSE, $body );
            }
            $order->save();
            $order->add_order_note( 'TreeDots ERP send failed: ' . $message );
            $this->log( $order_id, 'error', $message );
        }

        private function render_logs_table(): void {
            $logs = get_option( self::LOG_OPTION, [] );
            if ( ! is_array( $logs ) || empty( $logs ) ) {
                echo '<p>' . esc_html__( 'No logs yet.', 'wc-treedots-erp-full' ) . '</p>';
                return;
            }

            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Order</th><th>Level</th><th>Message</th></tr></thead><tbody>';
            foreach ( array_reverse( $logs ) as $log ) {
                $order_id = isset( $log['order_id'] ) ? (int) $log['order_id'] : 0;
                $order_link = $order_id ? get_edit_post_link( $order_id ) : '';
                echo '<tr>';
                echo '<td>' . esc_html( (string) ( $log['time'] ?? '' ) ) . '</td>';
                echo '<td>';
                if ( $order_link ) {
                    echo '<a href="' . esc_url( $order_link ) . '">#' . esc_html( (string) $order_id ) . '</a>';
                } else {
                    echo esc_html( (string) $order_id );
                }
                echo '</td>';
                echo '<td>' . esc_html( strtoupper( (string) ( $log['level'] ?? '' ) ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $log['message'] ?? '' ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        private function log( int $order_id, string $level, string $message ): void {
            if ( 'yes' !== $this->get_settings()['log_enabled'] ) {
                return;
            }

            $logs = get_option( self::LOG_OPTION, [] );
            if ( ! is_array( $logs ) ) {
                $logs = [];
            }

            $logs[] = [
                'time'     => current_time( 'mysql' ),
                'order_id' => $order_id,
                'level'    => $level,
                'message'  => $message,
            ];

            if ( count( $logs ) > 200 ) {
                $logs = array_slice( $logs, -200 );
            }

            update_option( self::LOG_OPTION, $logs, false );
        }

        private function redirect_notice( string $type, string $message ): void {
            $url = add_query_arg(
                [
                    'page'                => 'wc-treedots-erp-full',
                    'wc_treedots_notice'  => $type,
                    'wc_treedots_message' => rawurlencode( $message ),
                ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $url );
            exit;
        }

        private function sanitize_path( $path ): string {
            $path = trim( (string) $path );
            if ( '' === $path ) {
                return '';
            }
            return '/' . ltrim( $path, '/' );
        }

        private function get_settings(): array {
            $settings = get_option( self::OPTION_KEY, [] );
            return wp_parse_args( is_array( $settings ) ? $settings : [], $this->get_default_settings() );
        }

        private function get_default_settings(): array {
            return [
                'enabled'                    => 'no',
                'base_url'                   => 'https://staging-external-api.thetreedots.com',
                'orders_path'                => '/orders',
                'me_path'                    => '/me',
                'bearer_token'               => '',
                'request_timeout'            => 20,
                'delivery_date_meta_key'     => '',
                'delivery_time_meta_key'     => '',
                'payment_method_override'    => 'Card',
                'default_contact_email'      => 'realname@gmail.com',
                'company_name_fallback'      => '',
                'bypass_delivery_slot_check' => 'yes',
                'bypass_inventory_check'     => 'no',
                'disallow_merge_order'       => 'yes',
                'success_url'                => '',
                'fail_url'                   => '',
                'log_enabled'                => 'yes',
                'mark_processing_on_success' => 'yes',
            ];
        }
    }

    WC_TreeDots_ERP_Full::instance();
}
