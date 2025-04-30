<?php
/**
 * WP-Extend Field Examples
 * 
 * This file provides comprehensive examples of how to add custom fields to posts, terms, and users
 * using the awm_show_content function.
 * 
 * @package WP-Extend
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class AWM_Field_Examples
 * 
 * Demonstrates how to add custom fields to posts, terms, and users
 * using the awm_show_content function.
 */
class AWM_Field_Examples {
    /**
     * Class constructor
     */
    public function __construct() {
        // Initialize hooks if needed
    }

    /**
     * Example of adding fields to a post
     * 
     * @param int $post_id The post ID
     * @return string The HTML output of the fields
     */
    public static function post_fields_example($post_id = 0) {
        // Define fields array
        $fields = array(
            // Basic text input
            'text_field' => array(
                'case' => 'input',
                'type' => 'text',
                'label' => 'Text Field',
                'explanation' => 'This is a simple text field',
                'required' => true,
                'order' => 10,
                'class' => array('custom-class', 'another-class'),
                'attributes' => array(
                    'placeholder' => 'Enter text here',
                    'maxlength' => 100
                )
            ),
            
            // Number input
            'number_field' => array(
                'case' => 'input',
                'type' => 'number',
                'label' => 'Number Field',
                'explanation' => 'Enter a number',
                'order' => 20,
                'attributes' => array(
                    'min' => 0,
                    'max' => 100,
                    'step' => 5
                )
            ),
            
            // Checkbox
            'checkbox_field' => array(
                'case' => 'input',
                'type' => 'checkbox',
                'label' => 'Checkbox Field',
                'explanation' => 'Check this box',
                'order' => 30,
                'after_message' => 'Enable this feature'
            ),
            
            // Multiple checkboxes
            'checkbox_multiple_field' => array(
                'case' => 'checkbox_multiple',
                'label' => 'Multiple Checkboxes',
                'explanation' => 'Select multiple options',
                'order' => 40,
                'options' => array(
                    'option1' => array('label' => 'Option 1'),
                    'option2' => array('label' => 'Option 2'),
                    'option3' => array('label' => 'Option 3')
                )
            ),
            
            // Select dropdown
            'select_field' => array(
                'case' => 'select',
                'label' => 'Select Field',
                'explanation' => 'Choose an option',
                'order' => 50,
                'options' => array(
                    'value1' => array('label' => 'Value 1'),
                    'value2' => array('label' => 'Value 2'),
                    'value3' => array('label' => 'Value 3')
                )
            ),
            
            // Select with optgroups
            'select_with_groups' => array(
                'case' => 'select',
                'label' => 'Select With Groups',
                'explanation' => 'Choose from grouped options',
                'order' => 60,
                'options' => array(
                    'value1' => array('label' => 'Value 1', 'optgroup' => 'group1'),
                    'value2' => array('label' => 'Value 2', 'optgroup' => 'group1'),
                    'value3' => array('label' => 'Value 3', 'optgroup' => 'group2'),
                    'value4' => array('label' => 'Value 4', 'optgroup' => 'group2')
                ),
                'optgroups' => array(
                    'group1' => array('label' => 'Group 1', 'options' => array()),
                    'group2' => array('label' => 'Group 2', 'options' => array())
                )
            ),
            
            // Multiple select
            'multi_select_field' => array(
                'case' => 'select',
                'label' => 'Multiple Select',
                'explanation' => 'Choose multiple options',
                'order' => 70,
                'options' => array(
                    'value1' => array('label' => 'Value 1'),
                    'value2' => array('label' => 'Value 2'),
                    'value3' => array('label' => 'Value 3')
                ),
                'attributes' => array(
                    'multiple' => true
                )
            ),
            
            // Radio buttons
            'radio_field' => array(
                'case' => 'radio',
                'label' => 'Radio Field',
                'explanation' => 'Choose one option',
                'order' => 80,
                'options' => array(
                    'option1' => array('label' => 'Option 1'),
                    'option2' => array('label' => 'Option 2'),
                    'option3' => array('label' => 'Option 3')
                )
            ),
            
            // Textarea
            'textarea_field' => array(
                'case' => 'textarea',
                'label' => 'Textarea Field',
                'explanation' => 'Enter multiple lines of text',
                'order' => 90,
                'attributes' => array(
                    'rows' => 5,
                    'cols' => 50
                )
            ),
            
            // WYSIWYG editor
            'wysiwyg_field' => array(
                'case' => 'textarea',
                'label' => 'WYSIWYG Editor',
                'explanation' => 'Rich text editor',
                'order' => 100,
                'wp_editor' => true
            ),
            
            // Image upload
            'image_field' => array(
                'case' => 'image',
                'label' => 'Image Upload',
                'explanation' => 'Upload an image',
                'order' => 110
            ),
            
            // Multiple images
            'multiple_images' => array(
                'case' => 'image',
                'label' => 'Multiple Images',
                'explanation' => 'Upload multiple images',
                'order' => 120,
                'multiple' => true
            ),
            
            // Gallery
            'gallery_field' => array(
                'case' => 'awm_gallery',
                'label' => 'Gallery Field',
                'explanation' => 'Create a gallery',
                'order' => 130
            ),
            
            // Hidden field
            'hidden_field' => array(
                'case' => 'input',
                'type' => 'hidden',
                'order' => 140,
                'attributes' => array(
                    'value' => 'hidden_value'
                )
            ),
            
            // HTML content
            'html_content' => array(
                'case' => 'html',
                'label' => 'HTML Content',
                'explanation' => 'Custom HTML',
                'order' => 150,
                'value' => '<div class="custom-html">This is custom HTML content</div>',
                'show_label' => true
            ),
            
            // Message
            'message_field' => array(
                'case' => 'message',
                'label' => 'Message Field',
                'explanation' => 'Informational message',
                'order' => 160,
                'value' => 'This is an informational message for the user.'
            ),
            
            // Button
            'button_field' => array(
                'case' => 'button',
                'label' => 'Button Field',
                'order' => 170,
                'link' => '#',
                'class' => array('button', 'button-primary')
            ),
            
            // Section
            'section_field' => array(
                'case' => 'section',
                'label' => 'Section Field',
                'explanation' => 'Group of related fields',
                'order' => 180,
                'include' => array(
                    'section_text' => array(
                        'case' => 'input',
                        'type' => 'text',
                        'label' => 'Section Text',
                        'attributes' => array(
                            'placeholder' => 'Text within section'
                        )
                    ),
                    'section_checkbox' => array(
                        'case' => 'input',
                        'type' => 'checkbox',
                        'label' => 'Section Checkbox'
                    )
                )
            ),
            
            // Repeater
            'repeater_field' => array(
                'case' => 'repeater',
                'label' => 'Repeater Field',
                'explanation' => 'Add multiple sets of fields',
                'order' => 190,
                'minrows' => 1,
                'maxrows' => 10,
                'item_name' => 'Item',
                'row_title' => 'Item %s',
                'include' => array(
                    'repeater_title' => array(
                        'case' => 'input',
                        'type' => 'text',
                        'label' => 'Title',
                        'attributes' => array(
                            'placeholder' => 'Enter title'
                        )
                    ),
                    'repeater_description' => array(
                        'case' => 'textarea',
                        'label' => 'Description'
                    )
                )
            ),
            
            // Tabs
            'tab_field' => array(
                'case' => 'awm_tab',
                'label' => 'Tabbed Content',
                'explanation' => 'Content organized in tabs',
                'order' => 200,
                'awm_tabs' => array(
                    'tab1' => array(
                        'label' => 'Tab 1',
                        'include' => array(
                            'tab1_field1' => array(
                                'case' => 'input',
                                'type' => 'text',
                                'label' => 'Tab 1 Field 1'
                            ),
                            'tab1_field2' => array(
                                'case' => 'textarea',
                                'label' => 'Tab 1 Field 2'
                            )
                        )
                    ),
                    'tab2' => array(
                        'label' => 'Tab 2',
                        'include' => array(
                            'tab2_field1' => array(
                                'case' => 'input',
                                'type' => 'text',
                                'label' => 'Tab 2 Field 1'
                            ),
                            'tab2_field2' => array(
                                'case' => 'checkbox_multiple',
                                'label' => 'Tab 2 Field 2',
                                'options' => array(
                                    'option1' => array('label' => 'Option 1'),
                                    'option2' => array('label' => 'Option 2')
                                )
                            )
                        )
                    )
                )
            ),
            
            // Conditional field
            'conditional_parent' => array(
                'case' => 'select',
                'label' => 'Conditional Parent',
                'explanation' => 'This field controls visibility of other fields',
                'order' => 210,
                'options' => array(
                    'show' => array('label' => 'Show Child Field'),
                    'hide' => array('label' => 'Hide Child Field')
                )
            ),
            'conditional_child' => array(
                'case' => 'input',
                'type' => 'text',
                'label' => 'Conditional Child',
                'explanation' => 'This field is conditionally shown',
                'order' => 220,
                'show-when' => array(
                    'conditional_parent' => array('show')
                )
            ),
            
            // Function callback
            'function_field' => array(
                'case' => 'function',
                'label' => 'Function Field',
                'explanation' => 'Content generated by a function',
                'order' => 230,
                'callback' => 'awm_field_examples_callback'
            ),
            
            // Field with role restrictions
            'admin_only_field' => array(
                'case' => 'input',
                'type' => 'text',
                'label' => 'Admin Only Field',
                'explanation' => 'Only administrators can see this field',
                'order' => 240,
                'not_visible_by' => array('editor', 'author', 'contributor', 'subscriber')
            ),
            
            // Field with edit restrictions
            'restricted_edit_field' => array(
                'case' => 'input',
                'type' => 'text',
                'label' => 'Restricted Edit Field',
                'explanation' => 'Some roles cannot edit this field',
                'order' => 250,
                'not_editable_by' => array('author', 'contributor', 'subscriber')
            )
        );
        
        // Add a unique ID for the field group
        $fields['awm-id'] = 'post-fields-example';
        
        // Display the fields
        return awm_show_content($fields, $post_id, 'post');
    }
    
