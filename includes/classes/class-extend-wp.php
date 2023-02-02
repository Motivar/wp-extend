<?php
if (!defined('ABSPATH')) {
    exit;
}


class AWM_Meta
{
    public static $ewp_post_boxes = false;
    public static $ewp_term_boxes = false;
    public static $ewp_user_boxes = false;
    public static $ewp_options = false;

    public function init()
    {
        define('AWM_JQUERY_LOAD', apply_filters('awm_jquery_load_filter', true));
        add_action('plugins_loaded', function () {
            load_plugin_textdomain('extend-wp', false, awm_path . '/languages/');
        });
        add_action('init', array($this, 'awm_init'), 10);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles_script'), 10);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_styles_scripts'), 10);
        add_action('add_meta_boxes', array($this, 'awm_add_post_meta_boxes'), 10, 2);
        add_action('admin_init', array($this, 'awm_admin_post_columns'), 10);
        add_action('admin_init', array($this, 'awm_add_term_meta_boxes'), 10);
        add_action('admin_init', array($this, 'awm_add_user_meta_boxes'), 10);
        add_action('admin_menu', array($this, 'awm_add_options_page'), 10);
        add_action('admin_init', array($this, 'awm_register_option_settings'), 10);
        add_action('restrict_manage_posts', array($this, 'awm_add_restrict_posts_form'), 10);
        add_filter('pre_get_posts', array($this, 'awm_pre_get_posts'), 10);
        add_action(
            'save_post',
            function ($post_id) {
                if ((!wp_is_post_revision($post_id) && 'auto-draft' != get_post_status($post_id) && 'trash' != get_post_status($post_id))) {
                    if (isset($_POST['awm_custom_meta'])) {
                        awm_save_custom_meta($_POST['awm_custom_meta'], $_POST, $post_id, 'post', get_post_type($post_id));
                    }
                }
            },
            100
        );

        add_action('profile_update', 'awm_profile_update', 10, 10);
        add_action('user_register', 'awm_profile_update', 10, 10);
        function awm_profile_update($user_id)
        {
            if (isset($_POST['awm_custom_meta'])) {
                awm_save_custom_meta($_POST['awm_custom_meta'], $_POST, $user_id, 'user');
            }
        }

        add_action(
            'edit_term',
            function ($term_id, $taxonomy) {
                if (isset($_POST['awm_custom_meta'])) {
                    awm_save_custom_meta($_POST['awm_custom_meta'], $_POST, $term_id, 'term');
                }
            },
            100,
            2
        );

        add_action(
            'create_term',
            function ($term_id, $taxonomy) {
                if (isset($_POST['awm_custom_meta'])) {
                    awm_save_custom_meta($_POST['awm_custom_meta'], $_POST, $term_id, 'term');
                }
            },
            100,
            2
        );
        add_action('rest_api_init', array($this, 'awm_new_routes'), 10);
        add_action('rest_api_init', array($this, 'awm_dynamic_routes'), 10);
    }

    public function awm_dynamic_routes()
    {
        if (empty($this->options_boxes())) {
            return true;
        }
        $options_with_endpoint = array();
        foreach ($this->options_boxes() as $option_box => $option_data) {
            if (isset($option_data['rest'])) {
                $options_with_endpoint[$option_box] = $option_data['rest'];
            }
        }
        $d_api = new AWM_Dynamic_API($options_with_endpoint);
        $d_api->register_routes();
    }


    public function awm_new_routes()
    {
        $api = new AWM_API();
        $api->register_routes();
    }

    public function awm_admin_post_columns()
    {
        global $pagenow;

        switch ($pagenow) {
            case 'edit.php':
                $metaBoxes = $this->meta_boxes();
                if (!empty($metaBoxes)) {
                    foreach ($metaBoxes as $metaBoxKey => $metaBoxData) {
                        $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                        if (!empty($metaBoxData['library'])) {
                            foreach ($metaBoxData['library'] as $meta => $data) {
                                if (isset($data['admin_list']) && $data['admin_list']) {
                                    $data['key'] = $meta;
                                    foreach ($metaBoxData['postTypes'] as $postType) {
                                        if (isset($_GET['post_type']) && $_GET['post_type'] == $postType) {
                                            /*add post columns*/
                                            add_filter('manage_' . $postType . '_posts_columns', function ($columns) use ($data) {
                                                $columns[$data['key']] = $data['label'];
                                                return $columns;
                                            }, 10, 1);
                                            /*add the value of the post columns*/
                                            add_action('manage_' . $postType . '_posts_custom_column', function ($column) use ($data) {
                                                global $post;
                                                if ($data['key'] == $column) {
                                                    echo awm_display_meta_value($data['key'], $data, $post->ID);
                                                }
                                            }, 10, 1);
                                            /*add the sortables*/
                                            if (isset($data['sortable']) && $data['sortable']) {
                                                add_filter('manage_edit-' . $postType . '_sortable_columns', function ($columns) use ($data) {
                                                    $columns[$data['key']] = $data['key'] . '_ewp_sort_by_' . $data['sortable'];
                                                    return $columns;
                                                }, 10, 1);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                break;
            default:
                break;
        }
    }





    /**
     * admin enqueue scripts and styles
     */
    public function admin_enqueue_styles_scripts()
    {
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-awm');
        wp_enqueue_style('awm-slim-lib-style');
        wp_enqueue_style('awm-admin-style');
        wp_enqueue_style('awm-global-style');
        wp_enqueue_script('awm-slim-lib-script');
        wp_enqueue_script('awm-global-script');
        wp_enqueue_script('awm-admin-script');
    }

    /**
     * enquee scripts and styles
     */
    public function enqueue_styles_script()
    {
        if (AWM_JQUERY_LOAD) {
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-awm');
        }
        wp_enqueue_style('awm-global-style');
        wp_enqueue_script('awm-global-script');
        wp_enqueue_script('awm-public-script');
    }
    /**
     * init function
     */
    public function awm_init()
    {

        $this->register_script_styles();
        $this->add_nonce_action();
    }




    /**
     * private function add awm save action
     */
    private function add_nonce_action()
    {
        if (isset($_REQUEST['awm_form_nonce_field'])) {
            do_action('awm_form_action');
        }
    }

    /**
     * register scripts and styles
     */
    private function register_script_styles()
    {
        $version = 0.2;
        wp_register_style('awm-slim-lib-style', 'https://cdnjs.cloudflare.com/ajax/libs/slim-select/1.27.1/slimselect.min.css', false, $version);
        wp_register_style('awm-global-style', awm_url . 'assets/css/global/awm-global-style.min.css', false, $version);
        wp_register_style('awm-admin-style', awm_url . 'assets/css/admin/awm-admin-style.min.css', false, $version);
        wp_register_script('awm-global-script', awm_url . 'assets/js/global/awm-global-script.js', array(), $version, true);
        wp_register_script('awm-public-script', awm_url . 'assets/js/public/awm-public-script.js', array(), $version, true);
        wp_localize_script('awm-global-script', 'awmGlobals', array('url' => esc_url(home_url()), 'nonce' => wp_create_nonce('wp_rest')));
        wp_register_script('awm-admin-script', awm_url . 'assets/js/admin/awm-admin-script.js', array(), $version, true);
        wp_register_script('awm-slim-lib-script', 'https://cdnjs.cloudflare.com/ajax/libs/slim-select/1.27.1/slimselect.min.js', array(), $version, true);
        wp_register_style('jquery-ui-awm', apply_filters('jquery_ui_awm_filter', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css'));
    }


    /**
     * this function is responsible for the admin pre get posts based on the awm restrict manage posts
     */
    public function awm_pre_get_posts($query)
    {
        global $pagenow;

        if (!is_admin()) {
            return;
        }

        if (isset($_REQUEST['awm_restict_post_list']) && !empty($_REQUEST['awm_restict_post_list'])) {
            $lists = $_REQUEST['awm_restict_post_list'];
            $registered = $this->restrict_post_forms();
            if ($query->is_main_query() && is_admin() && $pagenow == 'edit.php') {
                foreach ($lists as $list) {
                    if (isset($registered[$list])) {
                        if (isset($registered[$list]['callback']) && function_exists($registered[$list]['callback'])) {
                            $query = call_user_func_array($registered[$list]['callback'], array($query));
                        }
                    }
                }
            }
        }


        /*check order by*/
        $orderby = $query->get('orderby') ?: '';

        if (strpos($orderby, '_ewp_sort_by_') !== false) {
            $awm_info = explode('_ewp_sort_by_', $orderby);
            $meta = $awm_info[0];
            $type = $awm_info[1];
            $query->set('meta_key', $meta);
            $query->set('orderby', $type);
        }

        return $query;
    }

    /**
     * 
     */


    /**
     * get all the registered options pages
     *
     * @return array
     */
    public function options_boxes()
    {
        if (self::$ewp_options !== false) {
            return self::$ewp_options;
        }
        $optionsPages = apply_filters('awm_add_options_boxes_filter', array());
        /**
         * sort settings by order
         */
        if (!empty($optionsPages)) {
            uasort($optionsPages, function ($a, $b) {
                $first = isset($a['order']) ? $a['order'] : 100;
                $second = isset($b['order']) ? $b['order'] : 100;
                return $first - $second;
            });
        }


        self::$ewp_options = $optionsPages;

        return $optionsPages;
    }


    /**
     * Get post types for this meta box.
     *
     * @return array
     */
    public function meta_boxes()
    {


        if (self::$ewp_post_boxes !== false) {
            return self::$ewp_post_boxes;
        }
        $metaBoxes = apply_filters('awm_add_meta_boxes_filter', array(), 1);
        /**
         * sort settings by order
         */
        uasort($metaBoxes, function ($a, $b) {
            $first = isset($a['order']) ? $a['order'] : 100;
            $second = isset($b['order']) ? $b['order'] : 100;
            return $first - $second;
        });
        self::$ewp_post_boxes = $metaBoxes;

        return $metaBoxes;
    }

    /**
     * Get post types for this meta box.
     *
     * @return array
     */
    public function term_meta_boxes()
    {
        if (self::$ewp_term_boxes !== false) {
            return self::$ewp_term_boxes;
        }
        $boxes = apply_filters('awm_add_term_meta_boxes_filter', array());
        uasort($boxes, function ($a, $b) {
            $first = isset($a['order']) ? $a['order'] : 100;
            $second = isset($b['order']) ? $b['order'] : 100;
            return $first - $second;
        });
        self::$ewp_term_boxes = $boxes;
        return $boxes;
    }

    /**
     * get all the restict post forms
     */
    protected function restrict_post_forms()
    {
        $restrict_forms = apply_filters('awm_restrict_post_boxes_filter', array());
        /**
         * sort settings by order
         */

        uasort($restrict_forms, function ($a, $b) {
            $first = isset($a['order']) ? $a['order'] : 100;
            $second = isset($b['order']) ? $b['order'] : 100;
            return $first - $second;
        });

        return $restrict_forms;
    }


    /**
     * get all the forms added to certain post types via awm
     */
    public function awm_add_restrict_posts_form()
    {
        $restrict_post_forms = $this->restrict_post_forms();
        if (!empty($restrict_post_forms)) {

            $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : 'post';

            foreach ($restrict_post_forms as $optionKey => $optionData) {
                if (in_array($post_type, $optionData['postTypes']) && !empty($optionData['library'])) {
                    $library = array();
                    foreach ($optionData['library'] as $key => $data) {
                        $library[$key] = $data;
                        $library[$key]['exclude_meta'] = true;
                    }
                    $library['awm-id'] = $optionKey;
                    $library['awm_restict_post_list[]'] = array('case' => 'input', 'type' => 'hidden', 'exclude_meta' => true, 'attributes' => array('value' => $optionKey));

                    echo awm_show_content($library, 0, 'restrict_manage_posts');
                }
            }
        }
    }

    /**
     * register settings for the options
     */
    public function awm_register_option_settings()
    {
        $optionsPages = $this->options_boxes();
        if (!empty($optionsPages)) {


            foreach ($optionsPages as $optionKey => $optionData) {
                if ((isset($optionData['library']) && !empty($optionData['library'])) || (isset($optionData['callback']) && !empty($optionData['callback']))) {
                    $args = array();
                    $options = awm_callback_library(awm_callback_library_options($optionData), $optionKey);
                    if (!isset($optionData['disable_register']) || !$optionData['disable_register']) {
                        foreach ($options as $key => $data) {
                            register_setting($optionKey, $key, $args);
                        }
                        add_filter('option_page_capability_' . $optionKey, function () {
                            return 'edit_posts';
                        });
                    }
                }
            }
        }
    }

    /**
     * add options pages
     */
    public function awm_add_options_page()
    {
        global $pagenow;
        $optionsPages = $this->options_boxes();

        if (!empty($optionsPages)) {
            foreach ($optionsPages as $optionKey => $optionData) {
                $optionData['id'] = $optionKey;
                $parent = isset($optionData['parent']) ? $optionData['parent'] : 'options-general.php';
                $icon = isset($optionData['icon']) ? $optionData['icon'] : '';
                $cap = (isset($optionData['cap']) && !empty($optionData['cap'])) ? $optionData['cap'] : 'manage_options';
                $callback = isset($optionData['ext_callback']) ? $optionData['ext_callback'] : 'awm_options_callback';
                global $awm_settings;
                $awm_settings = $optionData;
                if ($parent) {
                    add_submenu_page($parent, ucwords($optionData['title']), ucwords($optionData['title']), $cap, $optionKey, $callback);
                    continue;
                }
                add_menu_page(
                    ucwords($optionData['title']),
                    ucwords($optionData['title']),
                    $cap,
                    $optionKey,
                    $callback,
                    $icon

                );
            }
        }
    }




    /**
     * add term meta boxes to taxonomies
     */
    public function awm_add_term_meta_boxes()
    {
        global $pagenow;
        if (in_array($pagenow, array('edit-tags.php', 'term.php'))) {
            $metaBoxes = $this->term_meta_boxes();
            if (!empty($metaBoxes)) {
                /**
                 * sort settings by order
                 */
                uasort($metaBoxes, function ($a, $b) {
                    $first = isset($a['order']) ? $a['order'] : 100;
                    $second = isset($b['order']) ? $b['order'] : 100;
                    return $first - $second;
                });
                foreach ($metaBoxes as $metaBoxKey => $metaBoxData) {
                    $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                    if (isset($metaBoxData['library']) && !empty($metaBoxData['library']) && isset($metaBoxData['taxonomies'])) {
                        $metaBoxData['id'] = $metaBoxKey;
                        foreach ($metaBoxData['taxonomies'] as $taxonomy) {
                            if (isset($_REQUEST['taxonomy']) && $_REQUEST['taxonomy'] == $taxonomy) {
                                add_action($taxonomy . '_add_form_fields', function ($term) use ($metaBoxData) {
                                    $metas = awm_show_content($metaBoxData['library'], 0, 'term');
                                    if (!empty($metas)) {
                                        echo '<div class="awm-term-meta-boxes" id="awm-table-' . $metaBoxData['id'] . '">';
                                        echo '<h2>' . $metaBoxData['title'] . '</h2>';
                                        echo awm_show_explanation($metaBoxData);
                                        echo $metas;
                                        echo '<input type="hidden" name="awm_metabox[]" value="' . $metaBoxData['id'] . '"/>';
                                        echo '<input type="hidden" name="awm_metabox_case" value="term"/>';
                                        echo '</div>';
                                    }
                                });
                                add_action($taxonomy . '_edit_form_fields', function ($term) use ($metaBoxData) {
                                    $metas = awm_show_content($metaBoxData['library'], $term->term_id, 'term');
                                    if (!empty($metas)) {
                                        echo '<tr><td colspan="2"><h2>' . $metaBoxData['title'] . '</h2>' . awm_show_explanation($metaBoxData) . '</td></tr>';
                                        echo $metas;
                                        echo '<input type="hidden" name="awm_metabox[]" value="' . $metaBoxData['id'] . '"/>';
                                        echo '<input type="hidden" name="awm_metabox_case" value="term"/>';
                                    }
                                });
                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * Get all user boxes across the plugin
     *
     * @return array
     */
    public function user_boxes()
    {

        if (self::$ewp_user_boxes !== false) {
            return self::$ewp_user_boxes;
        }
        $user_boxes = apply_filters('awm_add_user_boxes_filter', array(), 1);
        /**
         * sort settings by order
         */
        uasort($user_boxes, function ($a, $b) {
            $first = isset($a['order']) ? $a['order'] : 100;
            $second = isset($b['order']) ? $b['order'] : 100;
            return $first - $second;
        });
        self::$ewp_user_boxes = $user_boxes;
        return $user_boxes;
    }

    /**
     * with this function we set the user boxes in the various profile checks
     */
    public function awm_add_user_meta_boxes()
    {
        $metaBoxes = $this->user_boxes();

        if (!empty($metaBoxes)) {
            foreach ($metaBoxes as $metaBoxKey => $metaBoxData) { {
                    $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                    if (!empty($metaBoxData['library'])) {
                        $metaBoxData['id'] = $metaBoxKey;
                        $fields = function ($user) use ($metaBoxData) {
                            $user_id = isset($user->ID) ? $user->ID : 0;
                            $metaBoxData['library']['awm-id'] = $metaBoxData['id'];
                            echo '<div class="awm-user-fields" id="awm-table-' . $metaBoxData['id'] . '">';
                            echo '<h2>' . $metaBoxData['title'] . '</h2>';
                            echo awm_show_explanation($metaBoxData);
                            echo '<table class="form-table" >' . awm_show_content($metaBoxData['library'], $user_id, 'user') . '</table>';
                            echo '<input type="hidden" name="awm_metabox[]" value="' . $metaBoxData['id'] . '"/>';
                            echo '<input type="hidden" name="awm_metabox_case" value="user"/>';
                            echo '</div>';
                        };
                        add_action('show_user_profile', $fields);
                        add_action('edit_user_profile', $fields);
                        add_action('user_new_form', $fields);
                    }
                }
            }
        }
    }


    /**
     * add metaboxes to the admin
     * @param array $postType all the post type sto show the post box
     * @param object $post the post object
     */
    public function awm_add_post_meta_boxes($postType, $post)
    {

        $metaBoxes = $this->meta_boxes();

        if (!empty($metaBoxes)) {
            foreach ($metaBoxes as $metaBoxKey => $metaBoxData) {
                if (in_array($postType, $metaBoxData['postTypes'])) {

                    $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                    if (!empty($metaBoxData['library'])) {
                        $metaBoxData['post'] = $post;
                        $metaBoxData['id'] = $metaBoxKey;
                        $metaBoxData['library']['awm-id'] = $metaBoxData['id'];
                        $view = isset($metaBoxData['view']) ? $metaBoxData['view'] : 'post';
                        $metas = apply_filters('awm_add_meta_boxes_filter_content', awm_show_content($metaBoxData['library'], $metaBoxData['post']->ID, $view), $metaBoxData['id']);
                        $metaBoxData['metas'] = $metas;
                        if (!empty($metas)) {
                            add_meta_box(
                                $metaBoxKey,
                                $metaBoxData['title'], // $title
                                function () use ($metaBoxData) {

                                    echo awm_show_explanation($metaBoxData);
                                    echo $metaBoxData['metas'];
                                    echo '<input type="hidden" name="awm_metabox[]" value="' . $metaBoxData['id'] . '"/>';
                                    echo '<input type="hidden" name="awm_metabox_case" value="post"/>';
                                },
                                $metaBoxData['postTypes'], // $page
                                $metaBoxData['context'], // $context
                                $metaBoxData['priority'] // $priority
                            );
                        }
                    }
                }
            }
        }
    }
}






$metas = new AWM_Meta();
$metas->init();
