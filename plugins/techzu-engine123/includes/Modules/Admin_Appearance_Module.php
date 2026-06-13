<?php
namespace Techzu\Engine\Modules;

use Techzu\Engine\Admin\Admin_Appearance_Page;
use Techzu\Engine\Admin\Admin_Appearance_Store;
use Techzu\Engine\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Customize wp-admin: top-level menu order, labels, visibility, and plugin list display names.
 */
class Admin_Appearance_Module implements Module_Interface {
    const PROTECTED_MENU_HOOKS = array(
        'techzu-engine-settings',
    );

    /**
     * Snapshot of each parent's submenu child slugs in core registration order (before submenu_layout is applied).
     *
     * @var array<string, string[]>
     */
    protected static $native_submenu_child_orders = array();

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var Admin_Appearance_Store
     */
    protected $store;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
        $this->store    = new Admin_Appearance_Store();
    }

    public function setting_key() {
        return 'module_admin_appearance';
    }

    public function register() {
        if ( is_network_admin() ) {
            return;
        }

        $this->maybe_migrate_submenu_layout_opt_in();

        $page = new Admin_Appearance_Page( $this->store, $this->settings );
        $page->hooks();

        add_action( 'admin_menu', array( $this, 'register_custom_separators' ), 9 );
        add_action( 'admin_menu', array( $this, 'register_custom_top_menus' ), 9 );

        add_filter( 'menu_order', array( $this, 'filter_menu_order' ), 999 );
        add_filter( 'custom_menu_order', array( $this, 'enable_custom_menu_order' ) );
        add_filter( 'add_menu_classes', array( $this, 'filter_menu_items' ), 9999, 1 );
        add_filter( 'all_plugins', array( $this, 'filter_plugin_headers' ), 999 );
        /*
         * Run after other plugins' admin_menu callbacks so the snapshot matches the real registration
         * order (e.g. WooCommerce + taxonomies), then apply saved layout last.
         */
        add_action( 'admin_menu', array( $this, 'capture_native_submenu_orders' ), PHP_INT_MAX - 2 );
        add_action( 'admin_menu', array( $this, 'reorganize_submenus' ), PHP_INT_MAX - 1 );
        add_filter( 'tz_engine_admin_appearance_native_submenu_orders', array( __CLASS__, 'filter_native_submenu_orders' ) );
    }

    /**
     * Older releases saved submenu order and applied it on every load; opt-in was implicit.
     * Clear saved layout and default to off so wp-admin matches WordPress/plugins unless explicitly enabled.
     *
     * @return void
     */
    protected function maybe_migrate_submenu_layout_opt_in() {
        if ( '1' === get_option( 'tz_engine_submenu_opt_in_migrated', '' ) ) {
            return;
        }

        $raw = get_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION, array() );
        if ( ! is_array( $raw ) ) {
            update_option( 'tz_engine_submenu_opt_in_migrated', '1', true );
            return;
        }

        if ( ! array_key_exists( 'submenu_layout_enabled', $raw ) ) {
            $raw['submenu_layout_enabled'] = false;
            $raw['submenu_layout']         = array();
            update_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION, $raw, false );
        }

        update_option( 'tz_engine_submenu_opt_in_migrated', '1', true );
    }

    /**
     * Expose native submenu orders to Admin appearance UI / canonical compare.
     *
     * @param array<string, string[]> $fallback Fallback.
     * @return array<string, string[]>
     */
    public static function filter_native_submenu_orders( $fallback ) {
        if ( ! empty( self::$native_submenu_child_orders ) ) {
            return self::$native_submenu_child_orders;
        }
        return is_array( $fallback ) ? $fallback : array();
    }

    /**
     * Remember submenu structure as registered by WordPress and plugins, before we reorder from saved layout.
     *
     * @return void
     */
    public function capture_native_submenu_orders() {
        if ( ! is_admin() || is_network_admin() ) {
            return;
        }

        global $submenu, $_parent_pages, $_registered_pages;

        self::$native_submenu_child_orders = array();

        if ( ! is_array( $submenu ) ) {
            return;
        }

        foreach ( $submenu as $parent => $items ) {
            if ( ! is_array( $items ) ) {
                continue;
            }
            $children = array();
            foreach ( $items as $item ) {
                if ( empty( $item ) || ! is_array( $item ) || empty( $item[2] ) ) {
                    continue;
                }
                $children[] = (string) $item[2];
            }
            self::$native_submenu_child_orders[ (string) $parent ] = $children;
        }
    }

    /**
     * Register custom menu separators (horizontal rules in the sidebar).
     *
     * @return void
     */
    public function register_custom_separators() {
        if ( ! is_admin() || is_network_admin() ) {
            return;
        }

        $stored = $this->store->get_all()['custom_separators'];
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        /**
         * Extra separator slugs (tz-sep-*) merged with values saved in Admin appearance.
         *
         * @param string[] $stored Slugs from the database.
         */
        $ids = apply_filters( 'tz_engine_admin_menu_separator_ids', $stored );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }

        $slugs = array();
        foreach ( $ids as $slug ) {
            $slug = sanitize_text_field( (string) $slug );
            if ( preg_match( '/^tz-sep-[a-zA-Z0-9_-]+$/', $slug ) ) {
                $slugs[] = $slug;
            }
        }
        $slugs = array_values( array_unique( $slugs ) );
        if ( array() === $slugs ) {
            return;
        }

        global $menu;
        if ( ! is_array( $menu ) ) {
            return;
        }

        $used = array();
        foreach ( $menu as $item ) {
            if ( ! empty( $item[2] ) ) {
                $used[ (string) $item[2] ] = true;
            }
        }

        $max_key = 0;
        foreach ( array_keys( $menu ) as $k ) {
            if ( is_numeric( $k ) && (int) $k > $max_key ) {
                $max_key = (int) $k;
            }
        }
        $next = $max_key + 1;

        foreach ( $slugs as $slug ) {
            if ( isset( $used[ $slug ] ) ) {
                continue;
            }
            $menu[ $next ] = array( '', 'read', $slug, '', 'wp-menu-separator' );
            $used[ $slug ] = true;
            ++$next;
        }
    }

    /**
     * Register empty top-level menus so submenu items can be grouped under them.
     *
     * @return void
     */
    public function register_custom_top_menus() {
        $menus = $this->store->get_all()['custom_top_menus'];
        if ( empty( $menus ) || ! is_array( $menus ) ) {
            return;
        }

        $pos = 66;
        foreach ( $menus as $cm ) {
            if ( empty( $cm['slug'] ) || empty( $cm['title'] ) ) {
                continue;
            }
            $slug  = (string) $cm['slug'];
            $title = (string) $cm['title'];
            $icon  = ! empty( $cm['icon'] ) ? (string) $cm['icon'] : 'dashicons-admin-generic';

            add_menu_page(
                $title,
                $title,
                'manage_options',
                $slug,
                $this->get_custom_menu_callback( $title ),
                $icon,
                $pos
            );
            remove_submenu_page( $slug, $slug );
            ++$pos;
        }
    }

    /**
     * @param string $title Menu title.
     * @return callable
     */
    protected function get_custom_menu_callback( $title ) {
        return static function () use ( $title ) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html( $title ) . '</h1>';
            echo '<p>' . esc_html__( 'This is a custom admin menu created by Techzu Engine. Add screens here from Techzu → Appearance by dragging submenu items into this menu’s column.', 'techzu-engine' ) . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Appearance_Page::SLUG ) ) . '">' . esc_html__( 'Open Admin appearance', 'techzu-engine' ) . '</a></p>';
            echo '</div>';
        };
    }

    /**
     * Reorder and reparent submenu items based on saved layout (after all admin_menu registrations).
     *
     * @return void
     */
    public function reorganize_submenus() {
        if ( ! is_admin() || is_network_admin() ) {
            return;
        }

        $all = $this->store->get_all();
        if ( empty( $all['submenu_layout_enabled'] ) ) {
            return;
        }

        $layout = $all['submenu_layout'];
        if ( empty( $layout ) || ! is_array( $layout ) ) {
            return;
        }

        if ( $this->submenu_layout_already_matches_global( $layout ) ) {
            return;
        }

        global $submenu;
        if ( ! is_array( $submenu ) ) {
            return;
        }

        $pool = array();
        foreach ( $submenu as $parent => $items ) {
            if ( ! is_array( $items ) ) {
                continue;
            }
            foreach ( $items as $item ) {
                if ( empty( $item ) || ! is_array( $item ) || empty( $item[2] ) ) {
                    continue;
                }
                $child = (string) $item[2];
                if ( isset( $pool[ $child ] ) ) {
                    continue;
                }
                $pool[ $child ] = $item;
            }
        }

        $listed = array();
        foreach ( $layout as $children ) {
            if ( ! is_array( $children ) ) {
                continue;
            }
            foreach ( $children as $child ) {
                $child = Admin_Appearance_Store::sanitize_menu_slug( (string) $child );
                if ( '' !== $child && isset( $pool[ $child ] ) ) {
                    $listed[ $child ] = true;
                }
            }
        }

        if ( empty( $listed ) ) {
            return;
        }

        foreach ( $submenu as $parent => $items ) {
            if ( ! is_array( $items ) ) {
                continue;
            }
            foreach ( $items as $index => $item ) {
                if ( empty( $item[2] ) ) {
                    continue;
                }
                $child = (string) $item[2];
                if ( isset( $listed[ $child ] ) ) {
                    unset( $submenu[ $parent ][ $index ] );
                }
            }
            if ( isset( $submenu[ $parent ] ) ) {
                $submenu[ $parent ] = array_values( array_filter( $submenu[ $parent ] ) );
                if ( array() === $submenu[ $parent ] ) {
                    unset( $submenu[ $parent ] );
                }
            }
        }

        $placed = array();
        foreach ( $layout as $parent => $children ) {
            if ( ! is_array( $children ) || empty( $children ) ) {
                continue;
            }
            $parent = Admin_Appearance_Store::sanitize_menu_slug( (string) $parent );
            if ( '' === $parent ) {
                continue;
            }
            if ( ! isset( $submenu[ $parent ] ) ) {
                $submenu[ $parent ] = array();
            }
            foreach ( $children as $child ) {
                $child = Admin_Appearance_Store::sanitize_menu_slug( (string) $child );
                if ( '' === $child || ! isset( $pool[ $child ] ) || isset( $placed[ $child ] ) ) {
                    continue;
                }
                $submenu[ $parent ][] = $pool[ $child ];
                $placed[ $child ]     = true;

                /*
                 * Keep WordPress plugin-page access maps in sync with the visual reparenting.
                 * Without this, moved pages can fail capability/access checks (often showing "0")
                 * when the original parent is hidden or no longer contains that submenu.
                 */
                $old_parent = '';
                if ( is_array( $_parent_pages ) && isset( $_parent_pages[ $child ] ) ) {
                    $old_parent = (string) $_parent_pages[ $child ];
                }

                if ( ! is_array( $_parent_pages ) ) {
                    $_parent_pages = array();
                }
                $_parent_pages[ $child ] = $parent;

                if ( function_exists( 'get_plugin_page_hookname' ) && is_array( $_registered_pages ) ) {
                    $new_hook = get_plugin_page_hookname( $child, $parent );
                    if ( '' !== $old_parent ) {
                        $old_hook = get_plugin_page_hookname( $child, $old_parent );
                        if ( $new_hook && $old_hook && isset( $_registered_pages[ $old_hook ] ) && ! isset( $_registered_pages[ $new_hook ] ) ) {
                            $_registered_pages[ $new_hook ] = $_registered_pages[ $old_hook ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Skip strip/re-add when stored layout matches global $submenu as registered this request (nothing to fix).
     *
     * @param array<string, string[]> $layout Stored layout.
     * @return bool
     */
    protected function submenu_layout_already_matches_global( array $layout ) {
        global $submenu;

        if ( ! is_array( $submenu ) ) {
            return false;
        }

        foreach ( $layout as $parent => $children ) {
            if ( ! is_array( $children ) ) {
                continue;
            }
            $parent = Admin_Appearance_Store::sanitize_menu_slug( (string) $parent );
            $want   = array();
            foreach ( $children as $ch ) {
                $ch = Admin_Appearance_Store::sanitize_menu_slug( (string) $ch );
                if ( '' !== $ch ) {
                    $want[] = $ch;
                }
            }
            $have = array();
            if ( isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
                foreach ( $submenu[ $parent ] as $item ) {
                    if ( ! empty( $item[2] ) ) {
                        $have[] = Admin_Appearance_Store::sanitize_menu_slug( (string) $item[2] );
                    }
                }
            }
            if ( $want !== $have ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param bool $enabled Whether another component already enabled custom order.
     * @return bool
     */
    public function enable_custom_menu_order( $enabled ) {
        $all     = $this->store->get_all();
        $order   = $all['menu_order'];
        $sep_ids = isset( $all['custom_separators'] ) && is_array( $all['custom_separators'] ) ? $all['custom_separators'] : array();
        if ( ! empty( $order ) || ! empty( $sep_ids ) ) {
            return true;
        }
        return (bool) $enabled;
    }

    /**
     * @param array<int, string> $order Default order.
     * @return array<int, string>
     */
    public function filter_menu_order( $order ) {
        $saved = $this->store->get_all()['menu_order'];
        if ( empty( $saved ) || ! is_array( $order ) ) {
            return $order;
        }
        $merged = array();
        foreach ( $saved as $hook ) {
            if ( in_array( $hook, $order, true ) ) {
                $merged[] = $hook;
            }
        }
        foreach ( $order as $hook ) {
            if ( ! in_array( $hook, $merged, true ) ) {
                $merged[] = $hook;
            }
        }
        return $merged;
    }

    /**
     * @param array<int, array<int, string>> $menu Menu.
     * @return array<int, array<int, string>>
     */
    public function filter_menu_items( $menu ) {
        if ( ! is_array( $menu ) ) {
            return $menu;
        }

        $overrides = $this->store->get_all()['menu_overrides'];
        if ( empty( $overrides ) ) {
            return $menu;
        }

        foreach ( $menu as $id => $item ) {
            if ( empty( $item[2] ) ) {
                continue;
            }
            $hook = $item[2];
            if ( 0 === strpos( (string) $hook, 'tz-sep-' ) ) {
                continue;
            }
            if ( ! isset( $overrides[ $hook ] ) ) {
                continue;
            }
            $rule = $overrides[ $hook ];
            if ( ! empty( $rule['hidden'] ) && ! $this->is_protected_hook( $hook ) ) {
                unset( $menu[ $id ] );
                continue;
            }
            if ( ! empty( $rule['title'] ) ) {
                $menu[ $id ][0] = $this->merge_menu_badge_html( (string) $item[0], (string) $rule['title'] );
            }
        }

        return array_values( $menu );
    }

    /**
     * @param string $original_html Original menu title HTML.
     * @param string $new_title     Plain new title.
     * @return string
     */
    protected function merge_menu_badge_html( $original_html, $new_title ) {
        $new_title = wp_strip_all_tags( $new_title );
        if ( preg_match( '/^(.*)(\s*<span[\s\S]+)$/i', $original_html, $m ) ) {
            return esc_html( $new_title ) . $m[2];
        }
        return esc_html( $new_title );
    }

    /**
     * @param string $hook Menu file / hook.
     * @return bool
     */
    protected function is_protected_hook( $hook ) {
        return in_array( $hook, self::PROTECTED_MENU_HOOKS, true );
    }

    /**
     * @param array<string, array<string, mixed>> $plugins Plugins.
     * @return array<string, array<string, mixed>>
     */
    public function filter_plugin_headers( $plugins ) {
        if ( ! is_array( $plugins ) ) {
            return $plugins;
        }
        $map = $this->store->get_all()['plugin_names'];
        if ( empty( $map ) ) {
            return $plugins;
        }
        foreach ( $map as $file => $name ) {
            $file = plugin_basename( $file );
            if ( '' === $name || ! isset( $plugins[ $file ] ) ) {
                continue;
            }
            $plugins[ $file ]['Name'] = $name;
        }
        return $plugins;
    }
}
