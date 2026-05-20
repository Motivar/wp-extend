# External Plugin API Guide

This guide explains how external plugins can safely use AWM (Advance WP Meta) functions from the wp-extend plugin.

## Overview

The wp-extend plugin exposes many JavaScript functions globally for use by other plugins:
- `awm_selectr_box()` — Initialize SlimSelect on a select element
- `awmSelectrBoxes()` — Initialize all select boxes on the page
- `awm_create_calendar()` — Initialize date picker
- `repeater()` — Initialize repeater fields
- `awm_ajax_call()` — Make AJAX calls
- And many more...

However, these functions are **lazy-loaded** via dynamic imports, meaning they may not be available immediately when your plugin's JavaScript loads.

## Safe Usage Patterns

### Pattern 1: Using `awmOnReady()` (Recommended)

Queue your code to run after all AWM modules are loaded:

```javascript
// Your plugin's JavaScript
window.awmOnReady(function() {
    // All AWM functions are now available
    const element = document.getElementById('my-select');
    if (element) {
        window.awm_selectr_box(element);
    }
});
```

**Advantages:**
- Simple and readable
- Automatically executes immediately if modules are already loaded
- Handles timing automatically

### Pattern 2: Using `awmWaitForFunction()`

Wait for a specific function to be available:

```javascript
// Your plugin's JavaScript
window.awmWaitForFunction('awm_selectr_box')
    .then(function(awm_selectr_box) {
        const element = document.getElementById('my-select');
        if (element) {
            awm_selectr_box(element);
        }
    })
    .catch(function(error) {
        console.error('AWM function not available:', error);
    });
```

**Advantages:**
- More control over timing
- Can specify custom timeout
- Better error handling

### Pattern 3: Direct Call (Only if you're sure modules are loaded)

If you know the AWM modules are already loaded (e.g., in a click handler or after a delay):

```javascript
// Your plugin's JavaScript
document.addEventListener('click', function(e) {
    if (e.target.matches('.my-select-trigger')) {
        // Safe to call directly since DOM interaction means page is ready
        window.awm_selectr_box(e.target.closest('select'));
    }
});
```

**Advantages:**
- Simplest code
- No overhead

**Disadvantages:**
- Only safe for event handlers or delayed execution
- Not safe for page load initialization

## Available Functions

### Input Functions
- `awm_selectr_box(element)` — Initialize SlimSelect on a select element
- `awmSelectrBoxes()` — Initialize all select boxes
- `awm_create_calendar()` — Initialize date pickers
- `awmMultipleCheckBox()` — Initialize checkbox groups
- `awmCallbacks()` — Execute field callbacks
- `awmShowInputs()` — Show/hide inputs based on conditions
- `awm_auto_fill_inputs()` — Auto-fill input values
- `awm_toggle_password()` — Toggle password visibility

### Repeater Functions
- `repeater(container)` — Initialize repeater field
- `ewp_repeater_clone_row(button)` — Clone a repeater row
- `awm_repeater_order()` — Handle repeater ordering

### Maps Functions
- `awm_add_map()` — Initialize Google Maps
- `awm_call_maps_api(data)` — Load Google Maps API
- `awmInitMap()` — Initialize map instances
- `placeMarker(map, location, map_id)` — Place marker on map
- `removeMarkers()` — Clear all markers

### Forms Functions
- `awmInitForms()` — Initialize form validation
- `awmCheckValidation(form)` — Validate form

### Utility Functions
- `awm_ajax_call(options)` — Make AJAX calls
- `ewp_jsVanillaSerialize(form)` — Serialize form data
- `awm_serialize_data(form)` — Serialize form data
- `awm_open_tab(tab_id)` — Open a tab
- `awm_init_inputs()` — Initialize all input modules

## Examples

### Example 1: Initialize Select on Dynamic Element

```javascript
// Your plugin code
window.awmOnReady(function() {
    // Add a new select element dynamically
    const newSelect = document.createElement('select');
    newSelect.id = 'dynamic-select';
    newSelect.innerHTML = '<option>Option 1</option><option>Option 2</option>';
    document.body.appendChild(newSelect);
    
    // Initialize it with SlimSelect
    window.awm_selectr_box(newSelect);
});
```

### Example 2: Initialize Repeater on Custom Container

```javascript
// Your plugin code
window.awmOnReady(function() {
    const repeaterContainer = document.querySelector('.my-repeater');
    if (repeaterContainer) {
        window.repeater(repeaterContainer);
    }
});
```

### Example 3: Make AJAX Call Using AWM

```javascript
// Your plugin code
window.awmOnReady(function() {
    window.awm_ajax_call({
        method: 'POST',
        url: '/wp-json/my-plugin/v1/endpoint',
        data: { key: 'value' },
        callback: function(response) {
            console.log('Success:', response);
        },
        errorCallback: function(error) {
            console.error('Error:', error);
        }
    });
});
```

### Example 4: Wait for Specific Function with Timeout

```javascript
// Your plugin code
window.awmWaitForFunction('awm_create_calendar', 10000) // 10 second timeout
    .then(function() {
        // Initialize calendar on all date inputs
        document.querySelectorAll('input[type="date"]').forEach(input => {
            window.awm_create_calendar();
        });
    })
    .catch(function(error) {
        console.warn('Calendar function not available:', error);
        // Fallback to native date picker
    });
```

## Best Practices

1. **Always use `awmOnReady()` for initialization code** — It's the safest and most reliable method
2. **Check for function existence** — Use `typeof window.functionName === 'function'`
3. **Handle errors gracefully** — Provide fallbacks if AWM functions aren't available
4. **Don't assume timing** — Never call AWM functions directly on page load
5. **Use event handlers for dynamic code** — Click handlers, form submissions, etc. are safe
6. **Document your dependencies** — Let users know your plugin requires wp-extend

## Backwards Compatibility

All AWM functions are exposed to the `window` object for backwards compatibility:
- `window.awm_selectr_box`
- `window.repeater`
- `window.awm_ajax_call`
- etc.

This ensures existing code continues to work with the new webpack-bundled architecture.

## Troubleshooting

### "Function not available" Error

**Problem:** Getting "awm_selectr_box is not a function" error

**Solution:** Use `awmOnReady()` to wait for modules to load:
```javascript
window.awmOnReady(function() {
    window.awm_selectr_box(element);
});
```

### Timeout Error with `awmWaitForFunction()`

**Problem:** Function still not available after timeout

**Solution:** 
1. Check that wp-extend plugin is active
2. Verify the function name is correct
3. Increase the timeout value
4. Check browser console for errors

### Functions Work Sometimes, Not Others

**Problem:** Functions work on some pages but not others

**Solution:** The modules are only loaded when needed. For example:
- `awm_selectr_box` only loads if `.awm-show-content` exists on the page
- `repeater` only loads if `.awm-repeater` exists on the page

Use `awmWaitForFunction()` with a longer timeout, or ensure the required elements exist on your page.

## Support

For issues or questions about the external plugin API, please refer to the main wp-extend documentation or contact the plugin developers.
