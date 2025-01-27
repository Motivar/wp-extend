<?php
if (!defined('ABSPATH')) {
    // Exit if accessed directly, ensuring this script is only executed within WordPress.
    exit;
}

// Global variable to check the current page being accessed in the admin area.
global $pagenow;

// Get the 'page' parameter from the request, if available.
$page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';

// Initialize the custom AWM_Meta class and retrieve the options settings.
$allWpMeta = new AWM_Meta();
$options = $allWpMeta->options_boxes();

// Check if a valid 'page' parameter exists.
if (!empty($page)) {
    // Get settings for the current page.
    $awm_settings = $options[$page];
    $awm_settings['id'] = $page;

    $custom_link = '';

    // Check if the current page matches the parent setting.
    if (isset($awm_settings['parent']) && strpos($awm_settings['parent'], $pagenow) !== false) {
        $custom_link = $pagenow;
        // Append the 'post_type' parameter if it exists in the request.
        if (isset($_REQUEST['post_type'])) {
            $custom_link .= '?post_type=' . $_REQUEST['post_type'];
        }
    }

    // Load the library data for the current settings.
    $awm_settings['library'] = awm_callback_library(awm_callback_library_options($awm_settings), $awm_settings['id']);

    // Set a unique form ID for this settings page.
    $form_id = 'awm-form-' . $awm_settings['id'];

    // Check if the current admin page matches the criteria to display the settings form.
    if ($pagenow == 'admin.php' || $pagenow == 'options-general.php' || $custom_link == $awm_settings['parent']) { ?>
<!-- Start of the settings form wrapper -->
<div class="wrap awm-settings-form" id="<?php echo $awm_settings['id']; ?>">
 <h2><?php echo $awm_settings['title']; ?></h2> <!-- Display the page title -->
 <?php echo awm_show_explanation($awm_settings); ?>
 <!-- Display an explanation or description -->
 <?php settings_errors(); ?>
 <!-- Show any settings errors -->

 <!-- Start of the settings form -->
 <form method="post" action="options.php" id="<?php echo $form_id; ?>" class="awm-form">
  <?php
                // Check if there is a library associated with the current settings page.
                if (isset($awm_settings['library']) && !empty($awm_settings['library'])) {
                    settings_fields($awm_settings['id']); // Output hidden form fields for settings API.
                    do_settings_sections($awm_settings['id']); // Display the settings sections for this page.

                    // Retrieve the library options for this page and initialize settings array.
                    $options = $awm_settings['library'];
                    $settings = array('awm-id' => $awm_settings['id']);

                    // Iterate through each option in the library and prepare it for display.
                    foreach ($options as $key => $data) {
                        $data['id'] = $key;

                        // Check if the value is already set; otherwise, get it from WordPress options.
                        if (!isset($data['attributes']['value'])) {
                            $value = get_option($key);
                            $data['attributes']['value'] = apply_filters('awm_settings_page_value', $value, $data, $awm_settings);
                        }
                        $settings[$key] = $data; // Add the option to the settings array.
                    }

                    // Render the settings content.
                    echo awm_show_content($settings);

                    // Add hidden input fields to store metadata for the form.
                    echo '<input type="hidden" name="awm_metabox[]" value="' . $awm_settings['id'] . '"/>';
                    echo '<input type="hidden" name="awm_user_id" value="' . get_current_user_id() . '">';
                    echo '<input type="hidden" name="awm_metabox_case" value="option"/>';
                    do_action('amw_options_page_hidden_values', $awm_settings); // Hook for additional hidden values.
                ?>
  <?php
                    // Check if the submit button should be displayed.
                    if (!isset($awm_settings['hide_submit']) || !$awm_settings['hide_submit']) {
                        $label = (isset($awm_settings['submit_label']) && !empty($awm_settings['submit_label'])) ? $awm_settings['submit_label'] : '';
                    ?>
  <!-- Submit button for the settings form -->
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
        // Check if REST API actions are defined for this settings page.
        if (isset($awm_settings['rest']) && isset($awm_settings['rest']['endpoint'])) {
            $method = (isset($awm_settings['rest']['method']) && !empty($awm_settings['rest']['method'])) ? $awm_settings['rest']['method'] : 'get';
            $callback = (isset($awm_settings['rest']['js_callback']) && !empty($awm_settings['rest']['js_callback'])) ? $awm_settings['rest']['js_callback'] : 'awm_rest_options_callback';
            $button = (isset($awm_settings['rest']['button']) && !empty($awm_settings['rest']['button'])) ? $awm_settings['rest']['button'] : __('Show results', 'filox');
            $namespace = isset($awm_settings['rest']['namespace']) ? $awm_settings['rest']['namespace'] : 'awm-dynamic-api/v1';
        ?>
<!-- REST API actions wrapper -->
<div class="awm-rest-actions-wrap wrap">
 <!-- Button to trigger the REST API call -->
 <div><button
   onclick="awm_options_rest_call('<?php echo $form_id; ?>','<?php echo $namespace . $awm_settings['rest']['endpoint']; ?>','<?php echo $callback; ?>','<?php echo $method; ?>')"><?php echo $button; ?></button>
 </div>
 <!-- Placeholder for REST API results -->
 <div id="awm-rest-options-results"></div>
</div>
<?php
        } ?>
<?php
    }
}
?>