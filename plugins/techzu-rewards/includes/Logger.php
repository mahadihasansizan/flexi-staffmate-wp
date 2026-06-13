<?php
namespace Techzu\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Logger {
    /**
     * Settings instance.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings object.
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Write a message to the WooCommerce logger when enabled.
     *
     * @param string               $message Message text.
     * @param array<string,mixed>  $context Context data.
     * @return void
     */
    public function log( $message, $context = array() ) {
        if ( 'yes' !== $this->settings->get( 'debug_log', 'no' ) || ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $logger = wc_get_logger();
        $logger->info(
            $message . ' ' . wp_json_encode( $context ),
            array(
                'source' => 'techzu-rewards',
            )
        );
    }
}
