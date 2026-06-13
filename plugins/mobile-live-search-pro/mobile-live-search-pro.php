<?php
/**
 * Plugin Name: Mobile Live Search Pro
 * Plugin URI: https://example.com/mobile-live-search-pro
 * Description: Mobile-first popup AJAX live search with pagination, screenshot-style results, shortcode, and Elementor widget support.
 * Version: 3.0.0
 * Author: Techzu
 * Text Domain: mobile-live-search-pro
 * Requires at least: 5.8
 * Requires PHP: 7.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MLSP_VERSION')) {
    define('MLSP_VERSION', '3.0.0');
}
if (!defined('MLSP_FILE')) {
    define('MLSP_FILE', __FILE__);
}
if (!defined('MLSP_DIR')) {
    define('MLSP_DIR', plugin_dir_path(__FILE__));
}
if (!defined('MLSP_URL')) {
    define('MLSP_URL', plugin_dir_url(__FILE__));
}

final class MLSP_Plugin {
    private static $instance = null;
    private $localized = false;
    private $elementor_registered = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 20);
        add_action('elementor/frontend/after_enqueue_styles', array($this, 'enqueue_assets'));
        add_action('elementor/frontend/after_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('elementor/preview/enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('elementor/editor/after_enqueue_scripts', array($this, 'enqueue_assets'));

        add_shortcode('mlsp_search', array($this, 'shortcode'));

        add_action('wp_ajax_mlsp_live_search', array($this, 'ajax_live_search'));
        add_action('wp_ajax_nopriv_mlsp_live_search', array($this, 'ajax_live_search'));

        add_action('elementor/widgets/register', array($this, 'register_elementor_widget'));
        add_action('elementor/widgets/widgets_registered', array($this, 'register_elementor_widget_legacy'));

        add_action('save_post', array($this, 'clear_cache'));
        add_action('deleted_post', array($this, 'clear_cache'));
        add_action('transition_post_status', array($this, 'clear_cache'));
    }

    public function register_assets() {
        if (!wp_style_is('mlsp-style', 'registered')) {
            wp_register_style(
                'mlsp-style',
                MLSP_URL . 'assets/css/mlsp.css',
                array(),
                MLSP_VERSION
            );
        }

        if (!wp_script_is('mlsp-script', 'registered')) {
            wp_register_script(
                'mlsp-script',
                MLSP_URL . 'assets/js/mlsp.js',
                array(),
                MLSP_VERSION,
                false
            );
        }
    }

    public function enqueue_assets() {
        $this->register_assets();

        if (!$this->localized) {
            wp_localize_script('mlsp-script', 'MLSP_DATA', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mlsp_nonce'),
                'defaultMinChars' => 2,
                'defaultPerPage' => 8,
                'i18n' => array(
                    'placeholder' => __('Search news...', 'mobile-live-search-pro'),
                    'typing' => __('Type to search', 'mobile-live-search-pro'),
                    'minChars' => __('Type at least %d characters', 'mobile-live-search-pro'),
                    'loading' => __('Searching...', 'mobile-live-search-pro'),
                    'noResults' => __('No results found', 'mobile-live-search-pro'),
                    'error' => __('Search failed. Please refresh and try again.', 'mobile-live-search-pro'),
                    'results' => __('results found', 'mobile-live-search-pro'),
                    'result' => __('result found', 'mobile-live-search-pro'),
                    'close' => __('Close search', 'mobile-live-search-pro'),
                ),
            ));
            $this->localized = true;
        }

        wp_enqueue_style('mlsp-style');
        wp_enqueue_script('mlsp-script');
    }

    public function shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'label' => '',
            'placeholder' => __('Search news...', 'mobile-live-search-pro'),
            'size' => '28px',
            'color' => '#111111',
            'min_chars' => 2,
            'per_page' => 8,
            'post_types' => 'post',
        ), $atts, 'mlsp_search');

        $style = '--mlsp-icon-size:' . esc_attr($atts['size']) . ';--mlsp-icon-color:' . esc_attr($atts['color']) . ';';

        return $this->render_search(array(
            'label' => sanitize_text_field($atts['label']),
            'placeholder' => sanitize_text_field($atts['placeholder']),
            'min_chars' => absint($atts['min_chars']),
            'per_page' => absint($atts['per_page']),
            'post_types' => $this->sanitize_post_types($atts['post_types']),
            'style' => $style,
        ));
    }

    public function render_search($args = array()) {
        $this->enqueue_assets();

        $defaults = array(
            'id' => '',
            'label' => '',
            'placeholder' => __('Search news...', 'mobile-live-search-pro'),
            'button_aria' => __('Open search', 'mobile-live-search-pro'),
            'min_chars' => 2,
            'per_page' => 8,
            'post_types' => array('post'),
            'style' => '',
            'extra_class' => '',
            'icon_html' => '',
        );
        $args = wp_parse_args($args, $defaults);

        $id = $args['id'] ? sanitize_html_class($args['id']) : 'mlsp-' . wp_unique_id();
        $modal_id = $id . '-modal';
        $input_id = $id . '-input';
        $min_chars = max(1, min(10, absint($args['min_chars'])));
        $per_page = max(1, min(20, absint($args['per_page'])));
        $post_types = $this->sanitize_post_types($args['post_types']);
        $post_types_value = implode(',', $post_types);
        $label = sanitize_text_field($args['label']);
        $placeholder = sanitize_text_field($args['placeholder']);
        $button_aria = sanitize_text_field($args['button_aria']);
        $style = is_string($args['style']) ? $args['style'] : '';
        $extra_class = sanitize_html_class($args['extra_class']);
        $icon_html = !empty($args['icon_html']) ? $args['icon_html'] : $this->default_icon();

        ob_start();
        ?>
        <span class="mlsp-widget <?php echo esc_attr($extra_class); ?>" id="<?php echo esc_attr($id); ?>" style="<?php echo esc_attr($style); ?>">
            <button
                type="button"
                class="mlsp-trigger"
                data-mlsp-open="1"
                data-mlsp-min-chars="<?php echo esc_attr($min_chars); ?>"
                data-mlsp-per-page="<?php echo esc_attr($per_page); ?>"
                data-mlsp-post-types="<?php echo esc_attr($post_types_value); ?>"
                data-mlsp-placeholder="<?php echo esc_attr($placeholder); ?>"
                aria-label="<?php echo esc_attr($button_aria); ?>"
                aria-haspopup="dialog"
                aria-expanded="false"
                aria-controls="<?php echo esc_attr($modal_id); ?>"
            >
                <span class="mlsp-trigger-icon" aria-hidden="true"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php if ($label !== '') : ?>
                    <span class="mlsp-trigger-label"><?php echo esc_html($label); ?></span>
                <?php endif; ?>
            </button>

            <span class="mlsp-overlay" data-mlsp-overlay="1" hidden></span>
            <span
                class="mlsp-modal"
                id="<?php echo esc_attr($modal_id); ?>"
                data-mlsp-modal="1"
                role="dialog"
                aria-modal="true"
                aria-label="<?php esc_attr_e('Live search', 'mobile-live-search-pro'); ?>"
                hidden
            >
                <span class="mlsp-panel">
                    <span class="mlsp-header">
                        <button type="button" class="mlsp-close" data-mlsp-close="1" aria-label="<?php esc_attr_e('Close search', 'mobile-live-search-pro'); ?>">
                            <span aria-hidden="true">←</span>
                        </button>
                        <span class="mlsp-input-wrap">
                            <span class="mlsp-input-icon" aria-hidden="true"><?php echo $this->default_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <input
                                id="<?php echo esc_attr($input_id); ?>"
                                class="mlsp-input"
                                data-mlsp-input="1"
                                type="search"
                                placeholder="<?php echo esc_attr($placeholder); ?>"
                                autocomplete="off"
                                inputmode="search"
                            />
                        </span>
                    </span>
                    <span class="mlsp-status" data-mlsp-status="1"><?php echo esc_html(sprintf(__('Type at least %d characters', 'mobile-live-search-pro'), $min_chars)); ?></span>
                    <span class="mlsp-results" data-mlsp-results="1" aria-live="polite"></span>
                    <span class="mlsp-pagination" data-mlsp-pagination="1" hidden></span>
                </span>
            </span>
        </span>
        <?php
        return ob_get_clean();
    }

    public function ajax_live_search() {
        $keyword = isset($_REQUEST['keyword']) ? sanitize_text_field(wp_unslash($_REQUEST['keyword'])) : '';
        $page = isset($_REQUEST['page']) ? max(1, absint($_REQUEST['page'])) : 1;
        $per_page = isset($_REQUEST['per_page']) ? max(1, min(20, absint($_REQUEST['per_page']))) : 8;
        $min_chars = isset($_REQUEST['min_chars']) ? max(1, min(10, absint($_REQUEST['min_chars']))) : 2;
        $post_types_raw = isset($_REQUEST['post_types']) ? sanitize_text_field(wp_unslash($_REQUEST['post_types'])) : 'post';
        $post_types = $this->sanitize_post_types($post_types_raw);

        $keyword_length = function_exists('mb_strlen') ? mb_strlen($keyword) : strlen($keyword);
        if ($keyword_length < $min_chars) {
            wp_send_json_success(array(
                'items' => array(),
                'total_pages' => 0,
                'current_page' => 1,
                'found_posts' => 0,
            ));
        }

        $cache_key = 'mlsp_' . md5(get_current_blog_id() . '|' . strtolower($keyword) . '|' . $page . '|' . $per_page . '|' . implode(',', $post_types));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $query_args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            's' => $keyword,
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => true,
            'suppress_filters' => false,
        );

        $query_args = apply_filters('mlsp_search_query_args', $query_args, $keyword, $page, $per_page, $post_types);
        $query = new WP_Query($query_args);

        $items = array();
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $thumb = get_the_post_thumbnail_url($post_id, 'medium');
            if (!$thumb) {
                $thumb = MLSP_URL . 'assets/images/placeholder.svg';
            }

            $category = '';
            if (get_post_type($post_id) === 'post') {
                $cats = get_the_category($post_id);
                if (!empty($cats) && !is_wp_error($cats)) {
                    $category = html_entity_decode($cats[0]->name, ENT_QUOTES, get_bloginfo('charset'));
                }
            }
            if ($category === '') {
                $post_type_object = get_post_type_object(get_post_type($post_id));
                $category = $post_type_object && !empty($post_type_object->labels->singular_name) ? $post_type_object->labels->singular_name : '';
            }

            $items[] = array(
                'id' => $post_id,
                'title' => html_entity_decode(get_the_title($post_id), ENT_QUOTES, get_bloginfo('charset')),
                'url' => get_permalink($post_id),
                'thumb' => esc_url_raw($thumb),
                'date' => get_the_date('', $post_id),
                'category' => $category,
            );
        }
        wp_reset_postdata();

        $data = array(
            'items' => $items,
            'total_pages' => (int) $query->max_num_pages,
            'current_page' => (int) $page,
            'found_posts' => (int) $query->found_posts,
        );

        set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
        wp_send_json_success($data);
    }

    public function sanitize_post_types($post_types) {
        if (is_string($post_types)) {
            $post_types = explode(',', $post_types);
        }
        if (!is_array($post_types)) {
            $post_types = array('post');
        }

        $public_post_types = get_post_types(array('public' => true), 'names');
        unset($public_post_types['attachment']);

        $clean = array();
        foreach ($post_types as $post_type) {
            $post_type = sanitize_key($post_type);
            if ($post_type && isset($public_post_types[$post_type])) {
                $clean[] = $post_type;
            }
        }

        if (empty($clean)) {
            $clean = array('post');
        }

        return array_values(array_unique($clean));
    }

    public function get_post_type_options() {
        $types = get_post_types(array('public' => true), 'objects');
        $options = array();
        foreach ($types as $type => $object) {
            if ($type === 'attachment') {
                continue;
            }
            $options[$type] = isset($object->labels->singular_name) ? $object->labels->singular_name : $type;
        }
        if (empty($options)) {
            $options['post'] = __('Posts', 'mobile-live-search-pro');
        }
        return $options;
    }

    public function register_elementor_widget($widgets_manager) {
        if ($this->elementor_registered) {
            return;
        }
        if (!class_exists('\\Elementor\\Widget_Base')) {
            return;
        }

        $file = MLSP_DIR . 'includes/elementor/class-mlsp-elementor-widget.php';
        if (file_exists($file)) {
            require_once $file;
        }

        if (!class_exists('MLSP_Elementor_Widget')) {
            return;
        }

        if (is_object($widgets_manager) && method_exists($widgets_manager, 'register')) {
            $widgets_manager->register(new MLSP_Elementor_Widget());
            $this->elementor_registered = true;
            return;
        }

        if (is_object($widgets_manager) && method_exists($widgets_manager, 'register_widget_type')) {
            $widgets_manager->register_widget_type(new MLSP_Elementor_Widget());
            $this->elementor_registered = true;
        }
    }

    public function register_elementor_widget_legacy() {
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }
        $plugin = \Elementor\Plugin::instance();
        if (!empty($plugin->widgets_manager)) {
            $this->register_elementor_widget($plugin->widgets_manager);
        }
    }

    public function clear_cache() {
        global $wpdb;
        if (!isset($wpdb->options)) {
            return;
        }
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mlsp_%' OR option_name LIKE '_transient_timeout_mlsp_%'");
    }

    private function default_icon() {
        return '<svg class="mlsp-svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="16.65" y1="16.65" x2="21" y2="21"></line></svg>';
    }
}

MLSP_Plugin::instance();
