<?php

if (!defined('ABSPATH')) {
    exit;
}

function awm_global_messages()
{
    do_action('awm_pre_get_all_messages');

    /*booking messages*/
    $messages = array(
        'awm_Roww' => __('Row', 'extend-wp'),
        'awm_Remove' => __('Remove', 'extend-wp'),
        'awm_Add' => __('Add', 'extend-wp'),
        'awm_Upload_image' => __('Upload image', 'extend-wp'),
        'awm_Insert_image' => __('Insert image', 'extend-wp'),
        'awm_Remove_images' => __('Remove images', 'extend-wp'),
        'awm_Yes' => __('Yes', 'extend-wp'),
        'awm_No' => __('No', 'extend-wp'),
    );

    return apply_filters('awm_global_messages_filter', $messages);
}

if (!function_exists('awm_global_messages_init')) {
    function awm_global_messages_init()
    {
        $messages = awm_global_messages();

        /*check here*/

        if (!empty($messages)) {
            foreach ($messages as $key => $value) {
                /*check if value is array*/
                if (is_array($value)) {
                    $value = serialize($value);
                }
                if (!defined($key)) {
                    define($key, $value);
                }
            }
        }
    }
}

add_action('init', 'awm_global_messages_init', 10);
add_action('admin_init', 'awm_global_messages_init', 10);
