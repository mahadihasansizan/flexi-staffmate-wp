<?php
namespace Techzu\Engine;

use Techzu\Engine\Admin\Settings_Page;
use Techzu\Engine\Modules\Dashboard_Clean_Module;
use Techzu\Engine\Modules\Dashboard_Support_Widget_Module;
use Techzu\Engine\Modules\Module_Interface;
use Techzu\Engine\Modules\Admin_Appearance_Module;
use Techzu\Engine\Modules\Admin_Branding_Module;
use Techzu\Engine\Modules\WooCommerce_Commerce_Hub_Module;
use Techzu\Engine\Modules\Login_Page_Module;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    /**
     * @var Plugin|null
     */
    protected static $instance = null;

    /**
     * @var bool
     */
    protected $booted = false;

    /**
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return void
     */
    public static function activate() {
        Settings::ensure_defaults();
        delete_option( Admin_Branding_Module::RUNTIME_SLUG_OPTION );
        Admin_Branding_Module::schedule_rewrite_flush();
    }

    /**
     * @return void
     */
    public function boot() {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain( 'techzu-engine', false, dirname( plugin_basename( TZ_ENGINE_PLUGIN_FILE ) ) . '/languages' );

        $settings    = new Settings();
        $admin_page  = new Settings_Page( $settings );
        $admin_page->hooks();

        add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_assets' ), 0 );

        $modules = $this->build_modules( $settings );

        /**
         * Allow other Techzu plugins to register engine modules.
         *
         * @param Module_Interface[] $modules Modules to boot when enabled.
         * @param Settings           $settings Shared settings service.
         */
        $modules = apply_filters( 'tz_engine_modules', $modules, $settings );

        foreach ( $modules as $module ) {
            if ( ! $module instanceof Module_Interface ) {
                continue;
            }
            if ( $settings->is_module_enabled( $module->setting_key() ) ) {
                $module->register();
            }
        }
    }

    /**
     * @param Settings $settings Settings.
     * @return Module_Interface[]
     */
    protected function build_modules( Settings $settings ) {
        return array(
            new Dashboard_Clean_Module( $settings ),
            new Dashboard_Support_Widget_Module( $settings ),
            new WooCommerce_Commerce_Hub_Module( $settings ),
            new Admin_Branding_Module( $settings ),
            new Admin_Appearance_Module( $settings ),
            new Login_Page_Module( $settings ),
        );
    }

    /**
     * Register shared styles (enqueued as a dependency by screens that need them).
     *
     * @return void
     */
    public function register_admin_assets() {
        wp_register_style(
            'tz-engine-admin-ui',
            TZ_ENGINE_PLUGIN_URL . 'assets/css/admin-ui.css',
            array(),
            TZ_ENGINE_VERSION
        );
    }
}
