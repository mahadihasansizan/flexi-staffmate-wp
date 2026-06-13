<?php
namespace Techzu\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    const OPTION_KEY = 'tz_rewards_settings';

    /**
     * Get normalized settings.
     *
     * @return array<string,mixed>
     */
    public function all() {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return self::normalize( $saved );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key Setting key.
     * @param mixed  $default Fallback value.
     * @return mixed
     */
    public function get( $key, $default = null ) {
        $settings = $this->all();
        return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
    }

    /**
     * Get default settings.
     *
     * @return array<string,mixed>
     */
    public static function defaults() {
        return array(
            'enabled'                  => 'yes',
            'points_label'             => 'Bliss Points',
            'point_label_singular'     => 'Bliss Point',
            'voucher_label'            => 'Reward voucher',
            'points_per_dollar'        => 1,
            'minimum_spend'            => 0,
            'rounding_mode'            => 'floor',
            'exclude_sale_items'       => 'no',
            'subtract_negative_fees'   => 'yes',
            'earn_order_statuses'      => array( 'processing', 'completed' ),
            'points_expiry_months'     => 12,
            'non_stacking_enabled'     => 'yes',
            'block_coupons_when_reward_active' => 'yes',

            // Kept for backward compatibility with the original build.
            'bonus_match_mode'         => 'highest_matched',
            'bonus_value_type'         => 'total_points',
            'bonus_tiers'              => array(),

            'redemption_tiers'         => array(
                array(
                    'enabled' => 'yes',
                    'points'  => 150,
                    'voucher' => 5,
                    'label'   => 'S$5 off',
                ),
                array(
                    'enabled' => 'yes',
                    'points'  => 300,
                    'voucher' => 10,
                    'label'   => 'S$10 off',
                ),
                array(
                    'enabled' => 'yes',
                    'points'  => 450,
                    'voucher' => 15,
                    'label'   => 'S$15 off',
                ),
            ),

            'membership_tiers'         => array(
                array(
                    'enabled'           => 'yes',
                    'key'               => 'bronze',
                    'name'              => 'Bronze Bliss',
                    'qualification'     => 'Create a customer account',
                    'spend_threshold'   => 0,
                    'birthday_discount' => 5,
                ),
                array(
                    'enabled'           => 'yes',
                    'key'               => 'silver',
                    'name'              => 'Silver Bliss',
                    'qualification'     => 'Spend S$300 within 12 months',
                    'spend_threshold'   => 300,
                    'birthday_discount' => 10,
                ),
                array(
                    'enabled'           => 'yes',
                    'key'               => 'gold',
                    'name'              => 'Gold Bliss',
                    'qualification'     => 'Spend S$600 within 12 months',
                    'spend_threshold'   => 600,
                    'birthday_discount' => 15,
                ),
            ),
            'tier_window_months'       => 12,
            'tier_order_statuses'      => array( 'processing', 'completed' ),

            'birthday_enabled'         => 'yes',
            'birthday_minimum_spend'   => 60,
            'birthday_block_sale_items'=> 'yes',
            'birthday_auto_apply'      => 'no',
            'birthday_label'           => 'Birthday perk',

            'show_catalog_hint'        => 'yes',
            'show_account_summary'     => 'yes',
            'show_my_account_endpoint' => 'yes',
            'show_email_summary'       => 'yes',
            'show_program_shortcode'   => 'yes',

            'program_brand_text'       => 'Elegant Bliss & Gifts | Rewards Programme',
            'program_overline'         => 'Membership Loyalty Programme',
            'program_title'            => 'Elegant Bliss Rewards',
            'program_tagline'          => 'Enjoy a little bliss with every thoughtful purchase.',
            'program_earning_message'  => 'Earn 1 Bliss Point for every S$1 spent on eligible purchases.',
            'rewards_section_title'    => 'Our Rewards',
            'rewards_section_subtitle' => 'Fixed discounts for future orders',
            'tiers_section_title'      => 'Membership Tiers',
            'tiers_section_subtitle'   => 'Birthday treats by tier',
            'faq_section_title'        => 'FAQs',
            'birthday_terms'           => 'Birthday discounts are valid during the member\'s birthday month and may be used on one eligible order with a minimum spend of S$60.',
            'terms_note'               => 'Bliss Points are not exchangeable for cash. Elegant Bliss & Gifts may amend, suspend or discontinue the rewards programme, points structure or redemption terms from time to time. For rewards-related enquiries, please contact contact@elegantbliss-gifts.com.',
            'faq_items'                => array(
                array(
                    'question' => 'How do I join?',
                    'answer'   => 'Create an Elegant Bliss customer account and you will automatically become part of our rewards programme.',
                ),
                array(
                    'question' => 'How do I earn?',
                    'answer'   => 'Earn 1 Bliss Point for every S$1 spent on eligible purchases.',
                ),
                array(
                    'question' => 'How do I redeem?',
                    'answer'   => 'Redeem available fixed discount rewards at checkout once you have enough Bliss Points.',
                ),
                array(
                    'question' => 'Can I combine rewards?',
                    'answer'   => 'No. Only one reward, voucher, birthday discount or promotional code may be used per order.',
                ),
                array(
                    'question' => 'Do points expire?',
                    'answer'   => 'Bliss Points are valid for 12 months from the date they are earned.',
                ),
                array(
                    'question' => 'What if I return an order?',
                    'answer'   => 'Points earned from cancelled, refunded or returned orders may be adjusted or removed.',
                ),
            ),

            'frontend_accent'          => '#2b2118',
            'frontend_background'      => '#ffffff',
            'frontend_surface'         => '#f7f5f0',
            'frontend_border'          => '#d9d5cc',
            'frontend_text'            => '#2f2a24',
            'frontend_muted'           => '#6f675e',
            'frontend_border_radius'   => 14,
            'frontend_max_width'       => 1080,
            'debug_log'                => 'no',
        );
    }

    /**
     * Ensure defaults exist in the database.
     *
     * @return void
     */
    public static function ensure_defaults() {
        if ( false === get_option( self::OPTION_KEY, false ) ) {
            add_option( self::OPTION_KEY, self::defaults() );
        }
    }

    /**
     * Normalize and sanitize settings.
     *
     * @param array<string,mixed> $input Raw input.
     * @return array<string,mixed>
     */
    public static function normalize( $input ) {
        $defaults = self::defaults();
        $input    = wp_parse_args( is_array( $input ) ? $input : array(), $defaults );

        $output = array(
            'enabled'                  => self::sanitize_toggle( $input['enabled'] ),
            'points_label'             => sanitize_text_field( $input['points_label'] ),
            'point_label_singular'     => sanitize_text_field( $input['point_label_singular'] ),
            'voucher_label'            => sanitize_text_field( $input['voucher_label'] ),
            'points_per_dollar'        => max( 0, (float) $input['points_per_dollar'] ),
            'minimum_spend'            => max( 0, (float) $input['minimum_spend'] ),
            'rounding_mode'            => in_array( $input['rounding_mode'], array( 'floor', 'round', 'ceil' ), true ) ? $input['rounding_mode'] : $defaults['rounding_mode'],
            'exclude_sale_items'       => self::sanitize_toggle( $input['exclude_sale_items'] ),
            'subtract_negative_fees'   => self::sanitize_toggle( $input['subtract_negative_fees'] ),
            'earn_order_statuses'      => self::normalize_statuses( $input['earn_order_statuses'], $defaults['earn_order_statuses'] ),
            'points_expiry_months'     => max( 0, (int) $input['points_expiry_months'] ),
            'non_stacking_enabled'     => self::sanitize_toggle( $input['non_stacking_enabled'] ),
            'block_coupons_when_reward_active' => self::sanitize_toggle( $input['block_coupons_when_reward_active'] ),

            'bonus_match_mode'         => in_array( $input['bonus_match_mode'], array( 'highest_matched', 'exact_match' ), true ) ? $input['bonus_match_mode'] : $defaults['bonus_match_mode'],
            'bonus_value_type'         => in_array( $input['bonus_value_type'], array( 'total_points', 'bonus_points' ), true ) ? $input['bonus_value_type'] : $defaults['bonus_value_type'],
            'bonus_tiers'              => self::normalize_bonus_tiers( $input['bonus_tiers'] ),

            'redemption_tiers'         => self::normalize_redemption_tiers( $input['redemption_tiers'] ),
            'membership_tiers'         => self::normalize_membership_tiers( $input['membership_tiers'] ),
            'tier_window_months'       => max( 1, (int) $input['tier_window_months'] ),
            'tier_order_statuses'      => self::normalize_statuses( $input['tier_order_statuses'], $defaults['tier_order_statuses'] ),

            'birthday_enabled'         => self::sanitize_toggle( $input['birthday_enabled'] ),
            'birthday_minimum_spend'   => max( 0, (float) $input['birthday_minimum_spend'] ),
            'birthday_block_sale_items'=> self::sanitize_toggle( $input['birthday_block_sale_items'] ),
            'birthday_auto_apply'      => self::sanitize_toggle( $input['birthday_auto_apply'] ),
            'birthday_label'           => sanitize_text_field( $input['birthday_label'] ),

            'show_catalog_hint'        => self::sanitize_toggle( $input['show_catalog_hint'] ),
            'show_account_summary'     => self::sanitize_toggle( $input['show_account_summary'] ),
            'show_my_account_endpoint' => self::sanitize_toggle( $input['show_my_account_endpoint'] ),
            'show_email_summary'       => self::sanitize_toggle( $input['show_email_summary'] ),
            'show_program_shortcode'   => self::sanitize_toggle( $input['show_program_shortcode'] ),

            'program_brand_text'       => sanitize_text_field( $input['program_brand_text'] ),
            'program_overline'         => sanitize_text_field( $input['program_overline'] ),
            'program_title'            => sanitize_text_field( $input['program_title'] ),
            'program_tagline'          => sanitize_text_field( $input['program_tagline'] ),
            'program_earning_message'  => sanitize_text_field( $input['program_earning_message'] ),
            'rewards_section_title'    => sanitize_text_field( $input['rewards_section_title'] ),
            'rewards_section_subtitle' => sanitize_text_field( $input['rewards_section_subtitle'] ),
            'tiers_section_title'      => sanitize_text_field( $input['tiers_section_title'] ),
            'tiers_section_subtitle'   => sanitize_text_field( $input['tiers_section_subtitle'] ),
            'faq_section_title'        => sanitize_text_field( $input['faq_section_title'] ),
            'birthday_terms'           => sanitize_textarea_field( $input['birthday_terms'] ),
            'terms_note'               => sanitize_textarea_field( $input['terms_note'] ),
            'faq_items'                => self::normalize_faq_items( $input['faq_items'] ),

            'frontend_accent'          => self::sanitize_color( $input['frontend_accent'], $defaults['frontend_accent'] ),
            'frontend_background'      => self::sanitize_color( $input['frontend_background'], $defaults['frontend_background'] ),
            'frontend_surface'         => self::sanitize_color( $input['frontend_surface'], $defaults['frontend_surface'] ),
            'frontend_border'          => self::sanitize_color( $input['frontend_border'], $defaults['frontend_border'] ),
            'frontend_text'            => self::sanitize_color( $input['frontend_text'], $defaults['frontend_text'] ),
            'frontend_muted'           => self::sanitize_color( $input['frontend_muted'], $defaults['frontend_muted'] ),
            'frontend_border_radius'   => max( 0, (int) $input['frontend_border_radius'] ),
            'frontend_max_width'       => max( 320, (int) $input['frontend_max_width'] ),
            'debug_log'                => self::sanitize_toggle( $input['debug_log'] ),
        );

        if ( '' === $output['points_label'] ) {
            $output['points_label'] = $defaults['points_label'];
        }
        if ( '' === $output['point_label_singular'] ) {
            $output['point_label_singular'] = $defaults['point_label_singular'];
        }
        if ( empty( $output['redemption_tiers'] ) ) {
            $output['redemption_tiers'] = $defaults['redemption_tiers'];
        }
        if ( empty( $output['membership_tiers'] ) ) {
            $output['membership_tiers'] = $defaults['membership_tiers'];
        }
        if ( empty( $output['faq_items'] ) ) {
            $output['faq_items'] = $defaults['faq_items'];
        }

        return $output;
    }

    /**
     * Normalize bonus tiers.
     *
     * @param mixed $tiers Raw tiers.
     * @return array<int,array<string,float>>
     */
    public static function normalize_bonus_tiers( $tiers ) {
        $rows = array();

        if ( ! is_array( $tiers ) ) {
            return $rows;
        }

        foreach ( $tiers as $tier ) {
            if ( ! is_array( $tier ) ) {
                continue;
            }

            $spend = isset( $tier['spend'] ) ? (float) $tier['spend'] : 0;
            $value = isset( $tier['value'] ) ? (float) $tier['value'] : 0;

            if ( $spend <= 0 || $value < 0 ) {
                continue;
            }

            $rows[] = array(
                'spend' => $spend,
                'value' => $value,
            );
        }

        usort(
            $rows,
            static function ( $left, $right ) {
                if ( $left['spend'] === $right['spend'] ) {
                    return 0;
                }
                return ( $left['spend'] < $right['spend'] ) ? -1 : 1;
            }
        );

        return array_values( $rows );
    }

    /**
     * Normalize redemption tiers.
     *
     * @param mixed $tiers Raw tiers.
     * @return array<int,array<string,mixed>>
     */
    public static function normalize_redemption_tiers( $tiers ) {
        $rows = array();

        if ( ! is_array( $tiers ) ) {
            $tiers = self::defaults()['redemption_tiers'];
        }

        foreach ( $tiers as $tier ) {
            if ( ! is_array( $tier ) ) {
                continue;
            }

            $points  = isset( $tier['points'] ) ? (int) $tier['points'] : 0;
            $voucher = isset( $tier['voucher'] ) ? (float) $tier['voucher'] : 0;
            $label   = isset( $tier['label'] ) ? sanitize_text_field( $tier['label'] ) : '';

            if ( $points <= 0 || $voucher <= 0 ) {
                continue;
            }

            $rows[] = array(
                'enabled' => isset( $tier['enabled'] ) ? self::sanitize_toggle( $tier['enabled'] ) : 'yes',
                'points'  => $points,
                'voucher' => $voucher,
                'label'   => $label,
            );
        }

        usort(
            $rows,
            static function ( $left, $right ) {
                if ( $left['points'] === $right['points'] ) {
                    return 0;
                }
                return ( $left['points'] < $right['points'] ) ? -1 : 1;
            }
        );

        return array_values( $rows );
    }

    /**
     * Normalize membership tiers.
     *
     * @param mixed $tiers Raw tiers.
     * @return array<int,array<string,mixed>>
     */
    public static function normalize_membership_tiers( $tiers ) {
        $rows = array();

        if ( ! is_array( $tiers ) ) {
            $tiers = self::defaults()['membership_tiers'];
        }

        foreach ( $tiers as $index => $tier ) {
            if ( ! is_array( $tier ) ) {
                continue;
            }

            $name = isset( $tier['name'] ) ? sanitize_text_field( $tier['name'] ) : '';
            $key  = isset( $tier['key'] ) ? sanitize_key( $tier['key'] ) : sanitize_key( $name );
            if ( '' === $key ) {
                $key = 'tier_' . absint( $index );
            }

            if ( '' === $name ) {
                $name = ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
            }

            $rows[] = array(
                'enabled'           => isset( $tier['enabled'] ) ? self::sanitize_toggle( $tier['enabled'] ) : 'yes',
                'key'               => $key,
                'name'              => $name,
                'qualification'     => isset( $tier['qualification'] ) ? sanitize_text_field( $tier['qualification'] ) : '',
                'spend_threshold'   => isset( $tier['spend_threshold'] ) ? max( 0, (float) $tier['spend_threshold'] ) : 0,
                'birthday_discount' => isset( $tier['birthday_discount'] ) ? min( 100, max( 0, (float) $tier['birthday_discount'] ) ) : 0,
            );
        }

        usort(
            $rows,
            static function ( $left, $right ) {
                if ( $left['spend_threshold'] === $right['spend_threshold'] ) {
                    return 0;
                }
                return ( $left['spend_threshold'] < $right['spend_threshold'] ) ? -1 : 1;
            }
        );

        return array_values( $rows );
    }

    /**
     * Normalize FAQ rows.
     *
     * @param mixed $items Raw FAQ data.
     * @return array<int,array<string,string>>
     */
    public static function normalize_faq_items( $items ) {
        $rows = array();

        if ( ! is_array( $items ) ) {
            $items = self::defaults()['faq_items'];
        }

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $question = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
            $answer   = isset( $item['answer'] ) ? sanitize_textarea_field( $item['answer'] ) : '';

            if ( '' === $question && '' === $answer ) {
                continue;
            }

            $rows[] = array(
                'question' => $question,
                'answer'   => $answer,
            );
        }

        return array_values( $rows );
    }

    /**
     * Normalize order statuses.
     *
     * @param mixed              $statuses Order status values.
     * @param array<int,string>  $defaults Default values.
     * @return array<int,string>
     */
    protected static function normalize_statuses( $statuses, $defaults ) {
        $rows = array();

        if ( ! is_array( $statuses ) ) {
            $statuses = $defaults;
        }

        foreach ( $statuses as $status ) {
            $status = str_replace( 'wc-', '', sanitize_key( $status ) );
            if ( '' !== $status ) {
                $rows[] = $status;
            }
        }

        $rows = array_values( array_unique( $rows ) );

        return $rows;
    }

    /**
     * Sanitize yes/no toggles.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    protected static function sanitize_toggle( $value ) {
        return ( 'yes' === $value ) ? 'yes' : 'no';
    }

    /**
     * Sanitize a hex color with fallback.
     *
     * @param mixed  $value Raw value.
     * @param string $fallback Fallback color.
     * @return string
     */
    protected static function sanitize_color( $value, $fallback ) {
        $color = sanitize_hex_color( $value );
        return $color ? $color : $fallback;
    }
}
