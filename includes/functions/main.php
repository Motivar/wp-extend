<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('awm_options_callback')) {
    /**
     * add settings page for custom options
     */
    function awm_options_callback()
    {
        echo awm_parse_template(awm_path . 'templates/settings.php');
    }
}

if (!function_exists('ewp_median')) {
    /*get the median price*/
    function ewp_median($numbers = array())
    {
        if (!is_array($numbers))
            $numbers = func_get_args();

        rsort($numbers);
        $mid = (count($numbers) / 2);
        return ($mid % 2 != 0) ? $numbers[$mid - 1] : (($numbers[$mid - 1]) + $numbers[$mid]) / 2;
    }
}


if (!function_exists('awm_parse_template')) {
    /**
     * with this function we get the content of php templates
     * @param $file string the path to the file
     * @param $variables the variables of the input field
     * 
     * @return string $content 
     */
    function awm_parse_template($file, $variables = array())
    {
        $location_file = apply_filters('awm_parse_template_location', $file,$variables);
        if (!file_exists($location_file)) {
            return '';
        }
        ob_start();
        include $location_file;
        $content = ob_get_contents();
        ob_end_clean();
        return $content;
    }
}


if (!function_exists('awmTaxObjectsForInput')) {
    /**
     * function to get all the post type objects
     * @param array $args array for the get_posts function
     */
    function awmTaxObjectsForInput($args)
    {

        $taxonomies = get_taxonomies($args, 'objects');
        $labels = array();
        foreach ($taxonomies as $post) {
            $labels[$post->name] = array('label' => $post->label);
        }
        return $labels;
    }
}

if (!function_exists('awmPostObjectsForInput')) {
    /**
     * function to get all the post type objects
     * @param array $args array for the get_posts function
     */
    function awmPostObjectsForInput($args)
    {
        $post_types = get_post_types($args, 'objects');
        $labels = array();
        foreach ($post_types as $post) {
            $labels[$post->name] = array('label' => $post->label);
        }
        return $labels;
    }
}


if (!function_exists('awmEwpContentForInput')) {
    function awmEwpContentForInput($content_type = '', $args = array())
    {
        $data = awm_get_db_content($content_type, $args);
        $labels = array();
        foreach ($data as $data_value) {
            $labels[$data_value['content_id']] = array('label' => $data_value['content_title']);
        }
        return $labels;
    }
}


/**
 * function to get the posts of a post type
 * @param string $postType wordpres post type / custom post type
 * @param int $number the number of posts to show
 * @param array $args array for the get_posts function
 *
 */
if (!function_exists('awmPostFieldsForInput')) {
    function awmPostFieldsForInput($postType = '', $number = '-1', $args = array())
    {
        $options = $defaultArgs = array();
        if (!empty($postType)) {
            if (!is_array($postType)) {
                $postType = array($postType);
            }
            foreach ($postType as $currentPostType) {
                $defaultArgs = array(
                    'post_type' => $currentPostType,
                    'numberposts' => $number,
                    'status' => 'publish',
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'suppress_filters' => false
                );
                if (!empty($args)) {
                    foreach ($args as $argKey => $argValue) {
                        $defaultArgs[$argKey] = $argValue;
                    }
                }
                $content = get_posts($defaultArgs);
                if (!empty($content)) {
                    foreach ($content as $data) {
                        $options[$data->ID] = array('label' => $data->post_title);
                    }
                }
            }
        }
        return apply_filters('awmPostFieldsForInput_filter', $options, $postType, $number, $defaultArgs);
    }
}


/**
 * function to get the posts of a post type
 * @param string $taxonomy wordpres taxonomy name
 * @param int $number the number of posts to show
 * @param string $option_key which key to bring back to the option value
 * @param array $args array for the get_posts function
 * @param string $awm_id the id of the library
 * @param bool $show_all if we want to show the option all
 * 
 */
if (!function_exists('awmTaxonomyFieldsForInput')) {
    function awmTaxonomyFieldsForInput($taxonomy = '', $number = '-1', $args = array(), $option_key = 'term_id', $awm_id = '', $show_all = false)
    {
        $options = array();
        $showed_all = false;
        $defaultArgs = array(
            'taxonomy'      => $taxonomy, // taxonomy name
            'orderby'       => 'name',
            'order'         => 'ASC',
            'hide_empty'    => false,
            'fields'        => 'all',
            'suppress_filters' => false,
        );
        if (!empty($args)) {
            foreach ($args as $argKey => $argValue) {
                $defaultArgs[$argKey] = $argValue;
            }
        }
        $content = get_terms($defaultArgs);
        if (!empty($content) && !is_wp_error($content)) {
            foreach ($content as $data) {
                if ($show_all && !$showed_all) {
                    $options[''] = array('label' => __('All', 'extend-wp'));
                    $showed_all = true;
                }
                $options[$data->{$option_key}] = array('label' => $data->name);
            }
        }
        return apply_filters('awmTaxonomyFieldsForInput_filter', $options, $taxonomy, $number, $defaultArgs, $awm_id);
    }
}



