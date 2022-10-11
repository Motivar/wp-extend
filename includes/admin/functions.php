<?php

if (!defined('ABSPATH')) {
    exit;
}




/**
 * add settings page for custom options
 */
function awm_options_callback()
{
    echo awm_parse_template(awm_path . 'templates/settings.php');
}

if (!function_exists('awm_parse_template')) {
    /**
     * with this function we get the content of php templates
     * @param $file string the path to the file
     * 
     * @return $content html 
     */
    function awm_parse_template($file)
    {
        if (!file_exists($file)) {
            return '';
        }
        ob_start();
        include $file;
        $content = ob_get_contents();
        ob_end_clean();
        return $content;
    }
}
