<?php
namespace Techzu\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persists admin menu / plugin label customization (separate option from core Engine settings).
 */
class Admin_Appearance_Store {
    /**
     * @return array{menu_order: string[], menu_overrides: array<string, array{title?: string, hidden?: bool, default_label?: string}>, plugin_names: array<string, string>, submenu_layout: array<string, string[]>, submenu_layout_enabled: bool, custom_top_menus: array<int, array{slug: string, title: string, icon: string}>, custom_separators: string[]}
     */
    public static function defaults() {
        return array(
            'menu_order'                => array(),
            'menu_overrides'            => array(),
            'plugin_names'              => array(),
            'submenu_layout'            => array(),
            'submenu_layout_enabled'    => false,
            'custom_top_menus'          => array(),
            'custom_separators'         => array(),
        );
    }

    /**
     * Allowed option keys for import / export payloads.
     *
     * @return list<string>
     */
    public static function allowed_keys() {
        return array_keys( self::defaults() );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_all() {
        $raw = get_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return self::defaults();
        }
        return wp_parse_args( $raw, self::defaults() );
    }

    /**
     * @param array<string, mixed> $data Data.
     * @return void
     */
    public function save( array $data ) {
        $clean = wp_parse_args( $data, self::defaults() );

        if ( isset( $clean['menu_order'] ) && is_array( $clean['menu_order'] ) ) {
            $clean['menu_order'] = array_values( array_filter( array_map( 'sanitize_text_field', $clean['menu_order'] ) ) );
        } else {
            $clean['menu_order'] = array();
        }

        $overrides = array();
        if ( ! empty( $clean['menu_overrides'] ) && is_array( $clean['menu_overrides'] ) ) {
            foreach ( $clean['menu_overrides'] as $hook => $rule ) {
                $hook = sanitize_text_field( (string) $hook );
                if ( '' === $hook ) {
                    continue;
                }
                if ( ! is_array( $rule ) ) {
                    continue;
                }
                $overrides[ $hook ] = array(
                    'title'          => isset( $rule['title'] ) ? sanitize_text_field( (string) $rule['title'] ) : '',
                    'hidden'         => ! empty( $rule['hidden'] ),
                    'default_label'  => isset( $rule['default_label'] ) ? sanitize_text_field( (string) $rule['default_label'] ) : '',
                );
            }
        }
        $clean['menu_overrides'] = $overrides;

        $names = array();
        if ( ! empty( $clean['plugin_names'] ) && is_array( $clean['plugin_names'] ) ) {
            foreach ( $clean['plugin_names'] as $file => $name ) {
                $file = plugin_basename( sanitize_text_field( (string) $file ) );
                if ( '' === $file ) {
                    continue;
                }
                $names[ $file ] = sanitize_text_field( (string) $name );
            }
        }
        $clean['plugin_names'] = $names;

        $layout = array();
        if ( ! empty( $clean['submenu_layout'] ) && is_array( $clean['submenu_layout'] ) ) {
            foreach ( $clean['submenu_layout'] as $parent => $children ) {
                $parent = self::sanitize_menu_slug( (string) $parent );
                if ( '' === $parent ) {
                    continue;
                }
                if ( ! is_array( $children ) ) {
                    continue;
                }
                $layout[ $parent ] = array_values(
                    array_filter(
                        array_map(
                            array( __CLASS__, 'sanitize_menu_slug' ),
                            $children
                        )
                    )
                );
            }
        }
        $clean['submenu_layout'] = $layout;

        $clean['submenu_layout_enabled'] = ! empty( $clean['submenu_layout_enabled'] );
        if ( ! $clean['submenu_layout_enabled'] ) {
            $clean['submenu_layout'] = array();
        }

        $custom_menus = array();
        if ( ! empty( $clean['custom_top_menus'] ) && is_array( $clean['custom_top_menus'] ) ) {
            $seen = array();
            foreach ( $clean['custom_top_menus'] as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
                if ( '' === $title ) {
                    continue;
                }
                $slug = isset( $row['slug'] ) ? self::sanitize_menu_slug( (string) $row['slug'] ) : '';
                if ( '' === $slug || 0 !== strpos( $slug, 'tz-custom-' ) ) {
                    $base = sanitize_title( $title );
                    $slug = 'tz-custom-' . ( '' !== $base ? $base : 'menu' );
                }
                $n = 2;
                $base_slug = $slug;
                while ( isset( $seen[ $slug ] ) ) {
                    $slug = $base_slug . '-' . $n;
                    ++$n;
                }
                $seen[ $slug ] = true;

                $icon = isset( $row['icon'] ) ? sanitize_text_field( (string) $row['icon'] ) : 'dashicons-admin-generic';
                if ( '' === $icon ) {
                    $icon = 'dashicons-admin-generic';
                }
                if ( 0 !== strpos( $icon, 'dashicons-' ) && 0 !== strpos( $icon, 'data:' ) ) {
                    $icon = 'dashicons-' . ltrim( $icon, '-' );
                }

                $custom_menus[] = array(
                    'slug'  => $slug,
                    'title' => $title,
                    'icon'  => $icon,
                );
            }
        }
        $clean['custom_top_menus'] = $custom_menus;

        $separators = array();
        if ( ! empty( $clean['custom_separators'] ) && is_array( $clean['custom_separators'] ) ) {
            foreach ( $clean['custom_separators'] as $slug ) {
                $slug = sanitize_text_field( (string) $slug );
                if ( preg_match( '/^tz-sep-[a-zA-Z0-9_-]+$/', $slug ) ) {
                    $separators[] = $slug;
                }
            }
            $separators = array_values( array_unique( $separators ) );
        }
        $clean['custom_separators'] = $separators;

        update_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION, $clean, false );
    }

    /**
     * Replace stored data with a validated payload (e.g. after import).
     *
     * @param array<string, mixed> $data Data.
     * @return void
     */
    public function replace_all( array $data ) {
        $clean = self::defaults();
        foreach ( self::allowed_keys() as $key ) {
            if ( ! array_key_exists( $key, $data ) ) {
                continue;
            }
            $clean[ $key ] = $data[ $key ];
        }
        $this->save( $clean );
    }

    /**
     * @return void
     */
    public function reset() {
        delete_option( TZ_ENGINE_ADMIN_APPEARANCE_OPTION );
    }

    /**
     * Allow admin menu hook strings (file names, query strings, plugin slugs).
     *
     * @param string $slug Raw slug.
     * @return string
     */
    public static function sanitize_menu_slug( $slug ) {
        $slug = (string) $slug;
        $slug = preg_replace( '/[^\w\-.?=&\/]/', '', $slug );
        return $slug;
    }
}