/**
 * function to get the posts of a post type
 * @param string $roles wordpres user roles
 * @param int $number the number of users to show
 * @param array $args array for the get_posts function
 * 
 */
if (!function_exists('awmUserFieldsForInput')) {
    function awmUserFieldsForInput($roles = array(), $number = '-1', $args = array())
    {
        $options = array();
        $defaultArgs = array(
            'orderby' => 'display_name',
            'order' => 'ASC'
        );
        if (!empty($args)) {
            foreach ($args as $argKey => $argValue) {
                $defaultArgs[$argKey] = $argValue;
            }
        }
        if (empty($roles)) {
            $roles = array('administrator');
        }
        foreach ($roles as $role) {
            $defaultArgs['role'] = $role;
            $content = get_users($defaultArgs);
            if (!empty($content)) {
                foreach ($content as $data) {
                    $options[$data->ID] = array('label' => $data->display_name);
                }
            }
        }
        return apply_filters('awmUserFieldsForInput_filter', $options, $roles, $number, $defaultArgs);
    }
}


/**
 * function to get the posts of a post type
 * @param string $roles wordpres user roles
 * @param int $number the number of users to show
 * @param array $args array for the get_posts function
 * 
 */
if (!function_exists('awmUserRolesFieldsForInput')) {
    function awmUserRolesFieldsForInput($exclude = array())
    {
        $options = array();
        $editable_roles = get_editable_roles();
        foreach ($editable_roles as $role => $details) {
            if (!in_array($role, $exclude)) {
                $options[$role] = array('label' => __($details['name'], 'extend-wp'));
            }
        }
        return apply_filters('awmUserRolesFieldsForInput', $options);
    }
}



if (!function_exists('awm_get_metabox_info')) {
    /**
     * get all the details related to a metabox for all the object types
     * @param string $id the id of the object
     * @param string $case the name of the type of the object
     * @param string $content_type the content type to search for
     */
    function awm_get_metabox_info($id, $case, $content_type = '')
    {

        if (($id != '' || $content_type != '') && $case) {
            $metas = new AWM_Meta();
            $metaBoxes = array();
            switch ($case) {
                case 'post':
                case 'post_type':
                    $metaBoxes = $metas->meta_boxes();

                    break;
                case 'user':
                    $metaBoxes = $metas->user_boxes();
                    break;
                case 'term':
                case 'taxonomy':
                    $metaBoxes = $metas->term_meta_boxes();
                    break;
                case 'option':
                    $metaBoxes = $metas->options_boxes();
                    break;
            }

            if ($id != '') {
                if (isset($metaBoxes[$id])) {
                    $metaBoxes[$id]['library'] = awm_callback_library(awm_callback_library_options($metaBoxes[$id]), $id);
                    return $metaBoxes[$id];
                };
            }
            if ($content_type != '') {
                $allMetaBoxes = array();
                foreach ($metaBoxes as $metabox_id => $metabox_data) {
                    switch ($case) {
                        case 'post':
                        case 'post_type':
                            if (in_array($content_type, $metabox_data['postTypes'])) {
                                $allMetaBoxes[$metabox_id] = $metabox_data;
                                $allMetaBoxes[$metabox_id]['library'] = awm_callback_library(awm_callback_library_options($metabox_data), $id);
                            }
                            break;
                        case 'term':
                        case 'taxonomy':
                            if (in_array($content_type, $metabox_data['taxonomies'])) {
                                $allMetaBoxes[$metabox_id] = $metabox_data;
                                $allMetaBoxes[$metabox_id]['library'] = awm_callback_library(awm_callback_library_options($metabox_data), $id);
                            }
                            break;
                    }
                }
                return $allMetaBoxes;
            }
        }
        return array();
    }
}



if (!function_exists('awm_show_featured_image')) {
    function awm_show_featured_image($post_ID)
    {
        $post_thumbnail_id = get_post_thumbnail_id($post_ID);
        if ($post_thumbnail_id) {
            $post_thumbnail_img = wp_get_attachment_image_src($post_thumbnail_id, 'thumbnail');
            return '<img src="' . $post_thumbnail_img[0] . '" style="max-width:50px;height:auto;"/>';
        }
        return '';
    }
}

if (!function_exists('awm_clean_string')) {
    /**
     * with this function we allow only numbers and characters
     * @param string $string the string to clean
     */
    function awm_clean_string($string)
    {
        $string = str_replace(' ', '_', strtolower($string)); // Replaces all spaces with hyphens.

        return preg_replace('/[^ \w_]/', '', $string); // Removes special chars.
    }
}

if (!function_exists('ewp_debug')) {
    /**
     * Debug function to print arrays and objects within <pre> tags
     *
     * @param mixed $data The data to debug (array, object, string, etc.)
     * @param bool $exit Whether to exit after printing (default: false)
     */
    function ewp_debug($data, $exit = false)
    {
        echo '<pre style="background: #f4f4f4; padding: 10px; border: 1px solid #ddd; border-radius: 5px; color: #333;">';
        echo htmlspecialchars(print_r($data, true), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
        if ($exit) {
            die();
        }
    }
}