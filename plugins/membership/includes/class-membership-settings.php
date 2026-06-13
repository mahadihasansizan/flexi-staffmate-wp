<?php
/**
 * Settings and membership level repository.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central settings service.
 */
final class Settings {
    public const SETTINGS_OPTION = 'membership_settings';
    public const LEVELS_OPTION   = 'membership_levels';

    /** @var Settings|null */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Settings
     */
    public static function instance(): Settings {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Default plugin settings.
     *
     * @return array<string,mixed>
     */
    public function default_settings(): array {
        return array(
            'login_gate_enabled'       => 1,
            'allow_homepage'           => 0,
            'login_page_id'            => 0,
            'protect_rest'             => 0,
            'public_paths'             => '',
            'after_login'              => 'requested',
            'custom_redirect'          => '',
            'show_member_price_notice' => 1,
        );
    }

    /**
     * Get saved settings with defaults.
     *
     * @return array<string,mixed>
     */
    public function get_settings(): array {
        $settings = get_option( self::SETTINGS_OPTION, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, $this->default_settings() );
    }

    /**
     * Save sanitized settings.
     *
     * @param array<string,mixed> $settings Raw settings.
     * @return array<string,mixed>
     */
    public function update_settings( array $settings ): array {
        $clean = $this->sanitize_settings( $settings );
        update_option( self::SETTINGS_OPTION, $clean, false );
        return $clean;
    }

    /**
     * Sanitize settings.
     *
     * @param array<string,mixed> $settings Raw settings.
     * @return array<string,mixed>
     */
    public function sanitize_settings( array $settings ): array {
        $after_login = isset( $settings['after_login'] ) ? sanitize_key( (string) wp_unslash( $settings['after_login'] ) ) : 'requested';
        if ( ! in_array( $after_login, array( 'requested', 'home', 'shop', 'account', 'custom' ), true ) ) {
            $after_login = 'requested';
        }

        return array(
            'login_gate_enabled'       => ! empty( $settings['login_gate_enabled'] ) ? 1 : 0,
            'allow_homepage'           => ! empty( $settings['allow_homepage'] ) ? 1 : 0,
            'login_page_id'            => isset( $settings['login_page_id'] ) ? absint( $settings['login_page_id'] ) : 0,
            'protect_rest'             => ! empty( $settings['protect_rest'] ) ? 1 : 0,
            'public_paths'             => isset( $settings['public_paths'] ) ? sanitize_textarea_field( wp_unslash( (string) $settings['public_paths'] ) ) : '',
            'after_login'              => $after_login,
            'custom_redirect'          => isset( $settings['custom_redirect'] ) ? esc_url_raw( wp_unslash( (string) $settings['custom_redirect'] ) ) : '',
            'show_member_price_notice' => ! empty( $settings['show_member_price_notice'] ) ? 1 : 0,
        );
    }

    /**
     * Default levels created on first activation.
     *
     * @return array<string,array<string,mixed>>
     */
    public function default_levels(): array {
        return array(
            'silver' => array(
                'key'          => 'silver',
                'name'         => __( 'Silver', 'membership' ),
                'pricing_type' => 'percent',
                'amount'       => 5,
                'description'  => __( 'Entry member pricing.', 'membership' ),
                'enabled'      => 1,
            ),
            'gold'   => array(
                'key'          => 'gold',
                'name'         => __( 'Gold', 'membership' ),
                'pricing_type' => 'percent',
                'amount'       => 10,
                'description'  => __( 'Preferred member pricing.', 'membership' ),
                'enabled'      => 1,
            ),
            'vip'    => array(
                'key'          => 'vip',
                'name'         => __( 'VIP', 'membership' ),
                'pricing_type' => 'fixed_discount',
                'amount'       => 20,
                'description'  => __( 'VIP member pricing.', 'membership' ),
                'enabled'      => 1,
            ),
        );
    }

    /**
     * Get membership levels. Empty array is respected after admin removes all levels.
     *
     * @return array<string,array<string,mixed>>
     */
    public function get_levels(): array {
        $levels = get_option( self::LEVELS_OPTION, false );

        if ( false === $levels ) {
            $levels = $this->default_levels();
        }

        if ( ! is_array( $levels ) ) {
            $levels = $this->default_levels();
        }

        return $this->sanitize_levels( $levels );
    }

    /**
     * Save membership levels.
     *
     * @param array<int|string,array<string,mixed>> $levels Raw levels.
     * @return array<string,array<string,mixed>>
     */
    public function update_levels( array $levels ): array {
        $clean = $this->sanitize_levels( $levels );
        update_option( self::LEVELS_OPTION, $clean, false );
        return $clean;
    }

    /**
     * Sanitize all levels.
     *
     * @param array<int|string,array<string,mixed>> $levels Raw levels.
     * @return array<string,array<string,mixed>>
     */
    public function sanitize_levels( array $levels ): array {
        $clean = array();
        $seen  = array();

        foreach ( $levels as $maybe_key => $level ) {
            if ( ! is_array( $level ) ) {
                continue;
            }

            $key = '';
            if ( isset( $level['key'] ) ) {
                $key = $this->normalize_key( (string) wp_unslash( $level['key'] ) );
            } elseif ( is_string( $maybe_key ) ) {
                $key = $this->normalize_key( $maybe_key );
            }

            $name = isset( $level['name'] ) ? sanitize_text_field( wp_unslash( (string) $level['name'] ) ) : '';
            if ( '' === $key && '' !== $name ) {
                $key = $this->normalize_key( $name );
            }

            if ( '' === $key || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            if ( '' === $name ) {
                $name = ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
            }

            $type = isset( $level['pricing_type'] ) ? sanitize_key( (string) wp_unslash( $level['pricing_type'] ) ) : 'none';
            $type = array_key_exists( $type, $this->pricing_types() ) ? $type : 'none';

            $clean[ $key ] = array(
                'key'          => $key,
                'name'         => $name,
                'pricing_type' => $type,
                'amount'       => isset( $level['amount'] ) ? $this->sanitize_decimal( $level['amount'] ) : 0,
                'description'  => isset( $level['description'] ) ? sanitize_text_field( wp_unslash( (string) $level['description'] ) ) : '',
                'enabled'      => ! empty( $level['enabled'] ) ? 1 : 0,
            );
        }

        return $clean;
    }

    /**
     * Pricing type labels.
     *
     * @return array<string,string>
     */
    public function pricing_types(): array {
        return array(
            'none'           => __( 'No discount', 'membership' ),
            'percent'        => __( 'Percentage off', 'membership' ),
            'fixed_discount' => __( 'Fixed amount off', 'membership' ),
            'fixed_price'    => __( 'Fixed final price', 'membership' ),
        );
    }

    /**
     * Get one level.
     *
     * @param string $key Level key.
     * @return array<string,mixed>|null
     */
    public function get_level( string $key ) {
        $key    = $this->normalize_key( $key );
        $levels = $this->get_levels();

        return $levels[ $key ] ?? null;
    }

    /**
     * Normalize level keys for options and role slugs.
     *
     * @param string $key Raw key.
     * @return string
     */
    public function normalize_key( string $key ): string {
        $key = strtolower( trim( $key ) );
        $key = str_replace( array( ' ', '-' ), '_', $key );
        $key = sanitize_key( $key );
        $key = preg_replace( '/[^a-z0-9_]/', '', (string) $key );
        $key = trim( (string) $key, '_' );

        return substr( $key, 0, 45 );
    }

    /**
     * Sanitize a decimal value.
     *
     * @param mixed $value Raw value.
     * @return float
     */
    public function sanitize_decimal( $value ): float {
        $value = is_scalar( $value ) ? (string) wp_unslash( $value ) : '0';
        $value = str_replace( ',', '.', $value );
        $value = preg_replace( '/[^0-9.\-]/', '', $value );
        return max( 0, round( (float) $value, 4 ) );
    }
}