    /**
     * Example of adding fields to a term
     * 
     * @param int $term_id The term ID
     * @return string The HTML output of the fields
     */
    public static function term_fields_example($term_id = 0) {
        // Define fields array
        $fields = array(
            // Basic text input
            'term_text_field' => array(
                'case' => 'input',
                'type' => 'text',
                'label' => 'Term Text Field',
                'explanation' => 'This is a text field for terms',
                'required' => true,
                'order' => 10,
                'attributes' => array(
                    'placeholder' => 'Enter term text here'
                )
            ),
            
            // Image for term
            'term_image' => array(
                'case' => 'image',
                'label' => 'Term Image',
                'explanation' => 'Upload an image for this term',
                'order' => 20
            ),
            
            // Color picker
            'term_color' => array(
                'case' => 'input',
                'type' => 'color',
                'label' => 'Term Color',
                'explanation' => 'Choose a color for this term',
                'order' => 30
            ),
            
            // Select field
            'term_select' => array(
                'case' => 'select',
                'label' => 'Term Options',
                'explanation' => 'Select an option for this term',
                'order' => 40,
                'options' => array(
                    'option1' => array('label' => 'Option 1'),
                    'option2' => array('label' => 'Option 2'),
                    'option3' => array('label' => 'Option 3')
                )
            ),
            
            // WYSIWYG editor
            'term_description_extended' => array(
                'case' => 'textarea',
                'label' => 'Extended Description',
                'explanation' => 'Additional rich text description',
                'order' => 50,
                'wp_editor' => true
            )
        );
        
        // Add a unique ID for the field group
        $fields['awm-id'] = 'term-fields-example';
        
        // Display the fields
        return awm_show_content($fields, $term_id, 'term');
    }
    
