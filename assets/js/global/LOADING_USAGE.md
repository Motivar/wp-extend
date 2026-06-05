# AWM AJAX Loading Spinner Usage Guide

## Overview
The AWM AJAX system now includes built-in loading spinner support with automatic show/hide functionality.

## Features
- **Automatic loading display**: Show spinner before AJAX request starts
- **Auto-hide option**: Automatically hide spinner on success or error
- **Manual control**: Use helper functions for custom loading scenarios
- **Fade effects**: Smooth fade in/out transitions (0.3s)
- **Multiple elements**: Support for multiple loading containers via CSS selector
- **Body state classes**: Automatic body classes for visual feedback (`awm-ajax-started`, `awm-ajax-processing`, `awm-ajax-succeeded`, `awm-ajax-failed`)
- **Custom events**: Dispatched at each state change for advanced integrations

## Basic Usage

### Example 1: Auto-hide Loading (Recommended)
```javascript
awm_ajax_call({
    method: 'POST',
    url: '/wp-json/my-plugin/v1/endpoint',
    data: { id: 123 },
    loading: '#loading-container',
    loadingAutoHide: true,  // Auto-hide on success/error
    callback: function(response) {
        console.log('Success:', response);
        // Loading spinner already hidden automatically
    },
    errorCallback: function(error) {
        console.log('Error:', error);
        // Loading spinner already hidden automatically
    }
});
```

### Example 2: Manual Loading Control
```javascript
awm_ajax_call({
    method: 'POST',
    url: '/wp-json/my-plugin/v1/endpoint',
    data: { id: 123 },
    loading: '#loading-container',
    loadingAutoHide: false,  // Manual control
    callback: function(response) {
        // Do something with response
        console.log('Success:', response);
        
        // Manually hide loading when ready
        awm_hide_loading('#loading-container');
    }
});
```

### Example 3: Multiple Loading Containers
```javascript
awm_ajax_call({
    method: 'GET',
    url: '/wp-json/my-plugin/v1/data',
    loading: '.loading-spinner',  // Targets all elements with this class
    loadingAutoHide: true,
    callback: function(response) {
        console.log('Data loaded:', response);
    }
});
```

## Helper Functions

### awm_show_loading(selector)
Manually show loading spinner in target element(s).

```javascript
// Show loading in single element
awm_show_loading('#my-container');

// Show loading in multiple elements
awm_show_loading('.loading-area');
```

### awm_hide_loading(selector)
Manually hide loading spinner from target element(s).

```javascript
// Hide loading from single element
awm_hide_loading('#my-container');

// Hide loading from multiple elements
awm_hide_loading('.loading-area');
```

## HTML Structure

The loading container should be an empty element where the spinner will be injected:

```html
<!-- Single loading container -->
<div id="loading-container"></div>

<!-- Multiple loading containers -->
<div class="loading-spinner"></div>
<div class="loading-spinner"></div>
```

## Options Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `loading` | string\|boolean | `false` | CSS selector for loading element(s) |
| `loadingAutoHide` | boolean | `false` | Auto-hide spinner on success/error |

## Customization

### PHP Filter: Custom Spinner HTML

You can completely replace the spinner HTML using the `awm_spinner_html` filter:

```php
/**
 * Customize the loading spinner HTML
 * 
 * @param string $html Default spinner HTML
 * @return string Custom spinner HTML
 */
add_filter('awm_spinner_html', function($html) {
    // Use a custom spinner (e.g., Font Awesome icon)
    return '<div class="my-custom-spinner"><i class="fas fa-spinner fa-spin"></i></div>';
});

// Or use a simple text loader
add_filter('awm_spinner_html', function($html) {
    return '<div class="loading-text">Loading...</div>';
});
```

### SCSS Variables

The default spinner uses SCSS variables for easy styling customization:

```scss
// Override in your theme/plugin
$spinner-margin-top: 50px;    // Top margin
$spinner-width: 70px;          // Container width
$spinner-dot-size: 18px;       // Dot size
$spinner-color: #333;          // Dot color
$spinner-duration: 1.4s;       // Animation duration
$bounce1-delay: -0.32s;        // First dot delay
$bounce2-delay: -0.16s;        // Second dot delay
```

