<?php
/**
 * Plugin Name: MUNSHIGANJ Map by Mahadi
 * Description: Interactive SVG map of Munshiganj District with admin-manageable links. Use shortcode [munshiganj_map].
 * Version: 1.1.1
 * Author: Mahadi
 * License: GPLv2 or later
 * Text Domain: munshiganj-map-by-mahadi
 */

if (!defined('ABSPATH')) { exit; }

define('MMBM_OPTION_KEY', 'mmbm_munshiganj_links');

function mmbm_default_links() {
    return array(
        'srinagar'    => array('label' => 'শ্রীনগর', 'url' => ''),
        'sirajdikhan' => array('label' => 'সিরাজদিখান', 'url' => ''),
        'tongibari'   => array('label' => 'টংগিবাড়ী', 'url' => ''),
        'munshiganj'  => array('label' => 'সদর', 'url' => ''),
        'gazaria'     => array('label' => 'গজারিয়া', 'url' => ''),
        'lauhajang'   => array('label' => 'লৌহজং', 'url' => ''),
    );
}

function mmbm_activate() {
    if (get_option(MMBM_OPTION_KEY) === false) {
        add_option(MMBM_OPTION_KEY, mmbm_default_links());
    }
}
register_activation_hook(__FILE__, 'mmbm_activate');

function mmbm_get_links() {
    $links = get_option(MMBM_OPTION_KEY);
    if (!is_array($links)) { $links = array(); }
    $defaults = mmbm_default_links();
    foreach ($defaults as $k => $v) {
        if (!isset($links[$k]) || !is_array($links[$k])) { $links[$k] = array(); }
        $links[$k]['label'] = isset($links[$k]['label']) ? sanitize_text_field($links[$k]['label']) : $v['label'];
        $links[$k]['url']   = isset($links[$k]['url']) ? mmbm_sanitize_link_url($links[$k]['url']) : $v['url'];
    }
    return $links;
}

add_action('admin_menu', function() {
    add_options_page(
        'Munshiganj Map Links',
        'Munshiganj Map',
        'manage_options',
        'mmbm-munshiganj-map',
        'mmbm_render_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('mmbm_settings_group', MMBM_OPTION_KEY, array(
        'type'              => 'array',
        'sanitize_callback' => 'mmbm_sanitize_links',
        'default'           => mmbm_default_links(),
    ));
});

/**
 * Normalize a URL for map links (full URLs, mailto:, tel:, and root-relative paths).
 *
 * @param string $url Raw input.
 * @return string Sanitized URL or empty string.
 */
function mmbm_sanitize_link_url($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $try = esc_url_raw($url, array('http', 'https', 'mailto', 'tel'));
    if ($try !== '') {
        return $try;
    }
    /** Root-relative internal link (ASCII or Unicode slug): resolve with home_url. */
    if (preg_match('#^/[\p{L}\p{N}\-_./?%&=#:~]*$#u', $url)) {
        return esc_url_raw(home_url($url));
    }
    return '';
}

function mmbm_sanitize_links($input) {
    if (!is_array($input)) {
        $input = array();
    }
    $defaults = mmbm_default_links();
    $out = array();
    foreach ($defaults as $key => $def) {
        $label = isset($input[$key]['label']) ? sanitize_text_field($input[$key]['label']) : $def['label'];
        $url   = isset($input[$key]['url']) ? mmbm_sanitize_link_url($input[$key]['url']) : '';
        $out[$key] = array('label' => $label, 'url' => $url);
    }
    return $out;
}

function mmbm_render_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    $links = mmbm_get_links();
    ?>
    <div class="wrap">
        <h1>MUNSHIGANJ Map by Mahadi</h1>
        <p>Manage destination URLs for each upazila block. Shortcode: <code>[munshiganj_map]</code></p>

        <form method="post" action="options.php">
            <?php settings_fields('mmbm_settings_group'); ?>
            <table class="widefat striped" style="max-width:980px;">
                <thead>
                    <tr>
                        <th style="width:180px;">Area key (matches SVG <code>data-area</code>)</th>
                        <th style="width:260px;">Label (tooltip / aria)</th>
                        <th>Destination URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $key => $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td>
                                <input type="text" name="<?php echo esc_attr(MMBM_OPTION_KEY); ?>[<?php echo esc_attr($key); ?>][label]"
                                    value="<?php echo esc_attr($row['label']); ?>" class="regular-text" />
                            </td>
                            <td>
                                <input type="text" name="<?php echo esc_attr(MMBM_OPTION_KEY); ?>[<?php echo esc_attr($key); ?>][url]"
                                    value="<?php echo esc_attr($row['url']); ?>" class="regular-text"
                                    placeholder="https://… or /your-page/"
                                    autocomplete="url"
                                    style="width:100%;" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button('Save Links'); ?>
        </form>
    </div>
    <?php
}

function mmbm_enqueue_assets_once() {
    static $done = false;
    if ($done) return;
    $done = true;

    wp_register_style('mmbm-style', plugins_url('assets/mmbm.css', __FILE__), array(), '1.1.3');
    wp_register_script('mmbm-script', plugins_url('assets/mmbm.js', __FILE__), array(), '1.1.3', true);
    wp_enqueue_style('mmbm-style');
    wp_enqueue_script('mmbm-script');

    $links = mmbm_get_links();
    wp_localize_script('mmbm-script', 'MMBM_MAP_DATA', $links);
}

function mmbm_shortcode() {
    mmbm_enqueue_assets_once();

    $svg_file = plugin_dir_path(__FILE__) . 'assets/munshiganj.svg';
    if (!file_exists($svg_file)) {
        return '<p>Munshiganj SVG file not found.</p>';
    }

    // Inline the SVG so CSS/JS hover works.
    // Note: SVG is trusted from plugin assets.
    $svg = file_get_contents($svg_file);
    // Remove XML declaration (can break inline SVG in HTML)
    $svg = preg_replace('/^\s*<\?xml[^>]*>\s*/i', '', $svg);


    ob_start();
    ?>
    <div class="mmbm-map-wrap" aria-label="মুন্সিগঞ্জ জেলার মানচিত্র">
        <?php echo $svg; ?>
        <div class="mmbm-tooltip" role="tooltip" aria-hidden="true"></div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('munshiganj_map', 'mmbm_shortcode');
