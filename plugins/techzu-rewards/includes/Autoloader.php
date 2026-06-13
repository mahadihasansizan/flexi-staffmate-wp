<?php
namespace Techzu\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autoloader {
    /**
     * Register the autoloader.
     *
     * @return void
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Load class files for the Techzu Rewards namespace.
     *
     * @param string $class Class name.
     * @return void
     */
    public static function autoload( $class ) {
        $prefix = __NAMESPACE__ . '\\';
        $length = strlen( $prefix );

        if ( 0 !== strncmp( $prefix, $class, $length ) ) {
            return;
        }

        $relative_class = substr( $class, $length );
        $file           = TZ_REWARDS_PLUGIN_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
