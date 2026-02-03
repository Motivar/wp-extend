<?php

namespace EWP\TemplateSystem;

use EWP\TemplateSystem\Interfaces\Template_Parser_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Parser - Processes template files with variables
 * 
 * This class handles the parsing and rendering of template files,
 * making variables available within the template scope.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_Parser implements Template_Parser_Interface
{
    /**
     * Template data for current parsing
     * 
     * @var array
     */
    private $template_data = array();

    /**
     * Parse template file with variables
     * 
     * @param string $template_path Template file path
     * @param array $variables Template variables
     * @return string Rendered template content
     */
    public function parse_template($template_path, $variables)
    {
        if (!$this->validate_template($template_path)) {
            throw new \Exception("Invalid template file: {$template_path}");
        }
        
        // Set template data
        $this->set_template_data($variables);
        
        // Extract variables to local scope
        extract($variables, EXTR_SKIP);
        
        // Set global variable for backward compatibility
        global $ewp_input_vars;
        $ewp_input_vars = $variables;
        
        // Capture template output
        ob_start();
        
        try {
            include $template_path;
            $content = ob_get_contents();
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }
        
        ob_end_clean();
        
        /**
         * Filter parsed template content
         * 
         * @param string $content Rendered template content
         * @param string $template_path Template file path
         * @param array $variables Template variables
         */
        return apply_filters('awm_template_parsed_content', $content, $template_path, $variables);
    }

    /**
     * Set template data
     * 
     * @param array $data Template data
     * @return self
     */
    public function set_template_data($data)
    {
        $this->template_data = $data;
        return $this;
    }

    /**
     * Get template data
     * 
     * @return array Template data
     */
    public function get_template_data()
    {
        return $this->template_data;
    }

    /**
     * Validate template file
     * 
     * @param string $template_path Template file path
     * @return bool True if valid
     */
    public function validate_template($template_path)
    {
        // Check if file exists
        if (!file_exists($template_path)) {
            return false;
        }
        
        // Check if file is readable
        if (!is_readable($template_path)) {
            return false;
        }
        
        // Check file extension
        $extension = pathinfo($template_path, PATHINFO_EXTENSION);
        if ($extension !== 'php') {
            return false;
        }
        
        // Security check: ensure file is within allowed directories
        $real_path = realpath($template_path);
        $allowed_paths = $this->get_allowed_template_paths();
        
        foreach ($allowed_paths as $allowed_path) {
            if (strpos($real_path, realpath($allowed_path)) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get allowed template paths for security
     * 
     * @return array Allowed template directory paths
     */
    private function get_allowed_template_paths()
    {
        $paths = array();
        
        // Plugin template directory
        $plugin_path = defined('awm_path') ? awm_path : plugin_dir_path(__FILE__) . '../../../';
        $paths[] = $plugin_path . 'templates/';
        
        // Theme template directories
        $paths[] = get_template_directory() . '/extend-wp/';
        
        if (is_child_theme()) {
            $paths[] = get_stylesheet_directory() . '/extend-wp/';
        }
        
        /**
         * Filter allowed template paths
         * 
         * @param array $paths Allowed template directory paths
         */
        return apply_filters('awm_template_allowed_paths', $paths);
    }
}