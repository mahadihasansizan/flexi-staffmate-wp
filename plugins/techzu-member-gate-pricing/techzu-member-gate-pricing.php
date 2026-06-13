<?php
/**
 * Plugin Name: Techzu Member Gate Pricing
 * Plugin URI: https://techzu.site
 * Description: Adds a frontend login gate and WooCommerce membership pricing by global level, user override, and product-specific rules.
 * Version: 1.0.0
 * Author: Techzu
 * Author URI: https://techzu.site
 * Text Domain: techzu-member-gate-pricing
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

final class Techzu_Member_Gate_Pricing {
    const VERSION = '1.0.0';
    const OPTION_SETTINGS = 'tmgmp_settings';
    const META_USER_LEVEL = 'tmgmp_membership_level';
    const META_USER_OVERRIDE_TYPE = 'tmgmp_user_override_type';
    const META_USER_OVERRIDE_AMOUNT = 'tmgmp_user_override_amount';
    const META_PRODUCT_RULES = '_tmgmp_product_rules';
    const LOGIN_PAGE_OPTION = 'tmgmp_login_page_id';

    private static $instance = null;
    private $pricing_stack = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));

        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_action('init', array($this, 'register_shortcode'));
        add_action('template_redirect', array($this, 'maybe_force_login'), 1);
        add_filter('login_redirect', array($this, 'login_redirect'), 10, 3);

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_tmgmp_save_settings', array($this, 'save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));

        add_action('show_user_profile', array($this, 'user_profile_fields'));
        add_action('edit_user_profile', array($this, 'user_profile_fields'));
        add_action('personal_options_update', array($this, 'save_user_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_fields'));
        add_filter('manage_users_columns', array($this, 'users_column'));
        add_filter('manage_users_custom_column', array($this, 'users_column_value'), 10, 3);

        add_action('add_meta_boxes', array($this, 'product_metabox'));
        add_action('save_post_product', array($this, 'save_product_rules'));

        add_filter('woocommerce_product_get_price', array($this, 'filter_product_price'), 20, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'filter_product_price'), 20, 2);
        add_filter('woocommerce_variation_prices_price', array($this, 'filter_variation_prices_hash_price'), 20, 3);
        add_filter('woocommerce_get_variation_prices_hash', array($this, 'variation_prices_hash'), 20, 3);
        add_action('woocommerce_before_calculate_totals', array($this, 'set_cart_prices'), 20, 1);
        add_filter('woocommerce_cart_item_price', array($this, 'cart_item_price_html'), 20, 3);
    }

    public static function activate() {
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, array(
                'enabled' => 1,
                'allow_home' => 0,
                'levels' => array(
                    'silver' => array('name' => 'Silver', 'type' => 'percent', 'amount' => 5),
                    'gold' => array('name' => 'Gold', 'type' => 'percent', 'amount' => 10),
                    'vip' => array('name' => 'VIP', 'type' => 'fixed', 'amount' => 20),
                ),
            ));
        }

        $page_id = absint(get_option(self::LOGIN_PAGE_OPTION));
        if (!$page_id || 'publish' !== get_post_status($page_id)) {
            $page_id = wp_insert_post(array(
                'post_title' => 'Member Login',
                'post_name' => 'member-login',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => '[tmgmp_login_form]',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ));
            if (!is_wp_error($page_id)) {
                update_option(self::LOGIN_PAGE_OPTION, absint($page_id));
            }
        }
    }

    public static function deactivate() {
        // Keep settings and the generated login page for safe reactivation.
    }

    public function declare_woocommerce_compatibility() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain('techzu-member-gate-pricing', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=tmgmp')) . '">' . esc_html__('Settings', 'techzu-member-gate-pricing') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function defaults() {
        return array(
            'enabled' => 1,
            'allow_home' => 0,
            'levels' => array(),
        );
    }

    public function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), $this->defaults());
        $settings['levels'] = isset($settings['levels']) && is_array($settings['levels']) ? $settings['levels'] : array();
        return $settings;
    }

    public function get_levels() {
        return $this->get_settings()['levels'];
    }

    public function register_shortcode() {
        add_shortcode('tmgmp_login_form', array($this, 'login_shortcode'));
    }

    public function login_shortcode($atts) {
        $atts = shortcode_atts(array('redirect' => ''), $atts, 'tmgmp_login_form');
        $redirect = !empty($atts['redirect']) ? esc_url_raw($atts['redirect']) : $this->safe_redirect_after_login();

        ob_start();
        echo '<div class="tmgmp-login-wrap">';
        if (is_user_logged_in()) {
            echo '<p>' . esc_html__('You are already logged in.', 'techzu-member-gate-pricing') . '</p>';
            echo '<p><a class="button" href="' . esc_url(home_url('/')) . '">' . esc_html__('Enter website', 'techzu-member-gate-pricing') . '</a></p>';
        } else {
            echo '<h2>' . esc_html__('Member Login', 'techzu-member-gate-pricing') . '</h2>';
            wp_login_form(array(
                'echo' => true,
                'redirect' => $redirect,
                'remember' => true,
                'label_username' => __('Username or Email Address', 'techzu-member-gate-pricing'),
                'label_password' => __('Password', 'techzu-member-gate-pricing'),
                'label_log_in' => __('Log In', 'techzu-member-gate-pricing'),
            ));
            echo '<p class="tmgmp-login-links"><a href="' . esc_url(wp_lostpassword_url()) . '">' . esc_html__('Lost your password?', 'techzu-member-gate-pricing') . '</a></p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private function safe_redirect_after_login() {
        $requested = isset($_GET['tmgmp_redirect']) ? esc_url_raw(wp_unslash($_GET['tmgmp_redirect'])) : '';
        if ($requested && wp_validate_redirect($requested, false)) {
            return $requested;
        }
        return home_url('/');
    }

    public function login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!empty($requested_redirect_to) && wp_validate_redirect($requested_redirect_to, false)) {
            return $requested_redirect_to;
        }
        return $redirect_to;
    }

    public function maybe_force_login() {
        $settings = $this->get_settings();
        if (empty($settings['enabled']) || is_user_logged_in()) {
            return;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || $this->is_rest_request()) {
            return;
        }

        if (!empty($settings['allow_home']) && is_front_page()) {
            return;
        }

        $login_page_id = absint(get_option(self::LOGIN_PAGE_OPTION));
        if ($login_page_id && is_page($login_page_id)) {
            return;
        }

        if (function_exists('is_account_page') && is_account_page()) {
            return;
        }

        $path = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (false !== strpos($path, 'wp-login.php') || false !== strpos($path, 'wp-register.php')) {
            return;
        }

        $target = $login_page_id ? get_permalink($login_page_id) : wp_login_url();
        $redirect = add_query_arg('tmgmp_redirect', rawurlencode(home_url($path)), $target);
        wp_safe_redirect($redirect);
        exit;
    }

    private function is_rest_request() {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        $prefix = rest_get_url_prefix();
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return false !== strpos($uri, '/' . $prefix . '/');
    }

    public function admin_menu() {
        add_options_page(
            __('Member Gate Pricing', 'techzu-member-gate-pricing'),
            __('Member Gate Pricing', 'techzu-member-gate-pricing'),
            'manage_options',
            'tmgmp',
            array($this, 'settings_page')
        );
    }

    public function frontend_assets() {
        wp_register_style('tmgmp-frontend', false, array(), self::VERSION);
        wp_enqueue_style('tmgmp-frontend');
        wp_add_inline_style('tmgmp-frontend', '.tmgmp-login-wrap{max-width:420px;margin:40px auto;padding:28px;border:1px solid #ddd;border-radius:8px;background:#fff}.tmgmp-login-wrap input[type=text],.tmgmp-login-wrap input[type=password]{width:100%}.tmgmp-login-links{margin-top:16px}');
    }

    public function admin_assets($hook) {
        if ('settings_page_tmgmp' === $hook || 'post.php' === $hook || 'post-new.php' === $hook || 'user-edit.php' === $hook || 'profile.php' === $hook) {
            wp_enqueue_style('tmgmp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
        }
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = $this->get_settings();
        $levels = $settings['levels'];
        $login_page_id = absint(get_option(self::LOGIN_PAGE_OPTION));
        ?>
        <div class="wrap tmgmp-admin">
            <h1><?php esc_html_e('Member Gate Pricing', 'techzu-member-gate-pricing'); ?></h1>
            <?php if (!class_exists('WooCommerce')) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('WooCommerce is not active. Login gating still works, but membership pricing requires WooCommerce.', 'techzu-member-gate-pricing'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tmgmp_save_settings'); ?>
                <input type="hidden" name="action" value="tmgmp_save_settings" />

                <h2><?php esc_html_e('Login Gate', 'techzu-member-gate-pricing'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Require login before entering site', 'techzu-member-gate-pricing'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> /> <?php esc_html_e('Enabled', 'techzu-member-gate-pricing'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Allow public homepage', 'techzu-member-gate-pricing'); ?></th>
                        <td><label><input type="checkbox" name="allow_home" value="1" <?php checked(!empty($settings['allow_home'])); ?> /> <?php esc_html_e('Let visitors see the homepage without logging in', 'techzu-member-gate-pricing'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Login page', 'techzu-member-gate-pricing'); ?></th>
                        <td>
                            <?php wp_dropdown_pages(array('name' => 'login_page_id', 'selected' => $login_page_id, 'show_option_none' => __('Use WordPress login', 'techzu-member-gate-pricing'))); ?>
                            <p class="description"><?php esc_html_e('Place [tmgmp_login_form] on this page.', 'techzu-member-gate-pricing'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Global Membership Levels', 'techzu-member-gate-pricing'); ?></h2>
                <p><?php esc_html_e('These rules apply store-wide unless a user override or product-specific rule is set.', 'techzu-member-gate-pricing'); ?></p>
                <table class="widefat striped tmgmp-level-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Level key', 'techzu-member-gate-pricing'); ?></th>
                            <th><?php esc_html_e('Display name', 'techzu-member-gate-pricing'); ?></th>
                            <th><?php esc_html_e('Pricing type', 'techzu-member-gate-pricing'); ?></th>
                            <th><?php esc_html_e('Amount', 'techzu-member-gate-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = $levels;
                        for ($i = 0; $i < 3; $i++) {
                            $rows['new_' . $i . '_' . count($rows)] = array('name' => '', 'type' => 'percent', 'amount' => '');
                        }
                        foreach ($rows as $key => $level) :
                            $is_new = 0 === strpos((string) $key, 'new_');
                            ?>
                            <tr>
                                <td><input type="text" name="levels[<?php echo esc_attr($key); ?>][key]" value="<?php echo esc_attr($is_new ? '' : $key); ?>" placeholder="gold" /></td>
                                <td><input type="text" name="levels[<?php echo esc_attr($key); ?>][name]" value="<?php echo esc_attr($level['name'] ?? ''); ?>" placeholder="Gold" /></td>
                                <td><?php $this->pricing_type_select('levels[' . esc_attr($key) . '][type]', $level['type'] ?? 'percent', false); ?></td>
                                <td><input type="number" step="0.01" min="0" name="levels[<?php echo esc_attr($key); ?>][amount]" value="<?php echo esc_attr($level['amount'] ?? ''); ?>" /></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Percent means percentage off. Fixed means a fixed amount off. Add more rows by saving, then editing again.', 'techzu-member-gate-pricing'); ?></p>
                <?php submit_button(__('Save settings', 'techzu-member-gate-pricing')); ?>
            </form>
        </div>
        <?php
    }

    private function pricing_type_select($name, $selected, $include_inherit = true) {
        $types = array();
        if ($include_inherit) {
            $types['inherit'] = __('Use global/user rule', 'techzu-member-gate-pricing');
        }
        $types['none'] = __('No discount for this level', 'techzu-member-gate-pricing');
        $types['percent'] = __('Percentage off', 'techzu-member-gate-pricing');
        $types['fixed'] = __('Fixed amount off', 'techzu-member-gate-pricing');
        $types['fixed_price'] = __('Fixed final product price', 'techzu-member-gate-pricing');
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'techzu-member-gate-pricing'));
        }
        check_admin_referer('tmgmp_save_settings');

        $levels = array();
        $posted_levels = isset($_POST['levels']) && is_array($_POST['levels']) ? wp_unslash($_POST['levels']) : array();
        foreach ($posted_levels as $row) {
            $key = isset($row['key']) ? sanitize_key($row['key']) : '';
            $name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
            if ('' === $key || '' === $name) {
                continue;
            }
            $type = isset($row['type']) ? sanitize_key($row['type']) : 'percent';
            if (!in_array($type, array('percent', 'fixed', 'none', 'fixed_price'), true)) {
                $type = 'percent';
            }
            $levels[$key] = array(
                'name' => $name,
                'type' => $type,
                'amount' => isset($row['amount']) ? max(0, (float) $this->clean_decimal($row['amount'])) : 0,
            );
        }

        $settings = array(
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'allow_home' => !empty($_POST['allow_home']) ? 1 : 0,
            'levels' => $levels,
        );
        update_option(self::OPTION_SETTINGS, $settings);
        update_option(self::LOGIN_PAGE_OPTION, isset($_POST['login_page_id']) ? absint($_POST['login_page_id']) : 0);

        wp_safe_redirect(add_query_arg(array('page' => 'tmgmp', 'updated' => 'true'), admin_url('options-general.php')));
        exit;
    }

    public function user_profile_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        $level = get_user_meta($user->ID, self::META_USER_LEVEL, true);
        $override_type = get_user_meta($user->ID, self::META_USER_OVERRIDE_TYPE, true);
        $override_amount = get_user_meta($user->ID, self::META_USER_OVERRIDE_AMOUNT, true);
        ?>
        <h2><?php esc_html_e('Membership Pricing', 'techzu-member-gate-pricing'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="tmgmp_membership_level"><?php esc_html_e('Membership level', 'techzu-member-gate-pricing'); ?></label></th>
                <td>
                    <select name="tmgmp_membership_level" id="tmgmp_membership_level">
                        <option value=""><?php esc_html_e('No membership', 'techzu-member-gate-pricing'); ?></option>
                        <?php foreach ($this->get_levels() as $key => $data) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($level, $key); ?>><?php echo esc_html($data['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="tmgmp_user_override_type"><?php esc_html_e('User-specific pricing override', 'techzu-member-gate-pricing'); ?></label></th>
                <td>
                    <?php $this->pricing_type_select('tmgmp_user_override_type', $override_type ?: 'inherit', true); ?>
                    <input type="number" step="0.01" min="0" name="tmgmp_user_override_amount" value="<?php echo esc_attr($override_amount); ?>" />
                    <p class="description"><?php esc_html_e('Optional. Applies to this user unless a product-specific rule overrides it.', 'techzu-member-gate-pricing'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        $level = isset($_POST['tmgmp_membership_level']) ? sanitize_key(wp_unslash($_POST['tmgmp_membership_level'])) : '';
        if ($level && !array_key_exists($level, $this->get_levels())) {
            $level = '';
        }
        update_user_meta($user_id, self::META_USER_LEVEL, $level);

        $type = isset($_POST['tmgmp_user_override_type']) ? sanitize_key(wp_unslash($_POST['tmgmp_user_override_type'])) : 'inherit';
        if (!in_array($type, array('inherit', 'none', 'percent', 'fixed', 'fixed_price'), true)) {
            $type = 'inherit';
        }
        update_user_meta($user_id, self::META_USER_OVERRIDE_TYPE, $type);
        update_user_meta($user_id, self::META_USER_OVERRIDE_AMOUNT, isset($_POST['tmgmp_user_override_amount']) ? max(0, (float) $this->clean_decimal(wp_unslash($_POST['tmgmp_user_override_amount']))) : 0);
    }

    public function users_column($columns) {
        $columns['tmgmp_membership'] = __('Membership', 'techzu-member-gate-pricing');
        return $columns;
    }

    public function users_column_value($value, $column_name, $user_id) {
        if ('tmgmp_membership' !== $column_name) {
            return $value;
        }
        $level = get_user_meta($user_id, self::META_USER_LEVEL, true);
        $levels = $this->get_levels();
        return $level && isset($levels[$level]) ? esc_html($levels[$level]['name']) : '&mdash;';
    }

    public function product_metabox() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_meta_box(
            'tmgmp_product_rules',
            __('Membership Pricing Rules', 'techzu-member-gate-pricing'),
            array($this, 'render_product_metabox'),
            'product',
            'normal',
            'default'
        );
    }

    public function render_product_metabox($post) {
        wp_nonce_field('tmgmp_save_product_rules', 'tmgmp_product_nonce');
        $rules = get_post_meta($post->ID, self::META_PRODUCT_RULES, true);
        $rules = is_array($rules) ? $rules : array();
        ?>
        <p><?php esc_html_e('Set product-specific pricing per level. These rules override user-specific and global rules.', 'techzu-member-gate-pricing'); ?></p>
        <table class="widefat striped tmgmp-product-table">
            <thead><tr><th><?php esc_html_e('Level', 'techzu-member-gate-pricing'); ?></th><th><?php esc_html_e('Rule', 'techzu-member-gate-pricing'); ?></th><th><?php esc_html_e('Amount', 'techzu-member-gate-pricing'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($this->get_levels() as $key => $level) :
                    $rule = $rules[$key] ?? array('type' => 'inherit', 'amount' => '');
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($level['name']); ?></strong><br><code><?php echo esc_html($key); ?></code></td>
                        <td><?php $this->pricing_type_select('tmgmp_product_rules[' . esc_attr($key) . '][type]', $rule['type'] ?? 'inherit', true); ?></td>
                        <td><input type="number" step="0.01" min="0" name="tmgmp_product_rules[<?php echo esc_attr($key); ?>][amount]" value="<?php echo esc_attr($rule['amount'] ?? ''); ?>" /></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function save_product_rules($post_id) {
        if (!isset($_POST['tmgmp_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tmgmp_product_nonce'])), 'tmgmp_save_product_rules')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $saved = array();
        $rules = isset($_POST['tmgmp_product_rules']) && is_array($_POST['tmgmp_product_rules']) ? wp_unslash($_POST['tmgmp_product_rules']) : array();
        foreach ($this->get_levels() as $key => $level) {
            $row = $rules[$key] ?? array();
            $type = isset($row['type']) ? sanitize_key($row['type']) : 'inherit';
            if (!in_array($type, array('inherit', 'none', 'percent', 'fixed', 'fixed_price'), true)) {
                $type = 'inherit';
            }
            $saved[$key] = array(
                'type' => $type,
                'amount' => isset($row['amount']) ? max(0, (float) $this->clean_decimal($row['amount'])) : 0,
            );
        }
        update_post_meta($post_id, self::META_PRODUCT_RULES, $saved);
    }

    private function current_user_level($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if (!$user_id) {
            return '';
        }
        $level = get_user_meta($user_id, self::META_USER_LEVEL, true);
        return $level && array_key_exists($level, $this->get_levels()) ? $level : '';
    }

    private function rule_for_product($product, $user_id = 0) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return null;
        }
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $level_key = $this->current_user_level($user_id);
        if (!$level_key) {
            return null;
        }

        $product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $product_rules = get_post_meta($product_id, self::META_PRODUCT_RULES, true);
        if (is_array($product_rules) && isset($product_rules[$level_key])) {
            $rule = $product_rules[$level_key];
            $type = $rule['type'] ?? 'inherit';
            if ('none' === $type) {
                return array('type' => 'none', 'amount' => 0, 'source' => 'product');
            }
            if ('inherit' !== $type) {
                return array('type' => $type, 'amount' => (float) ($rule['amount'] ?? 0), 'source' => 'product');
            }
        }

        $user_type = get_user_meta($user_id, self::META_USER_OVERRIDE_TYPE, true);
        if ($user_type && 'inherit' !== $user_type) {
            if ('none' === $user_type) {
                return array('type' => 'none', 'amount' => 0, 'source' => 'user');
            }
            return array('type' => $user_type, 'amount' => (float) get_user_meta($user_id, self::META_USER_OVERRIDE_AMOUNT, true), 'source' => 'user');
        }

        $levels = $this->get_levels();
        if (isset($levels[$level_key])) {
            return array(
                'type' => $levels[$level_key]['type'] ?? 'percent',
                'amount' => (float) ($levels[$level_key]['amount'] ?? 0),
                'source' => 'global',
            );
        }
        return null;
    }

    private function apply_rule_to_price($price, $rule) {
        if ('' === $price || null === $price || !$rule || empty($rule['type']) || 'none' === $rule['type']) {
            return $price;
        }
        $price = (float) $price;
        $amount = max(0, (float) ($rule['amount'] ?? 0));
        switch ($rule['type']) {
            case 'percent':
                $amount = min(100, $amount);
                $price = $price - ($price * ($amount / 100));
                break;
            case 'fixed':
                $price = $price - $amount;
                break;
            case 'fixed_price':
                $price = $amount;
                break;
        }
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        return max(0, $this->clean_decimal($price, $decimals));
    }

    private function clean_decimal($value, $decimals = false) {
        if (function_exists('wc_format_decimal')) {
            return wc_format_decimal($value, $decimals);
        }
        $value = is_string($value) ? str_replace(',', '.', $value) : $value;
        $value = is_numeric($value) ? (float) $value : 0;
        return false === $decimals ? $value : number_format($value, absint($decimals), '.', '');
    }

    public function filter_product_price($price, $product) {
        if ($this->pricing_stack || is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        $this->pricing_stack = true;
        $rule = $this->rule_for_product($product);
        $new_price = $this->apply_rule_to_price($price, $rule);
        $this->pricing_stack = false;
        return $new_price;
    }

    public function filter_variation_prices_hash_price($price, $variation, $product) {
        return $this->filter_product_price($price, $variation);
    }

    public function variation_prices_hash($hash, $product, $for_display) {
        $hash['tmgmp_user'] = get_current_user_id();
        $hash['tmgmp_level'] = $this->current_user_level();
        $hash['tmgmp_version'] = self::VERSION;
        return $hash;
    }

    public function set_cart_prices($cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (did_action('woocommerce_before_calculate_totals') > 2) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
                continue;
            }
            $product = $cart_item['data'];
            $base_price = isset($cart_item['tmgmp_base_price']) ? $cart_item['tmgmp_base_price'] : $product->get_price('edit');
            if ('' === $base_price || null === $base_price) {
                $base_price = $product->get_regular_price('edit');
            }
            $rule = $this->rule_for_product($product);
            if ($rule && 'none' !== $rule['type']) {
                $cart->cart_contents[$cart_item_key]['tmgmp_base_price'] = $base_price;
                $cart->cart_contents[$cart_item_key]['tmgmp_rule'] = $rule;
                $product->set_price($this->apply_rule_to_price($base_price, $rule));
            }
        }
    }

    public function cart_item_price_html($price_html, $cart_item, $cart_item_key) {
        if (!empty($cart_item['tmgmp_rule']) && isset($cart_item['tmgmp_base_price'])) {
            $original = wc_price($cart_item['tmgmp_base_price']);
            $price_html = '<del>' . $original . '</del> <ins>' . $price_html . '</ins>';
        }
        return $price_html;
    }
}

Techzu_Member_Gate_Pricing::instance();
