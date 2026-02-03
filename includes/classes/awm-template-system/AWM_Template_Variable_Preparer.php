<?php

namespace EWP\TemplateSystem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Variable Preparer - Prepares variables for template rendering
 * 
 * This class handles the preparation of template variables from field
 * configuration, including generating input names, IDs, and HTML attributes.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_Variable_Preparer
{
    /**
     * Prepare variables for template rendering
     * 
     * @param array $field_config Field configuration
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return array Prepared template variables
     */
    public function prepare_variables($field_config, $value, $context)
    {
        $variables = array();
        
        // Core field information
        $variables['field'] = $field_config;
        $variables['context'] = $context;
        $variables['value'] = $value;
        
        // Generate input attributes
        $variables['input_name'] = $this->generate_input_name($field_config, $context);
        $variables['input_id'] = $this->generate_input_id($field_config, $context);
        
        // Generate HTML components
        $variables['label_html'] = $this->render_label($field_config, $variables['input_id']);
        $variables['explanation_html'] = $this->render_explanation($field_config);
        $variables['attributes_string'] = $this->render_attributes($field_config);
        
        // CSS classes
        $variables['css_classes'] = $this->prepare_css_classes($field_config, $context);
        
        // Type-specific variables
        $variables = array_merge($variables, $this->prepare_type_specific_variables($field_config, $value, $context));
        
        /**
         * Filter prepared template variables
         * 
         * @param array $variables Prepared variables
         * @param array $field_config Field configuration
         * @param mixed $value Field value
         * @param array $context Rendering context
         */
        return apply_filters('awm_template_prepared_variables', $variables, $field_config, $value, $context);
    }

    /**
     * Generate input name attribute
     * 
     * @param array $field_config Field configuration
     * @param array $context Rendering context
     * @return string Input name
     */
    public function generate_input_name($field_config, $context)
    {
        $name = $context['original_meta'] ?? $field_config['name'] ?? '';
        
        // Handle array inputs
        if (isset($field_config['case']) && $field_config['case'] === 'checkbox_multiple') {
            $name .= '[]';
        } elseif (isset($field_config['attributes']['multiple']) && $field_config['attributes']['multiple']) {
            $name .= '[]';
        }
        
        return $name;
    }

    /**
     * Generate input ID attribute
     * 
     * @param array $field_config Field configuration
     * @param array $context Rendering context
     * @return string Input ID
     */
    public function generate_input_id($field_config, $context)
    {
        // Use custom ID if provided
        if (isset($field_config['attributes']['id'])) {
            return $field_config['attributes']['id'];
        }
        
        // Generate ID from name
        $id = $context['original_meta_id'] ?? $context['original_meta'] ?? $field_config['name'] ?? '';
        
        // Sanitize ID
        $id = sanitize_html_class($id);
        
        return $id;
    }

    /**
     * Render label HTML
     * 
     * @param array $field_config Field configuration
     * @param string $input_id Input ID for label association
     * @return string Label HTML
     */
    public function render_label($field_config, $input_id)
    {
        // Check if label should be hidden
        if (isset($field_config['hide-label']) && $field_config['hide-label']) {
            return '';
        }
        
        // Skip label for certain input types
        $skip_label_types = array('checkbox_multiple', 'repeater', 'awm_tab', 'button', 'hidden');
        if (in_array($field_config['case'] ?? '', $skip_label_types)) {
            return '';
        }
        
        // Skip label for certain input subtypes
        if (isset($field_config['type']) && in_array($field_config['type'], array('submit', 'hidden', 'button'))) {
            return '';
        }
        
        $label = $field_config['label'] ?? '';
        if (empty($label)) {
            return '';
        }
        
        $label_html = sprintf(
            '<label for="%s" class="awm-input-label"><span>%s</span></label>',
            esc_attr($input_id),
            esc_html($label)
        );
        
        return $label_html;
    }

    /**
     * Render explanation HTML
     * 
     * @param array $field_config Field configuration
     * @return string Explanation HTML
     */
    public function render_explanation($field_config)
    {
        $explanation = $field_config['explanation'] ?? '';
        
        if (empty($explanation)) {
            return '';
        }
        
        return sprintf(
            '<span class="awm-explanation">%s</span>',
            esc_html(__($explanation, 'extend-wp'))
        );
    }

    /**
     * Render HTML attributes string
     * 
     * @param array $field_config Field configuration
     * @return string Attributes string
     */
    public function render_attributes($field_config)
    {
        $attributes = $field_config['attributes'] ?? array();
        $attribute_strings = array();
        
        foreach ($attributes as $key => $value) {
            // Skip certain attributes that are handled separately
            if (in_array($key, array('id', 'name', 'class', 'value'))) {
                continue;
            }
            
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            
            // Translate placeholder text
            if ($key === 'placeholder') {
                $value = __($value, 'extend-wp');
            }
            
            $attribute_strings[] = sprintf('%s="%s"', esc_attr($key), esc_attr($value));
        }
        
        return implode(' ', $attribute_strings);
    }

    /**
     * Prepare CSS classes array
     * 
     * @param array $field_config Field configuration
     * @param array $context Rendering context
     * @return array CSS classes
     */
    public function prepare_css_classes($field_config, $context)
    {
        $classes = array();
        
        // Add base classes
        $classes[] = 'awm-meta-field';
        $classes[] = $field_config['case'] ?? '';
        
        if (isset($field_config['type'])) {
            $classes[] = $field_config['type'];
        }
        
        // Add custom classes
        if (isset($field_config['class']) && is_array($field_config['class'])) {
            $classes = array_merge($classes, $field_config['class']);
        } elseif (isset($field_config['class'])) {
            $classes[] = $field_config['class'];
        }
        
        // Add required class if field is required
        if (isset($field_config['required']) && $field_config['required']) {
            $classes[] = 'awm-required';
        }
        
        // Filter and sanitize classes
        $classes = array_filter($classes);
        $classes = array_map('sanitize_html_class', $classes);
        
        return $classes;
    }

    /**
     * Prepare type-specific variables
     * 
     * @param array $field_config Field configuration
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return array Type-specific variables
     */
    private function prepare_type_specific_variables($field_config, $value, $context)
    {
        $variables = array();
        $case = $field_config['case'] ?? '';
        
        switch ($case) {
            case 'select':
            case 'radio':
            case 'checkbox_multiple':
                $variables['options'] = $field_config['options'] ?? array();
                break;
                
            case 'awm_gallery':
                $variables['gallery_images'] = is_array($value) ? $value : array();
                break;
                
            case 'textarea':
                if (isset($field_config['wp_editor']) && $field_config['wp_editor']) {
                    $variables['editor_content'] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                break;
                
            case 'repeater':
                $variables['repeater_items'] = is_array($value) ? $value : array();
                $variables['repeater_config'] = $field_config['include'] ?? array();
                break;
                
            case 'map':
                if (is_array($value)) {
                    $variables['map_lat'] = $value['lat'] ?? '';
                    $variables['map_lng'] = $value['lng'] ?? '';
                    $variables['map_address'] = $value['address'] ?? '';
                } else {
                    $variables['map_lat'] = '';
                    $variables['map_lng'] = '';
                    $variables['map_address'] = '';
                }
                break;
        }
        
        return $variables;
    }
}