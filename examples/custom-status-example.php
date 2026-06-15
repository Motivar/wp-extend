<?php
/**
 * Custom Post Status Example
 * 
 * This file demonstrates how to add custom post statuses to your post types
 * using the Extend WP plugin's custom status support.
 * 
 * @package ExtendWP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Example 1: Simple Product Status
 * 
 * Add basic custom statuses to a product post type
 */
add_filter('epw_get_post_types', function($post_types) {
    
    $post_types['ewp_product'] = [
        'post' => 'ewp_product',
        'sn' => 'Product',
        'pl' => 'Products',
        'args' => ['title', 'editor', 'thumbnail'],
        'flx_custom_template' => true,
        
        // Custom statuses for products
        'custom_status' => [
            'pending_review' => [
                'label' => 'Pending Review',
                'public' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
            'approved' => [
                'label' => 'Approved',
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
            'out_of_stock' => [
                'label' => 'Out of Stock',
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
        ],
    ];
    
    return $post_types;
}, 10);

/**
 * Example 2: Review Moderation Workflow
 * 
 * Different post type with completely different statuses
 */
add_filter('epw_get_post_types', function($post_types) {
    
    $post_types['ewp_review'] = [
        'post' => 'ewp_review',
        'sn' => 'Review',
        'pl' => 'Reviews',
        'args' => ['title', 'editor'],
        'flx_custom_template' => true,
        
        // Custom statuses for reviews (completely independent from products)
        'custom_status' => [
            'pending_moderation' => [
                'label' => 'Pending Moderation',
                'public' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
            'approved' => [
                'label' => 'Approved',
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
            'spam' => [
                'label' => 'Spam',
                'public' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
            'flagged' => [
                'label' => 'Flagged for Review',
                'public' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
        ],
    ];
    
    return $post_types;
}, 10);

/**
 * Example 3: Programmatically Create Post with Custom Status
 */
function ewp_create_product_with_status() {
    $product_id = wp_insert_post([
        'post_type' => 'ewp_product',
        'post_title' => 'New Product',
        'post_content' => 'Product description',
        'post_status' => 'pending_review', // Custom status
    ]);
    
    return $product_id;
}

/**
 * Example 4: Query Posts by Custom Status
 */
function ewp_get_approved_products() {
    $args = [
        'post_type' => 'ewp_product',
        'post_status' => 'approved',
        'posts_per_page' => -1,
    ];
    
    return get_posts($args);
}

/**
 * Example 5: Update Post Status Programmatically
 */
function ewp_approve_product($product_id) {
    wp_update_post([
        'ID' => $product_id,
        'post_status' => 'approved',
    ]);
}

/**
 * Example 6: Conditional Display Based on Status
 */
function ewp_display_product_status_badge($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    
    $status = get_post_status($post_id);
    
    switch ($status) {
        case 'approved':
            echo '<span class="badge badge-success">✓ Approved</span>';
            break;
        case 'pending_review':
            echo '<span class="badge badge-warning">⏳ Pending Review</span>';
            break;
        case 'out_of_stock':
            echo '<span class="badge badge-danger">✗ Out of Stock</span>';
            break;
        default:
            echo '<span class="badge badge-secondary">' . esc_html($status) . '</span>';
    }
}

/**
 * Example 7: Admin Column for Custom Status
 */
add_filter('manage_ewp_product_posts_columns', function($columns) {
    $columns['product_status'] = 'Status';
    return $columns;
});

add_action('manage_ewp_product_posts_custom_column', function($column, $post_id) {
    if ($column === 'product_status') {
        ewp_display_product_status_badge($post_id);
    }
}, 10, 2);

/**
 * Example 8: Email Notification on Status Change
 */
add_action('transition_post_status', function($new_status, $old_status, $post) {
    // Only for products
    if ($post->post_type !== 'ewp_product') {
        return;
    }
    
    // Only when changing to 'approved'
    if ($new_status === 'approved' && $old_status !== 'approved') {
        $admin_email = get_option('admin_email');
        $subject = 'Product Approved: ' . get_the_title($post->ID);
        $message = sprintf(
            'The product "%s" has been approved and is now live.',
            get_the_title($post->ID)
        );
        
        wp_mail($admin_email, $subject, $message);
    }
}, 10, 3);

/**
 * Example 9: Bulk Status Update
 */
function ewp_bulk_approve_products($product_ids) {
    foreach ($product_ids as $product_id) {
        wp_update_post([
            'ID' => $product_id,
            'post_status' => 'approved',
        ]);
    }
}

/**
 * Example 10: REST API - Get Products by Status
 * 
 * Usage: GET /wp-json/wp/v2/ewp_product?status=approved
 */
add_action('rest_api_init', function() {
    register_rest_route('ewp/v1', '/products/by-status/(?P<status>[a-zA-Z_]+)', [
        'methods' => 'GET',
        'callback' => function($request) {
            $status = $request->get_param('status');
            
            $products = get_posts([
                'post_type' => 'ewp_product',
                'post_status' => $status,
                'posts_per_page' => -1,
            ]);
            
            return rest_ensure_response($products);
        },
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
    ]);
});
