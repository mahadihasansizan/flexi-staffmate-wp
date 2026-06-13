<?php
namespace Techzu\Engine\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Module_Interface {
    /**
     * Unique module id used in settings (e.g. module_dashboard_clean).
     *
     * @return string
     */
    public function setting_key();

    /**
     * Register WordPress hooks when the module is enabled.
     *
     * @return void
     */
    public function register();
}
