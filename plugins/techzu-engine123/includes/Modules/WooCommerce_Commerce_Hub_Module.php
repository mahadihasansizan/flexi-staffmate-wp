<?php
namespace Techzu\Engine\Modules;

use Techzu\Engine\Services\WooCommerce_Snapshot_Data;
use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce shop overview: WordPress dashboard widget + WooCommerce Home panel.
 * Loads only when WooCommerce is active and the module is enabled in Techzu Engine settings.
 */
class WooCommerce_Commerce_Hub_Module implements Module_Interface {
    /**
     * @var Settings
     */
    protected $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function setting_key() {
        return 'module_wc_commerce_hub';
    }

    public function register() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'admin_notices', array( $this, 'maybe_render_wc_home_panel' ), 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'woocommerce_after_order_object_save', array( $this, 'bump_snapshot_cache' ), 99 );
        add_action( 'woocommerce_delete_order', array( $this, 'bump_snapshot_cache' ), 99 );
        add_action( 'comment_post', array( $this, 'bump_snapshot_cache_on_review' ), 99, 2 );
    }

    /**
     * @return void
     */
    public function bump_snapshot_cache() {
        update_option( 'tz_engine_wc_snap_ver', (string) microtime( true ), false );
    }

    /**
     * @param int        $comment_id Comment ID.
     * @param int|string $approved Approved.
     * @return void
     */
    public function bump_snapshot_cache_on_review( $comment_id, $approved ) {
        if ( 1 !== (int) $approved && '1' !== $approved ) {
            return;
        }
        $comment = get_comment( $comment_id );
        if ( $comment && 'review' === $comment->comment_type ) {
            $this->bump_snapshot_cache();
        }
    }

    /**
     * @return bool
     */
    public static function user_can_view() {
        return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' );
    }

    /**
     * @return void
     */
    public function register_dashboard_widget() {
        if ( ! self::user_can_view() ) {
            return;
        }

        wp_add_dashboard_widget(
            'tz_engine_wc_commerce_hub',
            __( 'Shop overview', 'techzu-engine' ),
            array( $this, 'render_dashboard_widget' ),
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * @param string $hook_suffix Hook.
     * @return void
     */
    public function enqueue_assets( $hook_suffix ) {
        $load = false;
        if ( 'index.php' === $hook_suffix && self::user_can_view() ) {
            $load = true;
        }
        if ( 'woocommerce_page_wc-admin' === $hook_suffix && self::user_can_view() ) {
            $load = true;
        }

        if ( ! $load ) {
            return;
        }

        wp_enqueue_style(
            'tz-engine-wc-commerce-hub',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-wc-commerce-hub.css',
            array( 'tz-engine-admin-ui' ),
            TZ_ENGINE_VERSION
        );
    }

    /**
     * Renders above WooCommerce admin content on the Home screen only.
     *
     * @return void
     */
    public function maybe_render_wc_home_panel() {
        if ( ! self::user_can_view() ) {
            return;
        }

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'woocommerce_page_wc-admin' !== $screen->id ) {
            return;
        }

        $raw  = isset( $_GET['path'] ) ? wp_unslash( $_GET['path'] ) : '';
        $path = strtolower( trim( rawurldecode( is_string( $raw ) ? $raw : '' ), '/' ) );
        if ( '' !== $path && 'home' !== $path ) {
            return;
        }

        $this->render_hub_markup( 'tz-engine-wc-hub--wc-home' );
    }

    /**
     * @return void
     */
    public function render_dashboard_widget() {
        if ( ! self::user_can_view() ) {
            return;
        }
        $this->render_hub_markup( 'tz-engine-wc-hub--wp-dashboard' );
    }

    /**
     * @param string $extra_class Modifier class.
     * @return void
     */
    protected function render_hub_markup( $extra_class ) {
        $data = ( new WooCommerce_Snapshot_Data() )->get_snapshot();

        $today = $data['today'];
        $month = $data['month'];

        $analytics_url = WooCommerce_Snapshot_Data::get_analytics_url();
        $revenue_url   = WooCommerce_Snapshot_Data::get_revenue_analytics_url();

        $source_note = '';
        if ( 'analytics' === $today['source'] ) {
            $source_note = __( 'Figures match WooCommerce Analytics (order stats).', 'techzu-engine' );
        } elseif ( 'orders_fallback' === $today['source'] ) {
            $source_note = __( 'Analytics had no rows for today yet; values are summed from live orders.', 'techzu-engine' );
        } else {
            $source_note = __( 'Values are summed from live orders (Analytics unavailable).', 'techzu-engine' );
        }

        ?>
        <div id="tz-engine-wc-commerce-hub" class="tz-engine-ui tz-engine-wc-hub <?php echo esc_attr( $extra_class ); ?>">
            <div class="tz-engine-wc-hub__shell tz-engine-glass">
                <div class="tz-engine-wc-hub__head">
                    <h2 class="tz-engine-wc-hub__headline"><?php esc_html_e( 'Shop overview', 'techzu-engine' ); ?></h2>
                </div>

                <div class="tz-engine-wc-hub__tiles">
                    <section class="tz-engine-wc-hub__tile tz-engine-wc-hub__tile--today" aria-labelledby="tz-wc-today-heading">
                        <span id="tz-wc-today-heading" class="tz-engine-wc-hub__eyebrow"><?php esc_html_e( 'Today', 'techzu-engine' ); ?></span>
                        <div class="tz-engine-wc-hub__metrics" role="group" aria-label="<?php esc_attr_e( 'Today', 'techzu-engine' ); ?>">
                            <div class="tz-engine-wc-hub__metric">
                                <p class="tz-engine-wc-hub__stat-lg"><?php echo esc_html( (string) (int) $today['orders'] ); ?></p>
                                <p class="tz-engine-wc-hub__stat-caption"><?php esc_html_e( 'Orders', 'techzu-engine' ); ?></p>
                            </div>
                            <div class="tz-engine-wc-hub__metric">
                                <p class="tz-engine-wc-hub__stat-lg"><?php echo esc_html( (string) (int) $today['items'] ); ?></p>
                                <p class="tz-engine-wc-hub__stat-caption"><?php esc_html_e( 'Items sold', 'techzu-engine' ); ?></p>
                            </div>
                            <div class="tz-engine-wc-hub__metric">
                                <p class="tz-engine-wc-hub__stat-lg tz-engine-wc-hub__stat-lg--price"><?php echo wp_kses_post( wc_price( $today['revenue'] ) ); ?></p>
                                <p class="tz-engine-wc-hub__stat-caption"><?php esc_html_e( 'Net revenue', 'techzu-engine' ); ?></p>
                            </div>
                        </div>
                    </section>
                    <section class="tz-engine-wc-hub__tile tz-engine-wc-hub__tile--month" aria-labelledby="tz-wc-month-heading">
                        <span id="tz-wc-month-heading" class="tz-engine-wc-hub__eyebrow tz-engine-wc-hub__eyebrow--accent"><?php esc_html_e( 'This month', 'techzu-engine' ); ?></span>
                        <div class="tz-engine-wc-hub__metrics" role="group" aria-label="<?php esc_attr_e( 'This month', 'techzu-engine' ); ?>">
                            <div class="tz-engine-wc-hub__metric">
                                <p class="tz-engine-wc-hub__stat-lg tz-engine-wc-hub__stat-lg--accent"><?php echo esc_html( (string) (int) $month['orders'] ); ?></p>
                                <p class="tz-engine-wc-hub__stat-caption"><?php esc_html_e( 'Orders', 'techzu-engine' ); ?></p>
                            </div>
                            <div class="tz-engine-wc-hub__metric">
                                <p class="tz-engine-wc-hub__stat-lg tz-engine-wc-hub__stat-lg--accent"><?php echo esc_html( (string) (int) $month['items'] ); ?></p>
                                <p class="tz-engine-wc-hub__stat-caption"><?php esc_html_e( 'Items sold', 'techzu-engine' ); ?></p>
                            </div>
                            <div class="tz-engine-wc-hub__metric">
                                <p class="tz-engine-wc-hub__stat-lg tz-engine-wc-hub__stat-lg--accent tz-engine-wc-hub__stat-lg--price"><?php echo wp_kses_post( wc_price( $month['revenue'] ) ); ?></p>
                                <p class="tz-engine-wc-hub__stat-caption"><?php esc_html_e( 'Net revenue', 'techzu-engine' ); ?></p>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="tz-engine-wc-hub__foot">
                    <div class="tz-engine-wc-hub__actions tz-engine-wc-hub__actions--bottom">
                        <a class="tz-engine-btn tz-engine-btn--ghost" href="<?php echo esc_url( $revenue_url ); ?>"><?php esc_html_e( 'Revenue report', 'techzu-engine' ); ?></a>
                        <a class="tz-engine-btn tz-engine-btn--primary" href="<?php echo esc_url( $analytics_url ); ?>"><?php esc_html_e( 'Open Analytics', 'techzu-engine' ); ?></a>
                    </div>
                    <p class="tz-engine-wc-hub__source"><?php echo esc_html( $source_note ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
}
