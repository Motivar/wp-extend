# Recently Seen Functionality

This module provides functionality to track and retrieve recently viewed content in WordPress. It stores data both on the server-side (PHP sessions) and client-side (localStorage).

## Overview

The Recently Seen module tracks which posts/pages a user has viewed, organized by post type. This data is stored in two places:

1. **Server-side**: PHP sessions via `$_SESSION['ewp_recently_seen']`
2. **Client-side**: Browser localStorage via `localStorage.getItem('ewp_recently_seen')`

## Configuration

### Step 1: Enable and Configure Post Types

Before using the Recently Seen functionality, you must configure which post types to track. There are two ways to do this:

#### Option 1: Via WordPress Admin Interface

1. Navigate to **Extend WP → Developer Settings** in your WordPress admin dashboard
2. Find the **"Enable recently seen functionality for"** section
3. Select the post types you want to track (e.g., Posts, Pages, or custom post types)
4. Save your changes

![Developer Settings](../../../../../assets/images/recently-seen-settings.png)

#### Option 2: Via Filter Hook

You can also programmatically set which post types to track using the `ewp_recently_seen_post_types_filter` filter:

```php
/**
 * Add or modify post types for Recently Seen tracking
 * 
 * @param array $post_types Array of post types to track
 * @return array Modified array of post types
 */
function my_custom_recently_seen_post_types($post_types) {
    // Add your custom post types
    $post_types[] = 'product';
    $post_types[] = 'event';
    
    // Or completely override
    // $post_types = array('product', 'event', 'course');
    
    return $post_types;
}
add_filter('ewp_recently_seen_post_types_filter', 'my_custom_recently_seen_post_types');
```

## Accessing Recently Seen Data via PHP

### Getting All Recently Seen Data

```php
/**
 * Get all recently seen items organized by post type
 *
 * @return array Associative array with post types as keys and arrays of post IDs as values
 */
function get_all_recently_seen() {
    // Check if the class exists and if session is available
    if (class_exists('EWP_Recently_Seen_UTIL') && isset($_SESSION['ewp_recently_seen'])) {
        return $_SESSION['ewp_recently_seen'];
    }
    
    return array(); // Return empty array if no data is available
}
```

### Getting Recently Seen Items for a Specific Post Type

```php
/**
 * Get recently seen items for a specific post type
 *
 * @param string $post_type The post type to retrieve items for
 * @return array Array of post IDs
 */
function get_recently_seen_by_post_type($post_type) {
    if (isset($_SESSION['ewp_recently_seen']) && isset($_SESSION['ewp_recently_seen'][$post_type])) {
        return $_SESSION['ewp_recently_seen'][$post_type];
    }
    
    return array(); // Return empty array if no data is available
}
```

### Getting Recently Seen Posts as WP_Post Objects

```php
/**
 * Get recently seen posts as WP_Post objects
 *
 * @param string $post_type The post type to retrieve items for
 * @param int $limit Maximum number of posts to return (0 for all)
 * @return array Array of WP_Post objects
 */
function get_recently_seen_posts($post_type, $limit = 0) {
    $recently_seen = get_recently_seen_by_post_type($post_type);
    
    if (empty($recently_seen)) {
        return array();
    }
    
    // Apply limit if specified
    if ($limit > 0 && count($recently_seen) > $limit) {
        // Get the most recent items (last items in the array)
        $recently_seen = array_slice($recently_seen, -$limit);
    }
    
    // Get post objects
    $posts = array();
    foreach ($recently_seen as $post_id) {
        $post = get_post($post_id);
        if ($post) {
            $posts[] = $post;
        }
    }
    
    return $posts;
}
```

### Example Usage in a Template

```php
// Get the 5 most recently seen products
$recent_products = get_recently_seen_posts('product', 5);

if (!empty($recent_products)) {
    echo '<div class="recently-viewed-products">';
    echo '<h3>Recently Viewed Products</h3>';
    echo '<ul>';
    
    foreach ($recent_products as $product) {
        echo '<li>';
        echo '<a href="' . get_permalink($product->ID) . '">';
        echo get_the_title($product->ID);
        echo '</a>';
        echo '</li>';
    }
    
    echo '</ul>';
    echo '</div>';
}
```

