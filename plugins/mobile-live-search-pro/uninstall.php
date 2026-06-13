<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
if (isset($wpdb->options)) {
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mlsp_%' OR option_name LIKE '_transient_timeout_mlsp_%'");
}