    /**
     * Example of adding fields to a user
     * 
     * @param int $user_id The user ID
     * @return string The HTML output of the fields
     */
    public static function user_fields_example($user_id = 0) {
        // Define fields array
        $fields = array(
            // Social media fields
            'user_facebook' => array(
                'case' => 'input',
                'type' => 'url',
                'label' => 'Facebook Profile',
                'explanation' => 'Enter your Facebook profile URL',
                'order' => 10,
                'attributes' => array(
                    'placeholder' => 'https://facebook.com/username'
                )
            ),
            
            'user_twitter' => array(
                'case' => 'input',
                'type' => 'url',
                'label' => 'Twitter Profile',
                'explanation' => 'Enter your Twitter profile URL',
                'order' => 20,
                'attributes' => array(
                    'placeholder' => 'https://twitter.com/username'
                )
            ),
            
            'user_linkedin' => array(
                'case' => 'input',
                'type' => 'url',
                'label' => 'LinkedIn Profile',
                'explanation' => 'Enter your LinkedIn profile URL',
                'order' => 30,
                'attributes' => array(
                    'placeholder' => 'https://linkedin.com/in/username'
                )
            ),
            
            // User bio with rich text
            'user_extended_bio' => array(
                'case' => 'textarea',
                'label' => 'Extended Biography',
                'explanation' => 'Detailed user biography with rich text',
                'order' => 40,
                'wp_editor' => true
            ),
            
            // User avatar
            'user_custom_avatar' => array(
                'case' => 'image',
                'label' => 'Custom Avatar',
                'explanation' => 'Upload a custom avatar image',
                'order' => 50
            ),
            
            // User skills
            'user_skills' => array(
                'case' => 'checkbox_multiple',
                'label' => 'User Skills',
                'explanation' => 'Select your skills',
                'order' => 60,
                'options' => array(
                    'php' => array('label' => 'PHP'),
                    'javascript' => array('label' => 'JavaScript'),
                    'css' => array('label' => 'CSS'),
                    'html' => array('label' => 'HTML'),
                    'wordpress' => array('label' => 'WordPress')
                )
            ),
            
            // User experience level
            'user_experience' => array(
                'case' => 'radio',
                'label' => 'Experience Level',
                'explanation' => 'Select your experience level',
                'order' => 70,
                'options' => array(
                    'beginner' => array('label' => 'Beginner'),
                    'intermediate' => array('label' => 'Intermediate'),
                    'advanced' => array('label' => 'Advanced'),
                    'expert' => array('label' => 'Expert')
                )
            )
        );
        
        // Add a unique ID for the field group
        $fields['awm-id'] = 'user-fields-example';
        
        // Display the fields
        return awm_show_content($fields, $user_id, 'user');
    }
}