## Accessing Recently Seen Data via JavaScript

The Recently Seen data is also stored in the browser's localStorage, making it accessible via JavaScript.

### Getting All Recently Seen Data

```javascript
/**
 * Get all recently seen items from localStorage
 * 
 * @return {Object} Object with post types as keys and arrays of post IDs as values
 */
function getAllRecentlySeen() {
    const recentlySeen = localStorage.getItem('ewp_recently_seen');
    return recentlySeen ? JSON.parse(recentlySeen) : {};
}
```

### Getting Recently Seen Items for a Specific Post Type

```javascript
/**
 * Get recently seen items for a specific post type
 * 
 * @param {string} postType The post type to retrieve items for
 * @return {Array} Array of post IDs
 */
function getRecentlySeenByPostType(postType) {
    const allRecentlySeen = getAllRecentlySeen();
    return allRecentlySeen[postType] || [];
}
```

### Example Usage in JavaScript

```javascript
// Get recently seen products
const recentProducts = getRecentlySeenByPostType('product');

if (recentProducts.length > 0) {
    console.log('Recently viewed products:', recentProducts);
    
    // Example: Fetch product details using the WordPress REST API
    const fetchRecentProducts = async () => {
        try {
            const response = await fetch(`/wp-json/wp/v2/product?include=${recentProducts.join(',')}`);
            const products = await response.json();
            
            // Do something with the products data
            displayRecentProducts(products);
        } catch (error) {
            console.error('Error fetching recent products:', error);
        }
    };
    
    fetchRecentProducts();
}

// Example display function
function displayRecentProducts(products) {
    const container = document.querySelector('.recently-viewed-container');
    if (!container) return;
    
    let html = '<h3>Recently Viewed Products</h3><ul>';
    
    products.forEach(product => {
        html += `<li><a href="${product.link}">${product.title.rendered}</a></li>`;
    });
    
    html += '</ul>';
    container.innerHTML = html;
}
```

## Adding Custom Tracking

You can manually track items as "recently seen" using the following methods:

### PHP

```php
/**
 * Manually add an item to recently seen
 *
 * @param int $post_id The post ID to add
 * @param string $post_type The post type
 */
function add_to_recently_seen($post_id, $post_type) {
    if (class_exists('EWP_Recently_Seen_UTIL')) {
        $recently_seen = new EWP_Recently_Seen_UTIL();
        $recently_seen->update_recently_seen($post_id, $post_type);
    }
}
```

### JavaScript

```javascript
/**
 * Manually add an item to recently seen in localStorage
 *
 * @param {number} postId The post ID to add
 * @param {string} postType The post type
 */
function addToRecentlySeen(postId, postType) {
    // Get existing data
    const seen = JSON.parse(localStorage.getItem('ewp_recently_seen') || '{}');
    
    // Initialize array for this post type if needed
    if (!seen[postType]) {
        seen[postType] = [];
    }
    
    // Add the ID if it doesn't exist already
    if (!seen[postType].includes(postId)) {
        seen[postType].push(postId);
        localStorage.setItem('ewp_recently_seen', JSON.stringify(seen));
    }
    
    // Optionally notify the server
    const data = {
        method: 'post',
        url: awmGlobals.url + "/wp-json/ewp/v1/recently-seen/" + postId
    };
    
    awm_ajax_call(data);
}
```

## Best Practices

1. **Performance**: When displaying recently seen items, consider limiting the number of items to avoid performance issues.
2. **User Experience**: Display recently seen items in a logical order (most recent first or last, depending on your UI).
3. **Fallbacks**: Always check if the recently seen data exists before trying to use it.
4. **Privacy**: Consider adding a notice informing users that you're tracking their recently viewed items.

## Troubleshooting

- If server-side tracking isn't working, ensure PHP sessions are properly initialized.
- If client-side tracking isn't working, check browser console for JavaScript errors.
- Verify that the post types you want to track are included in the `recently_seen` array in the `ewp_dev_settings` option.
