<?php
namespace Techzu\Rewards\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Elementor_Rewards_Widget extends \Elementor\Widget_Base {
    /**
     * Widget name.
     *
     * @return string
     */
    public function get_name() {
        return 'techzu_rewards';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Techzu Rewards', 'techzu-rewards' );
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon() {
        return 'eicon-product-info';
    }

    /**
     * Widget categories.
     *
     * @return array<int,string>
     */
    public function get_categories() {
        return array( 'woocommerce-elements', 'general' );
    }

    /**
     * Register controls.
     *
     * @return void
     */
    protected function register_controls() {
        $this->start_controls_section(
            'content_section',
            array(
                'label' => __( 'Rewards view', 'techzu-rewards' ),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            )
        );

        $this->add_control(
            'view',
            array(
                'label'   => __( 'View', 'techzu-rewards' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'program',
                'options' => array(
                    'program'   => __( 'Public programme page', 'techzu-rewards' ),
                    'dashboard' => __( 'Customer dashboard', 'techzu-rewards' ),
                    'balance'   => __( 'Balance only', 'techzu-rewards' ),
                    'checkout'  => __( 'Checkout reward controls', 'techzu-rewards' ),
                ),
            )
        );

        $this->end_controls_section();
    }

    /**
     * Render widget.
     *
     * @return void
     */
    protected function render() {
        $settings = $this->get_settings_for_display();
        $view     = isset( $settings['view'] ) ? sanitize_key( $settings['view'] ) : 'program';

        if ( 'dashboard' === $view ) {
            echo do_shortcode( '[tz_rewards_dashboard]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        if ( 'balance' === $view ) {
            echo do_shortcode( '[tz_rewards_balance]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        if ( 'checkout' === $view ) {
            echo do_shortcode( '[tz_rewards_checkout_controls]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        echo do_shortcode( '[tz_rewards_program]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
