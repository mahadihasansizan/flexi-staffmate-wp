<?php
namespace Techzu\Engine\Admin;

use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Appearance_Page {
    const SLUG = 'techzu-engine-admin-appearance';

    /**
     * @var Admin_Appearance_Store
     */
    protected $store;

    /**
     * @var Settings
     */
    protected $settings;

    public function __construct( Admin_Appearance_Store $store, Settings $settings ) {
        $this->store    = $store;
        $this->settings = $settings;
    }

    /**
     * @return void
     */
    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_submenu' ), 40 );
        add_action( 'admin_init', array( $this, 'maybe_export_appearance' ), 0 );
        add_action( 'admin_init', array( $this, 'maybe_import_appearance' ), 0 );
        add_action( 'admin_init', array( $this, 'handle_post' ), 10 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_filter( 'admin_body_class', array( $this, 'body_class' ) );
    }

    /**
     * @param string $classes Classes.
     * @return string
     */
    public function body_class( $classes ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && false !== strpos( $screen->id, self::SLUG ) ) {
            $classes .= ' tz-engine-admin-appearance-page';
        }
        return $classes;
    }

    /**
     * @return void
     */
    public function register_submenu() {
        add_submenu_page(
            Settings_Page::MENU_SLUG,
            __( 'Admin appearance', 'techzu-engine' ),
            __( 'Appearance', 'techzu-engine' ),
            'manage_options',
            self::SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * @param string $hook_suffix Hook.
     * @return void
     */
    public function enqueue( $hook_suffix ) {
        if ( false === strpos( $hook_suffix, self::SLUG ) ) {
            return;
        }
        wp_enqueue_style(
            'tz-engine-admin-ui',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-ui.css',
            array(),
            TZ_ENGINE_VERSION
        );
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'tz-engine-admin-appearance',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-appearance-page.css',
            array( 'tz-engine-admin-ui', 'dashicons' ),
            TZ_ENGINE_VERSION
        );
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_script(
            'tz-engine-admin-appearance',
            TZ_ENGINE_PLUGIN_URL . 'assets/js/admin-appearance-page.js',
            array( 'jquery', 'jquery-ui-sortable' ),
            TZ_ENGINE_VERSION,
            true
        );
        $saved_for_js = $this->store->get_all();

        wp_localize_script(
            'tz-engine-admin-appearance',
            'tzEngineAdminAppearance',
            array(
                'removeLabel'             => __( 'Remove', 'techzu-engine' ),
                'separatorDefaultLbl'     => __( 'Custom separator', 'techzu-engine' ),
                'separatorDragTitle'      => __( 'Drag to reorder', 'techzu-engine' ),
                'submenuLayoutCanonical'  => ! empty( $saved_for_js['submenu_layout_enabled'] )
                    ? $this->get_canonical_submenu_layout_for_compare()
                    : array(),
                'clearSubmenuConfirm'     => __( 'Clear saved submenu layout and restore WordPress / plugin default order everywhere in wp-admin? Other settings on this page will still be saved.', 'techzu-engine' ),
            )
        );
    }

    /**
     * @return void
     */
    public function maybe_export_appearance() {
        if ( empty( $_GET['tz_export_appearance'] ) || '1' !== (string) $_GET['tz_export_appearance'] ) {
            return;
        }
        $ex_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::SLUG !== $ex_page ) {
            return;
        }
        if ( ! $this->settings->is_module_enabled( 'module_admin_appearance' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tz_engine_export_appearance' ) ) {
            return;
        }

        $data    = $this->store->get_all();
        $payload = array(
            'version'     => 1,
            'exported_at' => gmdate( 'c' ),
            'data'        => array(),
        );
        foreach ( Admin_Appearance_Store::allowed_keys() as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                $payload['data'][ $key ] = $data[ $key ];
            }
        }

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="techzu-engine-admin-appearance.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * @return void
     */
    public function maybe_import_appearance() {
        if ( empty( $_POST['tz_engine_import_appearance_submit'] ) ) {
            return;
        }
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }
        $ap_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::SLUG !== $ap_page ) {
            return;
        }
        if ( ! $this->settings->is_module_enabled( 'module_admin_appearance' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to import admin appearance.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }
        if ( ! isset( $_POST['tz_engine_admin_appearance_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tz_engine_admin_appearance_import_nonce'] ) ), 'tz_engine_admin_appearance_import' ) ) {
            $this->redirect_with_arg( 'import_bad_nonce' );
        }

        $raw = '';
        if ( ! empty( $_FILES['tz_import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['tz_import_file']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['tz_import_file']['error'] ) {
            $uploaded = $_FILES['tz_import_file'];
            $max      = (int) apply_filters( 'tz_engine_admin_appearance_import_max_bytes', 1048576 );
            if ( isset( $uploaded['size'] ) && (int) $uploaded['size'] > $max ) {
                $this->redirect_with_arg( 'import_too_large' );
            }
            $ftype = wp_check_filetype( $uploaded['name'] );
            if ( empty( $ftype['ext'] ) || 'json' !== strtolower( (string) $ftype['ext'] ) ) {
                $this->redirect_with_arg( 'import_bad_type' );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local uploaded tmp file.
            $raw = file_get_contents( $uploaded['tmp_name'] );
        } elseif ( isset( $_POST['tz_import_json'] ) ) {
            $raw = wp_unslash( (string) $_POST['tz_import_json'] );
        }

        if ( is_string( $raw ) && strlen( $raw ) > (int) apply_filters( 'tz_engine_admin_appearance_import_max_bytes', 1048576 ) ) {
            $this->redirect_with_arg( 'import_too_large' );
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            $this->redirect_with_arg( 'import_invalid_json' );
        }

        $payload = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;
        if ( ! is_array( $payload ) ) {
            $this->redirect_with_arg( 'import_invalid_json' );
        }

        $this->store->replace_all( $payload );
        $this->redirect_with_arg( 'imported' );
    }

    /**
     * @return void
     */
    public function handle_post() {
        if ( ! isset( $_POST['tz_engine_admin_appearance_nonce'] ) ) {
            return;
        }
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }
        if ( ! $this->settings->is_module_enabled( 'module_admin_appearance' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change admin appearance.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tz_engine_admin_appearance_nonce'] ) ), 'tz_engine_admin_appearance_save' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'techzu-engine' ), '', array( 'response' => 403 ) );
        }

        if ( ! empty( $_POST['tz_engine_admin_appearance_reset'] ) ) {
            $this->store->reset();
            $this->redirect_with_arg( 'reset' );
        }

        $rows = isset( $_POST['tz_menu_rows'] ) && is_array( $_POST['tz_menu_rows'] ) ? wp_unslash( $_POST['tz_menu_rows'] ) : array();

        $hidden_hooks = array();
        if ( ! empty( $_POST['tz_menu_hidden_hooks'] ) && is_array( $_POST['tz_menu_hidden_hooks'] ) ) {
            $hidden_hooks = array_map( 'sanitize_text_field', wp_unslash( $_POST['tz_menu_hidden_hooks'] ) );
        }

        $menu_order         = array();
        $menu_overrides     = array();
        $custom_separators  = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $hook = isset( $row['hook'] ) ? sanitize_text_field( $row['hook'] ) : '';
            if ( '' === $hook ) {
                continue;
            }
            $menu_order[] = $hook;
            if ( preg_match( '/^tz-sep-[a-zA-Z0-9_-]+$/', $hook ) ) {
                $custom_separators[] = $hook;
                continue;
            }
            $title         = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
            $default_label = isset( $row['default_title'] ) ? sanitize_text_field( $row['default_title'] ) : '';
            $hidden        = in_array( $hook, $hidden_hooks, true );
            if ( 'techzu-engine-settings' === $hook ) {
                $hidden = false;
            }
            $menu_overrides[ $hook ] = array(
                'title'          => $title,
                'hidden'         => $hidden,
                'default_label'  => $default_label,
            );
        }

        $plugin_names = array();
        if ( ! empty( $_POST['tz_plugin_names'] ) && is_array( $_POST['tz_plugin_names'] ) ) {
            foreach ( wp_unslash( $_POST['tz_plugin_names'] ) as $file => $name ) {
                $file = plugin_basename( sanitize_text_field( (string) $file ) );
                $name = sanitize_text_field( (string) $name );
                if ( '' !== $file && '' !== $name ) {
                    $plugin_names[ $file ] = $name;
                }
            }
        }

        $prev           = $this->store->get_all();
        $submenu_layout = isset( $prev['submenu_layout'] ) && is_array( $prev['submenu_layout'] ) ? $prev['submenu_layout'] : array();

        $submenu_layout_enabled = ! empty( $_POST['tz_submenu_layout_enabled'] );

        if ( ! empty( $_POST['tz_engine_clear_submenu_layout'] ) ) {
            $submenu_layout         = array();
            $submenu_layout_enabled = false;
        } elseif ( ! $submenu_layout_enabled ) {
            $submenu_layout = array();
        } elseif ( isset( $_POST['tz_submenu_layout_json'] ) ) {
            $raw = wp_unslash( $_POST['tz_submenu_layout_json'] );
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $submenu_layout = array();
                foreach ( $decoded as $parent => $children ) {
                    $parent = Admin_Appearance_Store::sanitize_menu_slug( (string) $parent );
                    if ( '' === $parent ) {
                        continue;
                    }
                    if ( ! is_array( $children ) ) {
                        continue;
                    }
                    $submenu_layout[ $parent ] = array_map(
                        array( Admin_Appearance_Store::class, 'sanitize_menu_slug' ),
                        $children
                    );
                }
            }
        }

        $custom_top_menus = $this->parse_custom_top_menus_from_post();

        $this->store->save(
            array(
                'menu_order'               => $menu_order,
                'menu_overrides'           => $menu_overrides,
                'plugin_names'             => $plugin_names,
                'submenu_layout'           => $submenu_layout,
                'submenu_layout_enabled'   => $submenu_layout_enabled,
                'custom_top_menus'         => $custom_top_menus,
                'custom_separators'        => $custom_separators,
            )
        );

        $this->redirect_with_arg( 'saved' );
    }

    /**
     * @return array<int, array{title: string, slug: string, icon: string}>
     */
    protected function parse_custom_top_menus_from_post() {
        if ( empty( $_POST['tz_custom_menus'] ) || ! is_array( $_POST['tz_custom_menus'] ) ) {
            return array();
        }
        $out = array();
        foreach ( wp_unslash( $_POST['tz_custom_menus'] ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $out[] = array(
                'title' => isset( $row['title'] ) ? (string) $row['title'] : '',
                'slug'  => isset( $row['slug'] ) ? (string) $row['slug'] : '',
                'icon'  => isset( $row['icon'] ) ? (string) $row['icon'] : '',
            );
        }
        return $out;
    }

    /**
     * @param string $flag Flag.
     * @return void
     */
    protected function redirect_with_arg( $flag ) {
        $url = admin_url( 'admin.php?page=' . self::SLUG );
        $url = add_query_arg( 'tz_admin_appearance', $flag, $url );
        if ( ! empty( $_POST['tz_ap_active_tab'] ) ) {
            $tab = sanitize_key( wp_unslash( $_POST['tz_ap_active_tab'] ) );
            if ( in_array( $tab, array( 'export', 'menu', 'plugins' ), true ) ) {
                $url = add_query_arg( 'tz_ap_tab', $tab, $url );
            }
        }
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * @return array<int, array{hook: string, default_title: string, title: string, hidden: bool, is_separator?: bool, missing_from_sidebar?: bool}>
     */
    protected function get_menu_rows_for_ui() {
        global $menu;

        $saved         = $this->store->get_all();
        $live_by_hook  = array();

        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                if ( empty( $item[2] ) ) {
                    continue;
                }
                $hook         = (string) $item[2];
                $is_sep_class = ! empty( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' );
                if ( $is_sep_class && 0 === strpos( $hook, 'tz-sep-' ) ) {
                    $live_by_hook[ $hook ] = array(
                        'hook'                   => $hook,
                        'default_title'          => __( 'Custom separator', 'techzu-engine' ),
                        'title'                  => '',
                        'hidden'                 => false,
                        'is_separator'           => true,
                        'missing_from_sidebar'   => false,
                    );
                    continue;
                }
                if ( $is_sep_class ) {
                    continue;
                }
                $live_by_hook[ $hook ] = $this->menu_live_item_to_row( $item, $saved );
            }
        }

        $stored_seps = isset( $saved['custom_separators'] ) && is_array( $saved['custom_separators'] ) ? $saved['custom_separators'] : array();
        foreach ( $stored_seps as $slug ) {
            $slug = sanitize_text_field( (string) $slug );
            if ( ! preg_match( '/^tz-sep-[a-zA-Z0-9_-]+$/', $slug ) || isset( $live_by_hook[ $slug ] ) ) {
                continue;
            }
            $live_by_hook[ $slug ] = array(
                'hook'                   => $slug,
                'default_title'          => __( 'Custom separator', 'techzu-engine' ),
                'title'                  => '',
                'hidden'                 => false,
                'is_separator'           => true,
                'missing_from_sidebar'   => false,
            );
        }

        $need_synthetic = array();
        if ( ! empty( $saved['menu_order'] ) && is_array( $saved['menu_order'] ) ) {
            foreach ( $saved['menu_order'] as $hook ) {
                $hook = sanitize_text_field( (string) $hook );
                if ( '' === $hook || isset( $live_by_hook[ $hook ] ) ) {
                    continue;
                }
                $need_synthetic[ $hook ] = true;
            }
        }
        if ( ! empty( $saved['menu_overrides'] ) && is_array( $saved['menu_overrides'] ) ) {
            foreach ( array_keys( $saved['menu_overrides'] ) as $hook ) {
                $hook = sanitize_text_field( (string) $hook );
                if ( '' === $hook || isset( $live_by_hook[ $hook ] ) ) {
                    continue;
                }
                $need_synthetic[ $hook ] = true;
            }
        }

        foreach ( array_keys( $need_synthetic ) as $hook ) {
            if ( preg_match( '/^tz-sep-[a-zA-Z0-9_-]+$/', $hook ) ) {
                $live_by_hook[ $hook ] = array(
                    'hook'                   => $hook,
                    'default_title'          => __( 'Custom separator', 'techzu-engine' ),
                    'title'                  => '',
                    'hidden'                 => false,
                    'is_separator'           => true,
                    'missing_from_sidebar'   => false,
                );
                continue;
            }
            $live_by_hook[ $hook ] = $this->build_synthetic_menu_row( $hook, $saved );
        }

        $rows = array();
        if ( ! empty( $saved['menu_order'] ) && is_array( $saved['menu_order'] ) ) {
            foreach ( $saved['menu_order'] as $hook ) {
                $hook = sanitize_text_field( (string) $hook );
                if ( '' === $hook || ! isset( $live_by_hook[ $hook ] ) ) {
                    continue;
                }
                $rows[] = $live_by_hook[ $hook ];
                unset( $live_by_hook[ $hook ] );
            }
        }
        foreach ( $live_by_hook as $r ) {
            $rows[] = $r;
        }

        return $rows;
    }

    /**
     * @param array<int, string> $item Menu item from global $menu.
     * @param array<string, mixed> $saved Store snapshot.
     * @return array{hook: string, default_title: string, title: string, hidden: bool, is_separator: bool, missing_from_sidebar: bool}
     */
    protected function menu_live_item_to_row( array $item, array $saved ) {
        $hook = (string) $item[2];
        $def  = wp_strip_all_tags( wp_specialchars_decode( (string) $item[0], ENT_QUOTES ) );
        $rule = isset( $saved['menu_overrides'][ $hook ] ) ? $saved['menu_overrides'][ $hook ] : array();
        return array(
            'hook'                   => $hook,
            'default_title'          => $def,
            'title'                  => isset( $rule['title'] ) ? (string) $rule['title'] : '',
            'hidden'                 => ! empty( $rule['hidden'] ),
            'is_separator'           => false,
            'missing_from_sidebar'   => false,
        );
    }

    /**
     * Row for a menu hook that is not present in the filtered global $menu (e.g. hidden from sidebar).
     *
     * @param string $hook Menu hook / file.
     * @param array<string, mixed> $saved Store snapshot.
     * @return array{hook: string, default_title: string, title: string, hidden: bool, is_separator: bool, missing_from_sidebar: bool}
     */
    protected function build_synthetic_menu_row( $hook, array $saved ) {
        $hook = sanitize_text_field( (string) $hook );
        $rule = isset( $saved['menu_overrides'][ $hook ] ) ? $saved['menu_overrides'][ $hook ] : array();
        $stored = isset( $rule['default_label'] ) ? (string) $rule['default_label'] : '';
        $title  = isset( $rule['title'] ) ? (string) $rule['title'] : '';
        $label  = '' !== $stored ? $stored : ( '' !== $title ? $title : $hook );
        return array(
            'hook'                   => $hook,
            'default_title'          => $label,
            'title'                  => $title,
            'hidden'                 => ! empty( $rule['hidden'] ),
            'is_separator'           => false,
            'missing_from_sidebar'   => true,
        );
    }

    /**
     * @return array<int, array{parent: string, parent_label: string, rows: array<int, array{child: string, label: string}>}>
     */
    protected function get_submenu_board_columns() {
        $saved  = $this->store->get_all();
        $layout = isset( $saved['submenu_layout'] ) && is_array( $saved['submenu_layout'] ) ? $saved['submenu_layout'] : array();

        return $this->build_submenu_board_columns( $layout );
    }

    /**
     * Build submenu board columns using a specific saved layout (for UI) against current globals.
     *
     * @param array<string, string[]> $layout Parent slug => ordered child slugs.
     * @return array<int, array{parent: string, parent_label: string, rows: array<int, array{child: string, label: string}>}>
     */
    protected function build_submenu_board_columns( array $layout ) {
        global $submenu, $menu;

        $saved = $this->store->get_all();

        $custom_labels = array();
        if ( ! empty( $saved['custom_top_menus'] ) && is_array( $saved['custom_top_menus'] ) ) {
            foreach ( $saved['custom_top_menus'] as $cm ) {
                if ( empty( $cm['slug'] ) ) {
                    continue;
                }
                $custom_labels[ (string) $cm['slug'] ] = isset( $cm['title'] ) ? (string) $cm['title'] : '';
            }
        }

        $parent_labels = array();
        if ( is_array( $menu ) ) {
            foreach ( $menu as $m ) {
                if ( ! empty( $m[2] ) ) {
                    $parent_labels[ $m[2] ] = wp_strip_all_tags( wp_specialchars_decode( (string) $m[0], ENT_QUOTES ) );
                }
            }
        }

        $parents = array();
        if ( is_array( $submenu ) ) {
            foreach ( array_keys( $submenu ) as $p ) {
                $parents[ (string) $p ] = true;
            }
        }
        foreach ( array_keys( $layout ) as $p ) {
            $parents[ (string) $p ] = true;
        }
        foreach ( array_keys( $custom_labels ) as $p ) {
            $parents[ (string) $p ] = true;
        }

        $columns = array();
        foreach ( array_keys( $parents ) as $parent ) {
            $items = ( is_array( $submenu ) && isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) ? $submenu[ $parent ] : array();

            $by_child = array();
            foreach ( $items as $it ) {
                if ( ! empty( $it[2] ) ) {
                    $by_child[ (string) $it[2] ] = $it;
                }
            }

            $order  = isset( $layout[ $parent ] ) && is_array( $layout[ $parent ] ) ? $layout[ $parent ] : array_keys( $by_child );
            $merged = array();
            foreach ( $order as $ch ) {
                if ( isset( $by_child[ $ch ] ) ) {
                    $merged[ $ch ] = $by_child[ $ch ];
                }
            }
            foreach ( $by_child as $ch => $it ) {
                if ( ! isset( $merged[ $ch ] ) ) {
                    $merged[ $ch ] = $it;
                }
            }

            $rows = array();
            foreach ( $merged as $ch => $it ) {
                $rows[] = array(
                    'child' => (string) $ch,
                    'label' => wp_strip_all_tags( wp_specialchars_decode( (string) $it[0], ENT_QUOTES ) ),
                );
            }

            $label = isset( $parent_labels[ $parent ] ) ? $parent_labels[ $parent ] : '';
            if ( '' === $label && isset( $custom_labels[ $parent ] ) && '' !== $custom_labels[ $parent ] ) {
                $label = $custom_labels[ $parent ];
            }
            if ( '' === $label ) {
                $label = (string) $parent;
            }

            $columns[] = array(
                'parent'       => (string) $parent,
                'parent_label' => $label,
                'rows'         => $rows,
            );
        }

        usort(
            $columns,
            static function ( $a, $b ) {
                return strcasecmp( $a['parent_label'], $b['parent_label'] );
            }
        );

        return $columns;
    }

    /**
     * Order `$current` slugs by registration order in `$native_order`, then append unknown slugs.
     *
     * @param string[] $current_slugs Child slugs currently under this parent (e.g. global $submenu).
     * @param string[] $native_order  Full native registration order for this parent.
     * @return string[]
     */
    protected function order_child_slugs_like_native( array $current_slugs, array $native_order ) {
        $wanted = array();
        foreach ( $current_slugs as $slug ) {
            $wanted[ (string) $slug ] = true;
        }
        $out = array();
        foreach ( $native_order as $slug ) {
            $slug = (string) $slug;
            if ( isset( $wanted[ $slug ] ) ) {
                $out[] = $slug;
                unset( $wanted[ $slug ] );
            }
        }
        foreach ( $current_slugs as $slug ) {
            $slug = (string) $slug;
            if ( isset( $wanted[ $slug ] ) ) {
                $out[] = $slug;
                unset( $wanted[ $slug ] );
            }
        }
        return $out;
    }

    /**
     * Target submenu export when no custom layout is stored: same shape as the JS board, keyed like {@see build_submenu_board_columns()}.
     *
     * @return array<string, string[]>
     */
    protected function get_canonical_submenu_layout_for_compare() {
        global $submenu;

        $native_orders = apply_filters( 'tz_engine_admin_appearance_native_submenu_orders', array() );
        if ( ! is_array( $native_orders ) ) {
            return array();
        }

        $saved        = $this->store->get_all();
        $layout_union = isset( $saved['submenu_layout'] ) && is_array( $saved['submenu_layout'] ) ? $saved['submenu_layout'] : array();
        $columns      = $this->build_submenu_board_columns( array() );
        $payload      = array();

        foreach ( $columns as $col ) {
            $parent       = $col['parent'];
            $current_list = array();
            if ( is_array( $submenu ) && isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
                foreach ( $submenu[ $parent ] as $it ) {
                    if ( ! empty( $it[2] ) ) {
                        $current_list[] = (string) $it[2];
                    }
                }
            }
            $nat                   = isset( $native_orders[ $parent ] ) && is_array( $native_orders[ $parent ] ) ? $native_orders[ $parent ] : array();
            $payload[ $parent ]    = $this->order_child_slugs_like_native( $current_list, $nat );
        }

        foreach ( array_keys( $layout_union ) as $p ) {
            $p = (string) $p;
            if ( array_key_exists( $p, $payload ) ) {
                continue;
            }
            $current_list       = array();
            if ( is_array( $submenu ) && isset( $submenu[ $p ] ) && is_array( $submenu[ $p ] ) ) {
                foreach ( $submenu[ $p ] as $it ) {
                    if ( ! empty( $it[2] ) ) {
                        $current_list[] = (string) $it[2];
                    }
                }
            }
            $nat                = isset( $native_orders[ $p ] ) && is_array( $native_orders[ $p ] ) ? $native_orders[ $p ] : array();
            $payload[ $p ]      = $this->order_child_slugs_like_native( $current_list, $nat );
        }

        return $payload;
    }

    /**
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $menu_rows   = $this->get_menu_rows_for_ui();
        $sub_columns = $this->get_submenu_board_columns();
        $plugins     = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $saved       = $this->store->get_all();
        $settings    = admin_url( 'admin.php?page=' . Settings_Page::MENU_SLUG );
        $submenu_layout_enabled = ! empty( $saved['submenu_layout_enabled'] );
        $sub_json               = wp_json_encode(
            ( $submenu_layout_enabled && ! empty( $saved['submenu_layout'] ) && is_array( $saved['submenu_layout'] ) )
                ? $saved['submenu_layout']
                : array()
        );
        $custom_rows = isset( $saved['custom_top_menus'] ) && is_array( $saved['custom_top_menus'] ) ? $saved['custom_top_menus'] : array();
        $export_url  = wp_nonce_url(
            add_query_arg( 'tz_export_appearance', '1', admin_url( 'admin.php?page=' . self::SLUG ) ),
            'tz_engine_export_appearance'
        );

        $allowed_tabs = array( 'export', 'menu', 'plugins' );
        $current_tab  = isset( $_GET['tz_ap_tab'] ) ? sanitize_key( wp_unslash( $_GET['tz_ap_tab'] ) ) : 'menu';
        if ( ! in_array( $current_tab, $allowed_tabs, true ) ) {
            $current_tab = 'menu';
        }
        $form_tab_value = in_array( $current_tab, array( 'menu', 'plugins' ), true ) ? $current_tab : 'menu';

        ?>
        <div class="wrap tz-engine-settings tz-engine-admin-appearance" data-tz-ap-default-tab="<?php echo esc_attr( $current_tab ); ?>">
            <div class="tz-engine-settings__hero">
                <h1 class="tz-engine-settings__title"><?php esc_html_e( 'Admin appearance', 'techzu-engine' ); ?></h1>
                <p class="tz-engine-settings__lead">
                    <?php esc_html_e( 'Rename and reorder top-level admin menu items, drag submenu items between parents or reorder them, hide entries you do not need, and override plugin names on the Plugins screen. Changes apply to all administrators.', 'techzu-engine' ); ?>
                </p>
                <p class="description">
                    <a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( '← Engine settings', 'techzu-engine' ); ?></a>
                </p>
            </div>

            <?php
            $ap_flag = isset( $_GET['tz_admin_appearance'] ) ? sanitize_text_field( wp_unslash( $_GET['tz_admin_appearance'] ) ) : '';
            if ( 'saved' === $ap_flag ) :
                ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Appearance settings saved.', 'techzu-engine' ); ?></p></div>
            <?php elseif ( 'reset' === $ap_flag ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Appearance settings were reset to WordPress defaults.', 'techzu-engine' ); ?></p></div>
            <?php elseif ( 'imported' === $ap_flag ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Appearance settings were imported.', 'techzu-engine' ); ?></p></div>
            <?php elseif ( 'import_invalid_json' === $ap_flag ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed: the file or pasted JSON could not be read as valid data.', 'techzu-engine' ); ?></p></div>
            <?php elseif ( 'import_bad_nonce' === $ap_flag ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed: security check expired. Try again.', 'techzu-engine' ); ?></p></div>
            <?php elseif ( 'import_too_large' === $ap_flag ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed: file or pasted JSON is too large.', 'techzu-engine' ); ?></p></div>
            <?php elseif ( 'import_bad_type' === $ap_flag ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed: upload a .json file.', 'techzu-engine' ); ?></p></div>
            <?php endif; ?>

            <div class="tz-engine-settings__card tz-ap-tabs" data-tz-ap-tabs="1">
                <div class="tz-ap-tabs__head">
                    <nav class="tz-ap-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Admin appearance sections', 'techzu-engine' ); ?>">
                        <button
                            type="button"
                            role="tab"
                            class="tz-ap-tabs__btn<?php echo 'export' === $current_tab ? ' is-active' : ''; ?>"
                            id="tz-ap-tab-export"
                            data-tz-ap-tab="export"
                            aria-selected="<?php echo 'export' === $current_tab ? 'true' : 'false'; ?>"
                            aria-controls="tz-ap-panel-export"
                            tabindex="<?php echo 'export' === $current_tab ? '0' : '-1'; ?>"
                        >
                            <span class="tz-ap-tabs__btn-icon dashicons dashicons-download" aria-hidden="true"></span>
                            <span class="tz-ap-tabs__btn-text"><?php esc_html_e( 'Export & import', 'techzu-engine' ); ?></span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="tz-ap-tabs__btn<?php echo 'menu' === $current_tab ? ' is-active' : ''; ?>"
                            id="tz-ap-tab-menu"
                            data-tz-ap-tab="menu"
                            aria-selected="<?php echo 'menu' === $current_tab ? 'true' : 'false'; ?>"
                            aria-controls="tz-ap-panel-menu"
                            tabindex="<?php echo 'menu' === $current_tab ? '0' : '-1'; ?>"
                        >
                            <span class="tz-ap-tabs__btn-icon dashicons dashicons-menu" aria-hidden="true"></span>
                            <span class="tz-ap-tabs__btn-text"><?php esc_html_e( 'Admin menu', 'techzu-engine' ); ?></span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="tz-ap-tabs__btn<?php echo 'plugins' === $current_tab ? ' is-active' : ''; ?>"
                            id="tz-ap-tab-plugins"
                            data-tz-ap-tab="plugins"
                            aria-selected="<?php echo 'plugins' === $current_tab ? 'true' : 'false'; ?>"
                            aria-controls="tz-ap-panel-plugins"
                            tabindex="<?php echo 'plugins' === $current_tab ? '0' : '-1'; ?>"
                        >
                            <span class="tz-ap-tabs__btn-icon dashicons dashicons-admin-plugins" aria-hidden="true"></span>
                            <span class="tz-ap-tabs__btn-text"><?php esc_html_e( 'Plugin names', 'techzu-engine' ); ?></span>
                        </button>
                    </nav>
                </div>

                <div class="tz-ap-tabs__body">
                    <section
                        class="tz-ap-tabs__panel tz-ap-tabs__panel--export"
                        id="tz-ap-panel-export"
                        role="tabpanel"
                        aria-labelledby="tz-ap-tab-export"
                        data-tz-ap-panel="export"
                        tabindex="0"
                        <?php echo ( 'export' !== $current_tab ) ? 'hidden' : ''; ?>
                    >
                        <div class="tz-ap-tabs__panel-inner tz-engine-admin-appearance__export-card">
                            <h2><?php esc_html_e( 'Export & import', 'techzu-engine' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Download a JSON file of this page’s settings (menu order, labels, optional submenu layout when enabled, custom menus, menu separators, plugin display names) and import it on another site with Techzu Engine and Admin appearance enabled.', 'techzu-engine' ); ?></p>
                            <p>
                                <a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Download JSON', 'techzu-engine' ); ?></a>
                            </p>
                            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="tz-engine-admin-appearance__import-form">
                                <?php wp_nonce_field( 'tz_engine_admin_appearance_import', 'tz_engine_admin_appearance_import_nonce' ); ?>
                                <input type="hidden" name="tz_ap_active_tab" value="export" />
                                <p>
                                    <label for="tz-import-file"><?php esc_html_e( 'JSON file', 'techzu-engine' ); ?></label><br />
                                    <input type="file" name="tz_import_file" id="tz-import-file" accept=".json,application/json" />
                                </p>
                                <p>
                                    <label for="tz-import-json"><?php esc_html_e( 'Or paste JSON', 'techzu-engine' ); ?></label><br />
                                    <textarea class="large-text code" rows="6" name="tz_import_json" id="tz-import-json" placeholder="{ &quot;version&quot;: 1, &quot;data&quot;: { ... } }"></textarea>
                                </p>
                                <p>
                                    <button type="submit" name="tz_engine_import_appearance_submit" value="1" class="button button-primary"><?php esc_html_e( 'Import', 'techzu-engine' ); ?></button>
                                </p>
                            </form>
                        </div>
                    </section>

                    <form method="post" class="tz-engine-admin-appearance__form tz-ap-tabs__form">
                        <input type="hidden" name="tz_ap_active_tab" id="tz-ap-active-tab" value="<?php echo esc_attr( $form_tab_value ); ?>" />
                        <?php wp_nonce_field( 'tz_engine_admin_appearance_save', 'tz_engine_admin_appearance_nonce' ); ?>
                        <section
                            class="tz-ap-tabs__panel tz-ap-tabs__panel--menu"
                            id="tz-ap-panel-menu"
                            role="tabpanel"
                            aria-labelledby="tz-ap-tab-menu"
                            data-tz-ap-panel="menu"
                            tabindex="0"
                            <?php echo ( 'menu' !== $current_tab ) ? 'hidden' : ''; ?>
                        >
                            <div class="tz-ap-tabs__panel-inner">
                                <h2><?php esc_html_e( 'Admin menu', 'techzu-engine' ); ?></h2>
                                <p class="description"><?php esc_html_e( 'Drag rows to change order. Leave a title blank to keep the default label. Hiding an item removes it from the sidebar for everyone with a role that could see it; hidden entries stay in this list so you can turn them back on. Use “Add separator” to insert horizontal rules and drag them like any other row.', 'techzu-engine' ); ?></p>
                                <p class="description"><strong><?php esc_html_e( 'Note:', 'techzu-engine' ); ?></strong> <?php esc_html_e( 'The Techzu Engine menu cannot be hidden from here so you always retain access.', 'techzu-engine' ); ?></p>

                                <h3 class="tz-engine-admin-appearance__subhead"><?php esc_html_e( 'Custom top-level menus', 'techzu-engine' ); ?></h3>
                                <p class="description"><?php esc_html_e( 'Create extra sidebar parents (empty shells). After saving, drag submenu items into their column below. Slugs must stay prefixed with tz-custom- when you set them manually; otherwise a slug is generated from the title.', 'techzu-engine' ); ?></p>

                                <table class="widefat striped tz-engine-custom-menus" id="tz-engine-custom-menus">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'techzu-engine' ); ?></th>
                                <th><?php esc_html_e( 'Slug (optional)', 'techzu-engine' ); ?></th>
                                <th><?php esc_html_e( 'Icon class', 'techzu-engine' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tz-engine-custom-menus-body">
                            <?php foreach ( $custom_rows as $i => $cm ) : ?>
                                <tr class="tz-engine-custom-menu-row">
                                    <td>
                                        <input type="text" class="regular-text" name="tz_custom_menus[<?php echo esc_attr( (string) $i ); ?>][title]" value="<?php echo esc_attr( isset( $cm['title'] ) ? (string) $cm['title'] : '' ); ?>" />
                                    </td>
                                    <td>
                                        <input type="text" class="regular-text code" name="tz_custom_menus[<?php echo esc_attr( (string) $i ); ?>][slug]" value="<?php echo esc_attr( isset( $cm['slug'] ) ? (string) $cm['slug'] : '' ); ?>" placeholder="tz-custom-…" />
                                    </td>
                                    <td>
                                        <input type="text" class="regular-text code" name="tz_custom_menus[<?php echo esc_attr( (string) $i ); ?>][icon]" value="<?php echo esc_attr( isset( $cm['icon'] ) ? (string) $cm['icon'] : '' ); ?>" placeholder="dashicons-admin-generic" />
                                    </td>
                                    <td><button type="button" class="button tz-engine-custom-menu-remove"><?php esc_html_e( 'Remove', 'techzu-engine' ); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="tz-engine-custom-menu-add"><?php esc_html_e( 'Add menu', 'techzu-engine' ); ?></button>
                        <button type="button" class="button" id="tz-engine-menu-separator-add"><?php esc_html_e( 'Add separator', 'techzu-engine' ); ?></button>
                    </p>

                    <ul id="tz-engine-menu-sort" class="tz-engine-menu-sort">
                        <?php foreach ( $menu_rows as $i => $row ) : ?>
                            <?php
                            $is_sep = ! empty( $row['is_separator'] );
                            $fid    = 'tz-menu-title-' . md5( $row['hook'] );
                            ?>
                            <?php if ( $is_sep ) : ?>
                                <li class="tz-engine-menu-sort__item tz-engine-menu-sort__item--separator">
                                    <span class="tz-engine-menu-sort__handle" title="<?php esc_attr_e( 'Drag to reorder', 'techzu-engine' ); ?>" aria-hidden="true">⋮⋮</span>
                                    <div class="tz-engine-menu-sort__main tz-engine-menu-sort__main--separator">
                                        <code class="tz-engine-menu-sort__hook"><?php echo esc_html( $row['hook'] ); ?></code>
                                        <span class="tz-engine-menu-sort__sep-label"><?php echo esc_html( $row['default_title'] ); ?></span>
                                        <input type="hidden" data-tz-field="hook" name="tz_menu_rows[<?php echo esc_attr( (string) $i ); ?>][hook]" value="<?php echo esc_attr( $row['hook'] ); ?>" />
                                    </div>
                                    <button type="button" class="button tz-engine-menu-sort__remove-sep"><?php esc_html_e( 'Remove', 'techzu-engine' ); ?></button>
                                </li>
                            <?php else : ?>
                                <?php
                                $missing = ! empty( $row['missing_from_sidebar'] );
                                $item_classes = array( 'tz-engine-menu-sort__item' );
                                if ( $missing ) {
                                    $item_classes[] = 'tz-engine-menu-sort__item--missing-from-sidebar';
                                }
                                ?>
                                <li class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>">
                                    <span class="tz-engine-menu-sort__handle" title="<?php esc_attr_e( 'Drag to reorder', 'techzu-engine' ); ?>" aria-hidden="true">⋮⋮</span>
                                    <div class="tz-engine-menu-sort__main">
                                        <code class="tz-engine-menu-sort__hook"><?php echo esc_html( $row['hook'] ); ?></code>
                                        <span class="tz-engine-menu-sort__default">
                                            <?php echo esc_html( $row['default_title'] ); ?>
                                            <?php if ( $missing && ! empty( $row['hidden'] ) ) : ?>
                                                <span class="tz-engine-menu-sort__hidden-note"><?php esc_html_e( 'Hidden', 'techzu-engine' ); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <input type="hidden" data-tz-field="hook" name="tz_menu_rows[<?php echo esc_attr( (string) $i ); ?>][hook]" value="<?php echo esc_attr( $row['hook'] ); ?>" />
                                        <input type="hidden" data-tz-field="default_title" name="tz_menu_rows[<?php echo esc_attr( (string) $i ); ?>][default_title]" value="<?php echo esc_attr( $row['default_title'] ); ?>" />
                                        <label class="screen-reader-text" for="<?php echo esc_attr( $fid ); ?>"><?php esc_html_e( 'Custom title', 'techzu-engine' ); ?></label>
                                        <input type="text" data-tz-field="title" class="tz-engine-menu-sort__title regular-text" id="<?php echo esc_attr( $fid ); ?>" name="tz_menu_rows[<?php echo esc_attr( (string) $i ); ?>][title]" value="<?php echo esc_attr( $row['title'] ); ?>" placeholder="<?php echo esc_attr( $row['default_title'] ); ?>" />
                                    </div>
                                    <label class="tz-engine-menu-sort__hide">
                                        <input type="checkbox" data-tz-field="hidden" name="tz_menu_hidden_hooks[]" value="<?php echo esc_attr( $row['hook'] ); ?>" <?php checked( $row['hidden'] ); ?> <?php disabled( 'techzu-engine-settings' === $row['hook'] ); ?> />
                                        <?php esc_html_e( 'Hide', 'techzu-engine' ); ?>
                                    </label>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>

                    <h3 class="tz-engine-admin-appearance__subhead"><?php esc_html_e( 'Submenus (drag between columns)', 'techzu-engine' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Turn this on only if you want to reorder submenu items or move them between top-level parents. When off (default), Techzu Engine does not change submenu order anywhere in wp-admin.', 'techzu-engine' ); ?></p>
                    <p>
                        <label>
                            <input type="checkbox" name="tz_submenu_layout_enabled" value="1" <?php checked( $submenu_layout_enabled ); ?> />
                            <?php esc_html_e( 'Enable submenu customization (drag between columns)', 'techzu-engine' ); ?>
                        </label>
                    </p>

                    <?php if ( $submenu_layout_enabled ) : ?>
                        <p class="description"><?php esc_html_e( 'Each column is a top-level menu. Drag items up or down within a column to reorder, or into another column to move them under that parent. Custom menus appear as columns even when empty so you can drop items into them.', 'techzu-engine' ); ?></p>
                        <p>
                            <button type="submit" name="tz_engine_clear_submenu_layout" value="1" id="tz-engine-clear-submenu-layout-btn" class="button">
                                <?php esc_html_e( 'Restore default submenu order', 'techzu-engine' ); ?>
                            </button>
                        </p>
                        <p class="description"><strong><?php esc_html_e( 'Note:', 'techzu-engine' ); ?></strong> <?php esc_html_e( 'Some plugins assume a fixed parent menu; moving entries can affect highlights or deep links until you move them back.', 'techzu-engine' ); ?></p>

                        <input type="hidden" name="tz_submenu_layout_json" id="tz-submenu-layout-json" value="<?php echo esc_attr( $sub_json ); ?>" />

                        <?php if ( ! empty( $sub_columns ) ) : ?>
                            <div class="tz-submenu-board" id="tz-submenu-board">
                                <?php foreach ( $sub_columns as $col ) : ?>
                                    <div class="tz-submenu-column" data-parent="<?php echo esc_attr( $col['parent'] ); ?>">
                                        <div class="tz-submenu-column__head">
                                            <span class="tz-submenu-column__title"><?php echo esc_html( $col['parent_label'] ); ?></span>
                                            <code class="tz-submenu-column__slug"><?php echo esc_html( $col['parent'] ); ?></code>
                                        </div>
                                        <ul class="tz-submenu-sortable">
                                            <?php foreach ( $col['rows'] as $subrow ) : ?>
                                                <li class="tz-submenu-sort__item" data-child="<?php echo esc_attr( $subrow['child'] ); ?>">
                                                    <span class="tz-submenu-sort__handle" title="<?php esc_attr_e( 'Drag', 'techzu-engine' ); ?>">⋮⋮</span>
                                                    <span class="tz-submenu-sort__label"><?php echo esc_html( $subrow['label'] ); ?></span>
                                                    <code class="tz-submenu-sort__child"><?php echo esc_html( $subrow['child'] ); ?></code>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'No submenu columns yet. Add a custom top-level menu above, save, and reload if needed; or wait until other plugins register menus.', 'techzu-engine' ); ?></p>
                        <?php endif; ?>
                    <?php else : ?>
                        <input type="hidden" name="tz_submenu_layout_json" id="tz-submenu-layout-json" value="{}" />
                        <p class="description"><?php esc_html_e( 'Submenu drag-and-drop is off. WordPress and other plugins control submenu order (e.g. WooCommerce Products). Enable the option above if you need this advanced layout.', 'techzu-engine' ); ?></p>
                    <?php endif; ?>

                                <p class="submit tz-ap-tabs__submit">
                                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save menu & plugins', 'techzu-engine' ); ?></button>
                                    <button type="submit" name="tz_engine_admin_appearance_reset" value="1" class="button" onclick="return window.confirm(<?php echo wp_json_encode( __( 'Reset all appearance customizations?', 'techzu-engine' ) ); ?>);">
                                        <?php esc_html_e( 'Reset all', 'techzu-engine' ); ?>
                                    </button>
                                </p>
                            </div>
                        </section>

                        <section
                            class="tz-ap-tabs__panel tz-ap-tabs__panel--plugins"
                            id="tz-ap-panel-plugins"
                            role="tabpanel"
                            aria-labelledby="tz-ap-tab-plugins"
                            data-tz-ap-panel="plugins"
                            tabindex="0"
                            <?php echo ( 'plugins' !== $current_tab ) ? 'hidden' : ''; ?>
                        >
                            <div class="tz-ap-tabs__panel-inner">
                                <h2><?php esc_html_e( 'Plugin names', 'techzu-engine' ); ?></h2>
                                <p class="description"><?php esc_html_e( 'Optional display names only — this does not deactivate plugins or change folder names. Shown on the Plugins screen.', 'techzu-engine' ); ?></p>
                                <div class="tz-engine-plugin-rename-wrap">
                                    <table class="widefat striped tz-engine-plugin-rename">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e( 'Plugin file', 'techzu-engine' ); ?></th>
                                                <th><?php esc_html_e( 'Original name', 'techzu-engine' ); ?></th>
                                                <th><?php esc_html_e( 'Display name override', 'techzu-engine' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $plugins as $file => $data ) : ?>
                                                <?php
                                                $basename = plugin_basename( $file );
                                                $orig     = isset( $data['Name'] ) ? (string) $data['Name'] : '';
                                                $override = isset( $saved['plugin_names'][ $basename ] ) ? $saved['plugin_names'][ $basename ] : '';
                                                ?>
                                                <tr>
                                                    <td><code><?php echo esc_html( $basename ); ?></code></td>
                                                    <td><?php echo esc_html( $orig ); ?></td>
                                                    <td>
                                                        <input type="text" class="regular-text" name="tz_plugin_names[<?php echo esc_attr( $basename ); ?>]" value="<?php echo esc_attr( $override ); ?>" placeholder="<?php echo esc_attr( $orig ); ?>" />
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="submit tz-ap-tabs__submit">
                                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save menu & plugins', 'techzu-engine' ); ?></button>
                                    <button type="submit" name="tz_engine_admin_appearance_reset" value="1" class="button" onclick="return window.confirm(<?php echo wp_json_encode( __( 'Reset all appearance customizations?', 'techzu-engine' ) ); ?>);">
                                        <?php esc_html_e( 'Reset all', 'techzu-engine' ); ?>
                                    </button>
                                </p>
                            </div>
                        </section>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
