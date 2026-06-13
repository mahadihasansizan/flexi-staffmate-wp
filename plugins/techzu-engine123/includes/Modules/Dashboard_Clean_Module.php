<?php
namespace Techzu\Engine\Modules;

use Techzu\Engine\Admin\Settings_Page;
use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dashboard_Clean_Module implements Module_Interface {
    const NOTICE_PAGE_SLUG     = 'techzu-engine-notices';
    const NOTICE_USER_META_KEY = 'tz_engine_notice_center_items';

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var int
     */
    protected $notice_buffer_level = 0;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function setting_key() {
        return 'module_dashboard_clean';
    }

    public function register() {
        add_action( 'wp_dashboard_setup', array( $this, 'strip_dashboard_widgets' ), 999 );
        add_action( 'admin_init', array( $this, 'remove_welcome_panel' ) );
        add_action( 'admin_init', array( $this, 'handle_notice_actions' ), 5 );
        add_action( 'admin_menu', array( $this, 'register_notice_page' ), 60 );
        add_action( 'admin_bar_menu', array( $this, 'trim_admin_bar' ), 999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_notice_hub_node' ), 100 );
        add_action( 'network_admin_notices', array( $this, 'capture_notices_open' ), 0 );
        add_action( 'network_admin_notices', array( $this, 'capture_notices_close' ), PHP_INT_MAX );
        add_action( 'user_admin_notices', array( $this, 'capture_notices_open' ), 0 );
        add_action( 'user_admin_notices', array( $this, 'capture_notices_close' ), PHP_INT_MAX );
        add_action( 'admin_notices', array( $this, 'capture_notices_open' ), 0 );
        add_action( 'admin_notices', array( $this, 'capture_notices_close' ), PHP_INT_MAX );
        add_action( 'all_admin_notices', array( $this, 'capture_notices_open' ), 0 );
        add_action( 'all_admin_notices', array( $this, 'capture_notices_close' ), PHP_INT_MAX );
    }

    /**
     * @return void
     */
    public function remove_welcome_panel() {
        remove_action( 'welcome_panel', 'wp_welcome_panel' );
    }

    /**
     * @return void
     */
    public function strip_dashboard_widgets() {
        $remove = array(
            'dashboard_right_now',
            'dashboard_activity',
            'dashboard_quick_press',
            'dashboard_primary',
            'dashboard_secondary',
            'dashboard_site_health',
            'dashboard_php_nag',
        );

        foreach ( $remove as $id ) {
            remove_meta_box( $id, 'dashboard', 'normal' );
            remove_meta_box( $id, 'dashboard', 'side' );
        }
    }

    /**
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
     * @return void
     */
    public function trim_admin_bar( $wp_admin_bar ) {
        if ( ! is_admin() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'dashboard' !== $screen->id ) {
            return;
        }
        $wp_admin_bar->remove_node( 'new-content' );
    }

    /**
     * @param string $hook_suffix Current admin page.
     * @return void
     */
    public function enqueue_dashboard_assets( $hook_suffix ) {
        if ( 'index.php' !== $hook_suffix && false === strpos( $hook_suffix, self::NOTICE_PAGE_SLUG ) ) {
            return;
        }

        wp_enqueue_style(
            'tz-engine-admin-dashboard',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-dashboard.css',
            array( 'tz-engine-admin-ui' ),
            TZ_ENGINE_VERSION
        );

        if ( 'index.php' === $hook_suffix ) {
            wp_enqueue_script(
                'tz-engine-admin-dashboard',
                TZ_ENGINE_PLUGIN_URL . 'assets/js/admin-dashboard.js',
                array(),
                TZ_ENGINE_VERSION,
                true
            );

            wp_localize_script(
                'tz-engine-admin-dashboard',
                'tzEngineDashboard',
                array(
                    'i18n' => array(
                        'drawerTitle' => __( 'Plugin & system notices', 'techzu-engine' ),
                        'empty'       => __( 'No notices right now.', 'techzu-engine' ),
                        'openHub'     => __( 'Open notice hub', 'techzu-engine' ),
                    ),
                )
            );
        }
    }

    /**
     * @return void
     */
    public function register_notice_page() {
        add_submenu_page(
            Settings_Page::MENU_SLUG,
            __( 'Notices', 'techzu-engine' ),
            __( 'Notice', 'techzu-engine' ),
            'manage_options',
            self::NOTICE_PAGE_SLUG,
            array( $this, 'render_notice_page' )
        );
    }

    /**
     * @return bool
     */
    protected function should_capture_notices() {
        if ( ! is_admin() || wp_doing_ajax() ) {
            return false;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::NOTICE_PAGE_SLUG === $page ) {
            return false;
        }
        return true;
    }

    /**
     * @return void
     */
    public function capture_notices_open() {
        if ( ! $this->should_capture_notices() ) {
            return;
        }
        if ( 0 === $this->notice_buffer_level ) {
            ob_start();
        }
        ++$this->notice_buffer_level;
    }

    /**
     * @return void
     */
    public function capture_notices_close() {
        if ( $this->notice_buffer_level <= 0 ) {
            return;
        }

        --$this->notice_buffer_level;
        if ( 0 !== $this->notice_buffer_level ) {
            return;
        }

        $html = ob_get_clean();
        if ( is_string( $html ) && '' !== trim( $html ) ) {
            $this->store_notices_from_html( $html );
        }
    }

    /**
     * @param string $html Notices HTML.
     * @return void
     */
    protected function store_notices_from_html( $html ) {
        $blocks = array();
        if ( preg_match_all( '/<div\b[^>]*class=["\'][^"\']*(?:notice|updated|update-nag|error)[^"\']*["\'][^>]*>[\s\S]*?<\/div>/i', $html, $m ) ) {
            $blocks = $m[0];
        } elseif ( '' !== trim( $html ) ) {
            $blocks[] = $html;
        }

        if ( empty( $blocks ) ) {
            return;
        }

        $screen_id = '';
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ! empty( $screen->id ) ) {
                $screen_id = sanitize_key( (string) $screen->id );
            }
        }

        $items = $this->get_user_notices();
        $now   = time();

        foreach ( $blocks as $block ) {
            $clean = trim( wp_kses_post( $block ) );
            if ( '' === $clean ) {
                continue;
            }

            $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $clean ) ) );
            if ( '' === $plain ) {
                continue;
            }

            $id = sha1( strtolower( $plain ) );
            if ( isset( $items[ $id ] ) && is_array( $items[ $id ] ) ) {
                $items[ $id ]['last_seen'] = $now;
                $items[ $id ]['count']     = isset( $items[ $id ]['count'] ) ? (int) $items[ $id ]['count'] + 1 : 1;
                if ( empty( $items[ $id ]['read'] ) ) {
                    $items[ $id ]['html'] = $clean;
                }
                continue;
            }

            $items[ $id ] = array(
                'id'         => $id,
                'html'       => $clean,
                'plain'      => function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 500 ) : substr( $plain, 0, 500 ),
                'read'       => false,
                'screen'     => $screen_id,
                'count'      => 1,
                'created_at' => $now,
                'last_seen'  => $now,
            );
        }

        if ( count( $items ) > 300 ) {
            uasort(
                $items,
                static function ( $a, $b ) {
                    return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
                }
            );
            $items = array_slice( $items, 0, 300, true );
        }

        update_user_meta( get_current_user_id(), self::NOTICE_USER_META_KEY, $items );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function get_user_notices() {
        $items = get_user_meta( get_current_user_id(), self::NOTICE_USER_META_KEY, true );
        return is_array( $items ) ? $items : array();
    }

    /**
     * @return int
     */
    protected function get_unread_notice_count() {
        $items = $this->get_user_notices();
        $n     = 0;
        foreach ( $items as $item ) {
            if ( empty( $item['read'] ) ) {
                ++$n;
            }
        }
        return $n;
    }

    /**
     * @return void
     */
    public function handle_notice_actions() {
        if ( empty( $_POST['tz_notice_action_nonce'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage notices.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tz_notice_action_nonce'] ) ), 'tz_notice_action' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        $items  = $this->get_user_notices();
        $action = isset( $_POST['tz_notice_action'] ) ? sanitize_key( wp_unslash( $_POST['tz_notice_action'] ) ) : '';
        $id     = isset( $_POST['tz_notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tz_notice_id'] ) ) : '';

        if ( 'mark_read' === $action && '' !== $id && isset( $items[ $id ] ) ) {
            $items[ $id ]['read'] = true;
        } elseif ( 'mark_unread' === $action && '' !== $id && isset( $items[ $id ] ) ) {
            $items[ $id ]['read'] = false;
        } elseif ( 'dismiss' === $action && '' !== $id && isset( $items[ $id ] ) ) {
            unset( $items[ $id ] );
        } elseif ( 'mark_all_read' === $action ) {
            foreach ( $items as &$item ) {
                if ( is_array( $item ) ) {
                    $item['read'] = true;
                }
            }
            unset( $item );
        } elseif ( 'clear_read' === $action ) {
            foreach ( $items as $k => $item ) {
                if ( ! empty( $item['read'] ) ) {
                    unset( $items[ $k ] );
                }
            }
        } elseif ( 'clear_all' === $action ) {
            $items = array();
        }

        update_user_meta( get_current_user_id(), self::NOTICE_USER_META_KEY, $items );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::NOTICE_PAGE_SLUG . '&tz_notice=updated' ) );
        exit;
    }

    /**
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
     * @return void
     */
    public function register_notice_hub_node( $wp_admin_bar ) {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $count      = $this->get_unread_notice_count();
        $badge      = $count > 0 ? '<span class="tz-engine-notice-badge" aria-hidden="true">' . (int) $count . '</span>' : '';
        $notice_url = admin_url( 'admin.php?page=' . self::NOTICE_PAGE_SLUG );

        $wp_admin_bar->add_node(
            array(
                'id'    => 'tz-engine-notice-hub',
                'title' => '<span class="ab-icon dashicons dashicons-bell"></span><span class="tz-engine-hub-label">' . esc_html__( 'Notices', 'techzu-engine' ) . '</span>' . $badge,
                'href'  => esc_url( $notice_url ),
                'meta'  => array(
                    'title' => esc_attr__( 'Open Notice Center', 'techzu-engine' ),
                ),
            )
        );
    }

    /**
     * @return void
     */
    public function render_notice_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view notices.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        $items = $this->get_user_notices();
        uasort(
            $items,
            static function ( $a, $b ) {
                if ( empty( $a['read'] ) !== empty( $b['read'] ) ) {
                    return empty( $a['read'] ) ? -1 : 1;
                }
                return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
            }
        );
        ?>
        <div class="wrap tz-engine-settings tz-engine-notice-center">
            <div class="tz-engine-settings__hero">
                <h1 class="tz-engine-settings__title"><?php esc_html_e( 'Notice Center', 'techzu-engine' ); ?></h1>
                <p class="tz-engine-settings__lead"><?php esc_html_e( 'System and plugin notices are collected here. Mark them as read so they do not clutter wp-admin screens.', 'techzu-engine' ); ?></p>
            </div>
            <?php if ( isset( $_GET['tz_notice'] ) && 'updated' === sanitize_key( wp_unslash( $_GET['tz_notice'] ) ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Notice center updated.', 'techzu-engine' ); ?></p></div>
            <?php endif; ?>

            <div class="tz-engine-settings__card">
                <form method="post" action="">
                    <?php wp_nonce_field( 'tz_notice_action', 'tz_notice_action_nonce' ); ?>
                    <button type="submit" class="button" name="tz_notice_action" value="mark_all_read"><?php esc_html_e( 'Mark all as read', 'techzu-engine' ); ?></button>
                    <button type="submit" class="button" name="tz_notice_action" value="clear_read"><?php esc_html_e( 'Delete read', 'techzu-engine' ); ?></button>
                    <button type="submit" class="button" name="tz_notice_action" value="clear_all"><?php esc_html_e( 'Delete all', 'techzu-engine' ); ?></button>
                </form>
            </div>

            <div class="tz-engine-settings__card">
                <?php if ( empty( $items ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No notices collected yet.', 'techzu-engine' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $items as $item ) : ?>
                        <div class="tz-engine-notice-center__row<?php echo ! empty( $item['read'] ) ? ' is-read' : ''; ?>">
                            <div class="tz-engine-notice-center__content">
                                <?php echo wp_kses_post( (string) ( $item['html'] ?? '' ) ); ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: screen slug, 2: count */
                                        esc_html__( 'Source: %1$s · Seen: %2$d', 'techzu-engine' ),
                                        esc_html( (string) ( $item['screen'] ?? 'unknown' ) ),
                                        (int) ( $item['count'] ?? 1 )
                                    );
                                    ?>
                                </p>
                            </div>
                            <form method="post" action="" class="tz-engine-notice-center__actions">
                                <?php wp_nonce_field( 'tz_notice_action', 'tz_notice_action_nonce' ); ?>
                                <input type="hidden" name="tz_notice_id" value="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>" />
                                <?php if ( empty( $item['read'] ) ) : ?>
                                    <button type="submit" class="button button-secondary" name="tz_notice_action" value="mark_read"><?php esc_html_e( 'Mark read', 'techzu-engine' ); ?></button>
                                <?php else : ?>
                                    <button type="submit" class="button button-secondary" name="tz_notice_action" value="mark_unread"><?php esc_html_e( 'Mark unread', 'techzu-engine' ); ?></button>
                                <?php endif; ?>
                                <button type="submit" class="button" name="tz_notice_action" value="dismiss"><?php esc_html_e( 'Delete', 'techzu-engine' ); ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
