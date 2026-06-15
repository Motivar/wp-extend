# Custom Post Status Usage Guide

## Overview

The Extend WP plugin now supports custom post statuses for any registered post type. Custom statuses are **strictly isolated per post type** - each post type can have its own unique set of statuses that appear only when editing that specific post type.

## Features

- ✅ **Classic Editor Support**: Custom statuses appear in the status dropdown
- ✅ **Gutenberg Support**: Custom statuses work in the block editor
- ✅ **Quick Edit Support**: Custom statuses available in list view quick edit
- ✅ **Post-Type Isolation**: Each post type has independent custom statuses
- ✅ **Status Labels**: Custom status labels display in admin post lists
- ✅ **Save Protection**: WordPress won't reset custom statuses to 'draft'

## Basic Usage

Add a `custom_status` array to your post type configuration via the `epw_get_post_types` filter:

```php
add_filter('epw_get_post_types', function($post_types) {
    $post_types['ewp_product'] = [
        'post' => 'ewp_product',
        'sn' => 'Product',
        'pl' => 'Products',
        'args' => ['title', 'editor', 'thumbnail'],
        
        // Add custom statuses
        'custom_status' => [
            'pending_review' => [
                'label' => 'Pending Review',
                'public' => true,
                'exclude_from_search' => false,
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
            'rejected' => [
                'label' => 'Rejected',
                'public' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
            ],
        ],
    ];
    
    return $post_types;
});
```

## Custom Status Parameters

Each status in the `custom_status` array accepts these parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `label` | string | **Required**. Display name for the status |
| `public` | boolean | Whether posts with this status are publicly visible |
| `exclude_from_search` | boolean | Exclude from search results |
| `show_in_admin_all_list` | boolean | Show in "All" posts list |
| `show_in_admin_status_list` | boolean | Show status count in admin |

## Post-Type Isolation Example

```php
add_filter('epw_get_post_types', function($post_types) {
    // Products have their own statuses
    $post_types['ewp_product'] = [
        'post' => 'ewp_product',
        'sn' => 'Product',
        'pl' => 'Products',
        'custom_status' => [
            'in_stock' => ['label' => 'In Stock'],
            'out_of_stock' => ['label' => 'Out of Stock'],
            'discontinued' => ['label' => 'Discontinued'],
        ],
    ];
    
    // Reviews have completely different statuses
    $post_types['ewp_review'] = [
        'post' => 'ewp_review',
        'sn' => 'Review',
        'pl' => 'Reviews',
        'custom_status' => [
            'pending_moderation' => ['label' => 'Pending Moderation'],
            'approved' => ['label' => 'Approved'],
            'spam' => ['label' => 'Spam'],
        ],
    ];
    
    return $post_types;
});
```

**Result:**
- When editing a **Product**: Only see `in_stock`, `out_of_stock`, `discontinued`
- When editing a **Review**: Only see `pending_moderation`, `approved`, `spam`
- No cross-contamination between post types

## Programmatic Status Changes

Set custom status when creating or updating posts:

```php
// Create a new post with custom status
$post_id = wp_insert_post([
    'post_type' => 'ewp_product',
    'post_title' => 'New Product',
    'post_status' => 'pending_review', // Custom status
]);

// Update existing post status
wp_update_post([
    'ID' => $post_id,
    'post_status' => 'approved', // Custom status
]);
```

## Query Posts by Custom Status

```php
// Get all products with 'approved' status
$approved_products = get_posts([
    'post_type' => 'ewp_product',
    'post_status' => 'approved',
    'posts_per_page' => -1,
]);

// WP_Query with custom status
$query = new WP_Query([
    'post_type' => 'ewp_product',
    'post_status' => ['approved', 'pending_review'],
]);
```

## Conditional Logic Based on Status

```php
// In template files
if (get_post_status() === 'approved') {
    echo '<span class="badge badge-success">Approved</span>';
} elseif (get_post_status() === 'pending_review') {
    echo '<span class="badge badge-warning">Pending Review</span>';
}
```

## REST API Support

Custom statuses registered with `public => true` are automatically available in the WordPress REST API:

```javascript
// Fetch posts with custom status via REST API
fetch('/wp-json/wp/v2/ewp_product?status=approved')
    .then(response => response.json())
    .then(products => console.log(products));
```

## Validation & Security

The system automatically validates:
- ✅ Status belongs to the post type (prevents cross-post-type assignments)
- ✅ Status exists in the post type's `custom_status` array
- ✅ Invalid statuses are rejected during save

## Troubleshooting

### Custom statuses not appearing in editor

1. **Check post type registration**: Ensure `custom_status` array is in the post type definition
2. **Clear cache**: Run `ewp_flush_cache()` or visit Settings → Permalinks
3. **Check browser console**: Look for JavaScript errors
4. **Verify post type**: Make sure you're editing the correct post type

### Status reverts to 'draft' on save

This should not happen with the implementation. If it does:
1. Check that the status key matches exactly (case-sensitive)
2. Verify the status is defined in the `custom_status` array
3. Check for conflicting plugins that modify post status

### Quick Edit not showing custom statuses

1. Ensure you're on the correct post type's list page
2. Check that `isEditScreen` is being passed correctly
3. Verify jQuery is loaded (WordPress Quick Edit requires it)

## Best Practices

1. **Use descriptive status keys**: `pending_review` instead of `pr`
2. **Set appropriate visibility**: Use `public => false` for internal statuses
3. **Consistent labeling**: Use clear, user-friendly labels
4. **Document your statuses**: Comment your custom status definitions
5. **Test thoroughly**: Verify in Classic Editor, Gutenberg, and Quick Edit

## Backward Compatibility

Post types without a `custom_status` array continue to work normally with WordPress default statuses (publish, draft, pending, private, trash).

## Technical Implementation

- **PHP Hooks**: `admin_enqueue_scripts`, `display_post_states`, `wp_insert_post_data`
- **JavaScript**: `EWPCustomPostStatus` class in `awm-admin-script.js`
- **Localization**: `ewpCustomPostStatus` global variable
- **Post-Type Detection**: Via `get_current_screen()` and `$_GET['post_type']`
