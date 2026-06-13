<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Elementor\Widget_Base')) {
    return;
}

class MLSP_Elementor_Widget extends \Elementor\Widget_Base {
    public function get_name() {
        return 'mobile_live_search_pro';
    }

    public function get_title() {
        return __('Mobile Live Search Pro', 'mobile-live-search-pro');
    }

    public function get_icon() {
        return 'eicon-search';
    }

    public function get_categories() {
        return array('general');
    }

    public function get_keywords() {
        return array('search', 'ajax', 'live search', 'popup', 'mobile');
    }

    public function get_style_depends() {
        return array('mlsp-style');
    }

    public function get_script_depends() {
        return array('mlsp-script');
    }

    protected function register_controls() {
        $this->start_controls_section('mlsp_content_section', array(
            'label' => __('Search Button', 'mobile-live-search-pro'),
        ));

        $this->add_control('selected_icon', array(
            'label' => __('Icon', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::ICONS,
            'default' => array(
                'value' => 'fas fa-search',
                'library' => 'fa-solid',
            ),
        ));

        $this->add_control('label', array(
            'label' => __('Button Label', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => '',
            'placeholder' => __('Search', 'mobile-live-search-pro'),
        ));

        $this->add_control('placeholder', array(
            'label' => __('Input Placeholder', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => __('Search news...', 'mobile-live-search-pro'),
        ));

        $this->add_control('min_chars', array(
            'label' => __('Minimum Characters', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 10,
            'step' => 1,
            'default' => 2,
        ));

        $this->add_control('per_page', array(
            'label' => __('Results Per Page', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 20,
            'step' => 1,
            'default' => 8,
        ));

        $post_type_options = class_exists('MLSP_Plugin') ? MLSP_Plugin::instance()->get_post_type_options() : array('post' => __('Posts', 'mobile-live-search-pro'));
        $this->add_control('post_types', array(
            'label' => __('Search Post Types', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::SELECT2,
            'multiple' => true,
            'options' => $post_type_options,
            'default' => array('post'),
        ));

        $this->end_controls_section();

        $this->start_controls_section('mlsp_icon_style_section', array(
            'label' => __('Icon / Button Style', 'mobile-live-search-pro'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $this->add_responsive_control('icon_size', array(
            'label' => __('Icon Size', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'em', 'rem'),
            'range' => array(
                'px' => array('min' => 10, 'max' => 120),
                'em' => array('min' => 0.5, 'max' => 8),
                'rem' => array('min' => 0.5, 'max' => 8),
            ),
            'default' => array('unit' => 'px', 'size' => 30),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-trigger' => '--mlsp-icon-size: {{SIZE}}{{UNIT}};',
            ),
        ));

        $this->add_control('icon_color', array(
            'label' => __('Icon Color', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#111111',
            'selectors' => array(
                '{{WRAPPER}} .mlsp-trigger' => '--mlsp-icon-color: {{VALUE}}; color: {{VALUE}};',
            ),
        ));

        $this->add_control('icon_hover_color', array(
            'label' => __('Hover Color', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-trigger:hover' => '--mlsp-icon-color: {{VALUE}}; color: {{VALUE}};',
            ),
        ));

        $this->add_control('button_background', array(
            'label' => __('Button Background', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-trigger' => 'background-color: {{VALUE}};',
            ),
        ));

        $this->add_responsive_control('button_padding', array(
            'label' => __('Button Padding', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', 'em', 'rem'),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ));

        $this->add_responsive_control('button_radius', array(
            'label' => __('Button Border Radius', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%'),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-trigger' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'label_typography',
            'label' => __('Label Typography', 'mobile-live-search-pro'),
            'selector' => '{{WRAPPER}} .mlsp-trigger-label',
        ));

        $this->end_controls_section();

        $this->start_controls_section('mlsp_popup_style_section', array(
            'label' => __('Popup Style', 'mobile-live-search-pro'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $this->add_responsive_control('popup_width', array(
            'label' => __('Popup Max Width', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'vw'),
            'range' => array(
                'px' => array('min' => 280, 'max' => 1200),
                'vw' => array('min' => 30, 'max' => 100),
            ),
            'default' => array('unit' => 'px', 'size' => 680),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-panel' => 'max-width: {{SIZE}}{{UNIT}};',
            ),
        ));

        $this->add_control('popup_background', array(
            'label' => __('Popup Background', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-panel' => 'background-color: {{VALUE}};',
            ),
        ));

        $this->add_control('overlay_color', array(
            'label' => __('Overlay Color', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => 'rgba(0,0,0,0.55)',
            'selectors' => array(
                '{{WRAPPER}} .mlsp-overlay' => 'background-color: {{VALUE}};',
            ),
        ));

        $this->add_responsive_control('popup_radius', array(
            'label' => __('Popup Border Radius', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%'),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ));

        $this->end_controls_section();

        $this->start_controls_section('mlsp_input_style_section', array(
            'label' => __('Search Input Style', 'mobile-live-search-pro'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $this->add_responsive_control('input_height', array(
            'label' => __('Input Height', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px'),
            'range' => array('px' => array('min' => 34, 'max' => 90)),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-input' => 'height: {{SIZE}}{{UNIT}};',
            ),
        ));

        $this->add_control('input_color', array(
            'label' => __('Input Text Color', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-input' => 'color: {{VALUE}};',
            ),
        ));

        $this->add_control('input_background', array(
            'label' => __('Input Background', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-input' => 'background-color: {{VALUE}};',
            ),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'input_typography',
            'label' => __('Input Typography', 'mobile-live-search-pro'),
            'selector' => '{{WRAPPER}} .mlsp-input',
        ));

        $this->end_controls_section();

        $this->start_controls_section('mlsp_result_style_section', array(
            'label' => __('Result Card Style', 'mobile-live-search-pro'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $this->add_responsive_control('thumb_width', array(
            'label' => __('Thumbnail Width', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px'),
            'range' => array('px' => array('min' => 40, 'max' => 240)),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-thumb' => 'width: {{SIZE}}{{UNIT}}; flex-basis: {{SIZE}}{{UNIT}};',
            ),
        ));

        $this->add_responsive_control('thumb_height', array(
            'label' => __('Thumbnail Height', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px'),
            'range' => array('px' => array('min' => 40, 'max' => 200)),
            'selectors' => array(
                '{{WRAPPER}} .mlsp-thumb' => 'height: {{SIZE}}{{UNIT}};',
            ),
        ));

        $this->add_control('card_background', array(
            'label' => __('Card Background', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-card' => 'background-color: {{VALUE}};',
            ),
        ));

        $this->add_control('card_hover_background', array(
            'label' => __('Card Hover Background', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-card:hover, {{WRAPPER}} .mlsp-card.mlsp-active' => 'background-color: {{VALUE}};',
            ),
        ));

        $this->add_control('title_color', array(
            'label' => __('Title Color', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-title' => 'color: {{VALUE}};',
            ),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'title_typography',
            'label' => __('Title Typography', 'mobile-live-search-pro'),
            'selector' => '{{WRAPPER}} .mlsp-title',
        ));

        $this->add_control('meta_color', array(
            'label' => __('Meta Color', 'mobile-live-search-pro'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlsp-meta' => 'color: {{VALUE}};',
            ),
        ));

        $this->end_controls_section();
    }

    protected function render() {
        if (!class_exists('MLSP_Plugin')) {
            return;
        }

        $settings = $this->get_settings_for_display();
        $icon_html = $this->get_rendered_icon($settings);

        echo MLSP_Plugin::instance()->render_search(array(
            'label' => isset($settings['label']) ? $settings['label'] : '',
            'placeholder' => isset($settings['placeholder']) ? $settings['placeholder'] : __('Search news...', 'mobile-live-search-pro'),
            'min_chars' => isset($settings['min_chars']) ? absint($settings['min_chars']) : 2,
            'per_page' => isset($settings['per_page']) ? absint($settings['per_page']) : 8,
            'post_types' => isset($settings['post_types']) ? $settings['post_types'] : array('post'),
            'icon_html' => $icon_html,
            'extra_class' => 'mlsp-elementor-widget',
        ));
    }

    private function get_rendered_icon($settings) {
        if (empty($settings['selected_icon']) || empty($settings['selected_icon']['value']) || !class_exists('\Elementor\Icons_Manager')) {
            return '';
        }

        ob_start();
        $returned = \Elementor\Icons_Manager::render_icon(
            $settings['selected_icon'],
            array('aria-hidden' => 'true', 'class' => 'mlsp-elementor-icon'),
            'span'
        );
        $printed = ob_get_clean();

        if (is_string($returned)) {
            $printed .= $returned;
        }

        return $printed;
    }
}
