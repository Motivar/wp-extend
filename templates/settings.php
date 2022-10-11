<?php
if (!defined('ABSPATH')) {
    exit;
}
global $pagenow;
$page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';
$allWpMeta = new AWM_Meta();
$options = $allWpMeta->options_boxes();
if (!empty($page)) {
    $awm_settings = $options[$page];
    $awm_settings['id'] = $page;
    $custom_link = '';
    if (isset($awm_settings['parent']) && strpos($awm_settings['parent'], $pagenow) !== false) {
        $custom_link = $pagenow;
        if (isset($_REQUEST['post_type'])) {
            $custom_link .= '?post_type=' . $_REQUEST['post_type'];
        }
    }
    $awm_settings['library'] = awm_callback_library(awm_callback_library_options($awm_settings), $awm_settings['id']);
    $form_id = 'awm-form-' . $awm_settings['id'];
    if ($pagenow == 'admin.php' || $pagenow == 'options-general.php' || $custom_link == $awm_settings['parent']) { ?>
        <div class="wrap awm-settings-form" id="<?php echo $awm_settings['id']; ?>">
            <h2><?php echo $awm_settings['title']; ?></h2>
            <?php echo awm_show_explanation($awm_settings); ?>
            <?php settings_errors(); ?>
            <form method="post" action="options.php" id="<?php echo $form_id; ?>" class="awm-form">
                <?php
                if (isset($awm_settings['library']) && !empty($awm_settings['library'])) {
                    settings_fields($awm_settings['id']);
                    do_settings_sections($awm_settings['id']);
                    $options = $awm_settings['library'];
                    $settings = array('awm-id' => $awm_settings['id']);
                    foreach ($options as $key => $data) {
                        $data['id'] = $key;
                        if (!isset($data['attributes']['value'])) {
                            $value = get_option($key);
                            $data['attributes']['value'] = apply_filters('awm_settings_page_value', $value, $data, $awm_settings);
                        }
                        $settings[$key] = $data;
                    }
                    echo awm_show_content($settings);
                    echo '<input type="hidden" name="awm_metabox[]" value="' . $awm_settings['id'] . '"/>';
                    echo '<input type="hidden" name="awm_user_id" value="' . get_current_user_id() . '">';
                    echo '<input type="hidden" name="awm_metabox_case" value="option"/>';
                    do_action('amw_options_page_hidden_values', $awm_settings);
                ?>
                    <?php
                    if (!isset($awm_settings['hide_submit']) || !$awm_settings['hide_submit']) {
                        $label = (isset($awm_settings['submit_label']) && !empty($awm_settings['submit_label'])) ? $awm_settings['submit_label'] : '';

                    ?>
                        <div class="awm-form-submit-area">
                            <?php submit_button($label); ?>
                        </div>

                <?php
                    }
                }
                ?>
            </form>
        </div>
        <?php
        if (isset($awm_settings['rest']) && isset($awm_settings['rest']['endpoint'])) {
            $method = (isset($awm_settings['rest']['method']) && !empty($awm_settings['rest']['method'])) ? $awm_settings['rest']['method'] : 'get';
            $callback = (isset($awm_settings['rest']['js_callback']) && !empty($awm_settings['rest']['js_callback'])) ? $awm_settings['rest']['js_callback'] : 'awm_rest_options_callback';
            $button = (isset($awm_settings['rest']['button']) && !empty($awm_settings['rest']['button'])) ? $awm_settings['rest']['button'] : __('Show results', 'filox');
            $namespace = isset($awm_settings['rest']['namespace']) ? $awm_settings['rest']['namespace'] : 'awm-dynamic-api/v1';
        ?>
            <div class="awm-rest-actions-wrap wrap">
                <div><button onclick="awm_options_rest_call('<?php echo $form_id; ?>','<?php echo $namespace . $awm_settings['rest']['endpoint']; ?>','<?php echo $callback; ?>','<?php echo $method; ?>')"><?php echo $button; ?></button></div>
                <div id="awm-rest-options-results"></div>
            </div>
        <?php
        } ?>
<?php
    }
}
?>