<?php
/**
 * Simple plugin autoloader.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loads Membership classes from includes.
 */
final class Autoloader {
    /**
     * Register the autoloader.
     *
     * @return void
     */
    public static function register(): void {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload a plugin class.
     *
     * @param string $class Full class name.
     * @return void
     */
    public static function autoload( string $class ): void {
        $prefix = __NAMESPACE__ . '\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $slug     = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $relative ) );
        $slug     = str_replace( array( '_', '\\' ), '-', (string) $slug );
        $file     = MEMBERSHIP_DIR . 'includes/class-membership-' . $slug . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
