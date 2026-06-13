<?php
namespace Techzu\Rewards\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Elementor {
    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
    }

    /**
     * Register Elementor widget when Elementor is active.
     *
     * @param mixed $widgets_manager Elementor widgets manager.
     * @return void
     */
    public function register_widget( $widgets_manager ) {
        if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
            return;
        }

        require_once TZ_REWARDS_PLUGIN_PATH . 'includes/Integrations/Elementor_Rewards_Widget.php';

        if ( method_exists( $widgets_manager, 'register' ) ) {
            $widgets_manager->register( new Elementor_Rewards_Widget() );
            return;
        }

        if ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
            $widgets_manager->register_widget_type( new Elementor_Rewards_Widget() );
        }
    }
}