## Body Classes

The system automatically adds/removes classes to `<body>` during AJAX lifecycle:

| Class | When Applied | Duration |
|-------|-------------|----------|
| `awm-ajax-started` | Request initialized | Until `loadstart` event |
| `awm-ajax-processing` | Request sent to server | Until response received |
| `awm-ajax-succeeded` | Successful response (2xx) | 300ms after success |
| `awm-ajax-failed` | Failed response (4xx/5xx) | 300ms after error |

### CSS Example

```css
/* Show overlay during AJAX */
body.awm-ajax-processing::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    z-index: 9999;
}

/* Disable buttons during processing */
body.awm-ajax-processing button {
    pointer-events: none;
    opacity: 0.6;
}

/* Success flash */
body.awm-ajax-succeeded {
    transition: background-color 0.3s;
    background-color: rgba(0, 255, 0, 0.1);
}

/* Error flash */
body.awm-ajax-failed {
    transition: background-color 0.3s;
    background-color: rgba(255, 0, 0, 0.1);
}
```

## Custom Events

Events are dispatched at each state change for advanced integrations:

| Event Name | When Dispatched | Detail Data |
|------------|----------------|-------------|
| `awm_ajax_started` | Request initialized | `{ options }` |
| `awm_ajax_processing` | Request sent (loadstart) | `{ options }` |
| `awm_ajax_call_callback` | Success response | `{ response, options }` |
| `awm_ajax_call_error` | Error response | `{ status, message, options }` |

### Event Listener Example

```javascript
// Listen for AJAX start
document.addEventListener('awm_ajax_started', function(e) {
    console.log('AJAX started:', e.detail.options);
    // Show global loading indicator
});

// Listen for AJAX processing
document.addEventListener('awm_ajax_processing', function(e) {
    console.log('AJAX processing:', e.detail.options);
    // Update UI state
});

// Listen for all successful AJAX calls
document.addEventListener('awm_ajax_call_callback', function(e) {
    console.log('AJAX succeeded:', e.detail.response);
    // Show success notification
});

// Listen for all failed AJAX calls
document.addEventListener('awm_ajax_call_error', function(e) {
    console.error('AJAX failed:', e.detail.status, e.detail.message);
    // Show error notification
});
```

## Best Practices

1. **Use auto-hide for simple cases**: Set `loadingAutoHide: true` when you don't need custom timing
2. **Manual control for complex flows**: Use `loadingAutoHide: false` when you need to:
   - Process response data before hiding
   - Show success messages
   - Chain multiple AJAX calls
3. **Consistent selectors**: Use consistent CSS selectors across your application
4. **Accessibility**: Ensure loading containers have appropriate ARIA attributes in your HTML
5. **CSS state management**: Use body classes for global UI changes (overlays, button states)
6. **Event listeners**: Use custom events for analytics, notifications, or complex state management

## Complete Example

```html
<!-- HTML -->
<div id="user-list"></div>
<div id="loading-users"></div>
<button id="load-users">Load Users</button>

<script>
document.getElementById('load-users').addEventListener('click', function() {
    awm_ajax_call({
        method: 'GET',
        url: '/wp-json/my-plugin/v1/users',
        loading: '#loading-users',
        loadingAutoHide: true,
        callback: function(response) {
            // Populate user list
            const userList = document.getElementById('user-list');
            userList.innerHTML = response.data.map(user => 
                `<div class="user">${user.name}</div>`
            ).join('');
        },
        errorCallback: function(error) {
            console.error('Failed to load users:', error);
            alert('Failed to load users. Please try again.');
        }
    });
});
</script>
```

## Troubleshooting

**Spinner not showing:**
- Verify the selector matches existing element(s)
- Check browser console for JavaScript errors
- Ensure SCSS is compiled and loaded

**Spinner not hiding:**
- Check `loadingAutoHide` is set to `true` OR
- Manually call `awm_hide_loading(selector)` in callback

**Multiple spinners:**
- Use class selectors (`.loading`) to target multiple elements
- Each element will get its own spinner instance