/**
 * Example callback function for the function field
 * 
 * @param int $id The post/term/user ID
 * @return string The HTML output
 */
function awm_field_examples_callback($id) {
    return '<div class="callback-content">This content is generated by a callback function. ID: ' . $id . '</div>';
}

/**
 * Usage examples for developers
 */

// Example 1: Display post fields in a metabox
function awm_example_post_metabox() {
    add_meta_box(
        'awm_example_metabox',
        'Custom Fields Example',
        'awm_example_metabox_callback',
        'post',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'awm_example_post_metabox');

function awm_example_metabox_callback($post) {
    // Output the fields
    echo AWM_Field_Examples::post_fields_example($post->ID);
}

// Example 2: Add term fields to a category edit screen
function awm_example_term_fields($term) {
    // Check if term exists
    if (is_object($term) && isset($term->term_id)) {
        echo AWM_Field_Examples::term_fields_example($term->term_id);
    }
}
add_action('category_edit_form_fields', 'awm_example_term_fields');

// Example 3: Add user fields to user profile
function awm_example_user_fields($user) {
    echo '<h2>Additional User Information</h2>';
    echo AWM_Field_Examples::user_fields_example($user->ID);
}
add_action('show_user_profile', 'awm_example_user_fields');
add_action('edit_user_profile', 'awm_example_user_fields');

// Example 4: Save the custom fields
function awm_example_save_fields($object_id, $data_array, $object_type = 'post') {
    if (isset($data_array['awm_custom_meta']) && !empty($data_array['awm_custom_meta'])) {
        awm_save_custom_meta($data_array['awm_custom_meta'], $data_array, $object_id, $object_type);
    }
}

// Save post meta
function awm_example_save_post_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    awm_example_save_fields($post_id, $_POST);
}
add_action('save_post', 'awm_example_save_post_meta');

// Save term meta
function awm_example_save_term_meta($term_id) {
    if (!isset($_POST['awm_custom_meta'])) return;
    
    awm_example_save_fields($term_id, $_POST, 'term');
}
add_action('edited_category', 'awm_example_save_term_meta');
add_action('create_category', 'awm_example_save_term_meta');

// Save user meta
function awm_example_save_user_meta($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;
    
    awm_example_save_fields($user_id, $_POST, 'user');
}
add_action('personal_options_update', 'awm_example_save_user_meta');
add_action('edit_user_profile_update', 'awm_example_save_user_meta');
