<?php
/**
 * Example: How to use awm_gallery field type with EWP Dynamic Blocks
 * 
 * This example demonstrates how to register a custom block with gallery field support
 * that will display a UI in the Gutenberg side panel for image selection.
 */

// Block direct access to the file for security.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Example Gallery Block Class
 * 
 * Demonstrates how to create a block with awm_gallery field type
 * that integrates with the Gutenberg editor side panel.
 */
class AWM_Gallery_Block_Example
{
    /**
     * Constructor - hooks into the EWP block system
     */
    public function __construct()
    {
        add_filter('ewp_gutenburg_blocks_filter', [$this, 'register_gallery_block']);
    }

    /**
     * Register the gallery block with EWP Dynamic Blocks system
     * 
     * @param array $blocks Existing blocks array
     * @return array Modified blocks array with gallery block
     */
    public function register_gallery_block($blocks)
    {
        $blocks['gallery_showcase'] = [
            'namespace' => 'awm-gallery',
            'name' => 'showcase',
            'title' => __('Gallery Showcase', 'extend-wp'),
            'description' => __('Display a gallery of images with customizable layout', 'extend-wp'),
            'category' => 'design',
            'icon' => 'format-gallery',
            'render_callback' => [$this, 'render_gallery_block'],
            'attributes' => [
                'gallery_title' => [
                    'label' => __('Gallery Title', 'extend-wp'),
                    'case' => 'input',
                    'type' => 'text',
                    'explanation' => __('Enter a title for your gallery', 'extend-wp'),
                    'required' => true,
                    'default' => ''
                ],
                'gallery_images' => [
                    'label' => __('Gallery Images', 'extend-wp'),
                    'case' => 'awm_gallery', // This is the key field type for gallery support
                    'explanation' => __('Select images for your gallery. You can choose multiple images and reorder them.', 'extend-wp'),
                    'required' => true,
                    'default' => []
                ],
                'columns' => [
                    'label' => __('Columns', 'extend-wp'),
                    'case' => 'input',
                    'type' => 'number',
                    'explanation' => __('Number of columns to display', 'extend-wp'),
                    'min' => 1,
                    'max' => 6,
                    'default' => 3
                ],
                'show_captions' => [
                    'label' => __('Show Captions', 'extend-wp'),
                    'case' => 'input',
                    'type' => 'checkbox',
                    'explanation' => __('Display image captions below each image', 'extend-wp'),
                    'default' => false
                ]
            ]
        ];

        return $blocks;
    }

    /**
     * Render callback for the gallery block
     * 
     * @param array $attributes Block attributes from Gutenberg
     * @return string HTML output for the block
     */
    public function render_gallery_block($attributes)
    {
        // Extract attributes with defaults
        $title = isset($attributes['gallery_title']) ? sanitize_text_field($attributes['gallery_title']) : '';
        $image_ids = isset($attributes['gallery_images']) ? $attributes['gallery_images'] : [];
        $columns = isset($attributes['columns']) ? intval($attributes['columns']) : 3;
        $show_captions = isset($attributes['show_captions']) ? (bool)$attributes['show_captions'] : false;

        // Return early if no images
        if (empty($image_ids) || !is_array($image_ids)) {
            return '<div class="awm-gallery-placeholder">' . __('No images selected for gallery.', 'extend-wp') . '</div>';
        }

        // Start building the HTML output
        $output = '<div class="awm-gallery-showcase" data-columns="' . esc_attr($columns) . '">';
        
        // Add title if provided
        if (!empty($title)) {
            $output .= '<h3 class="awm-gallery-title">' . esc_html($title) . '</h3>';
        }

        // Create gallery grid
        $output .= '<div class="awm-gallery-grid" style="display: grid; grid-template-columns: repeat(' . $columns . ', 1fr); gap: 15px;">';

        // Loop through image IDs and create gallery items
        foreach ($image_ids as $image_id) {
            $image_id = intval($image_id);
            
            // Get image data
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
            $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $image_caption = wp_get_attachment_caption($image_id);

            if ($image_url) {
                $output .= '<div class="awm-gallery-item">';
                $output .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($image_alt) . '" style="width: 100%; height: auto; border-radius: 4px;">';
                
                // Add caption if enabled and available
                if ($show_captions && !empty($image_caption)) {
                    $output .= '<p class="awm-gallery-caption" style="margin-top: 8px; font-size: 14px; color: #666;">' . esc_html($image_caption) . '</p>';
                }
                
                $output .= '</div>';
            }
        }

        $output .= '</div>'; // Close gallery-grid
        $output .= '</div>'; // Close gallery-showcase

        return $output;
    }
}

// Initialize the example (uncomment to activate)
// new AWM_Gallery_Block_Example();

/**
 * Usage Instructions:
 * 
 * 1. Uncomment the line above to activate this example
 * 2. Go to the WordPress admin and create/edit a post or page
 * 3. In the Gutenberg editor, click the "+" button to add a new block
 * 4. Search for "Gallery Showcase" in the block inserter
 * 5. Add the block to your content
 * 6. In the side panel (Inspector Controls), you'll see:
 *    - Gallery Title: Text input for the gallery title
 *    - Gallery Images: Button to "Select Images" that opens WordPress media library
 *    - Columns: Number input for grid columns
 *    - Show Captions: Toggle for displaying image captions
 * 
 * The awm_gallery field type provides:
 * - Multiple image selection via WordPress media library
 * - Visual preview of selected images in the side panel
 * - Individual image removal with "×" button
 * - "Clear All Images" button to remove all selections
 * - Proper integration with block validation system
 * 
 * Field Configuration Options for awm_gallery:
 * - 'case' => 'awm_gallery' (required)
 * - 'label' => 'Your Field Label'
 * - 'explanation' => 'Help text for users'
 * - 'required' => true/false
 * - 'default' => [] (always use empty array as default)
 * 
 * Field Configuration Options for image (single image picker):
 * - 'case' => 'image' (required)
 * - 'label' => 'Your Field Label'
 * - 'explanation' => 'Help text for users'
 * - 'required' => true/false
 * - 'default' => '' (empty string as default)
 * 
 * Example single image attribute in a block:
 * 'featured_image' => [
 *     'label'       => __('Featured Image', 'extend-wp'),
 *     'case'        => 'image',
 *     'explanation'  => __('Select a single featured image', 'extend-wp'),
 *     'default'     => ''
 * ]
 */
