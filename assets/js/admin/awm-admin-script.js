/**
 * Admin Script - Admin-specific initialization
 * Extends the core awm_init_inputs() with admin-only features
 */

/**
 * Custom Post Status Handler
 * Manages custom post statuses for specific post types in Classic Editor, Gutenberg, and Quick Edit
 * Ensures strict post-type isolation
 */
class EWPCustomPostStatus {
    /**
     * Constructor
     * @param {Object} customStatuses - Custom statuses for the current post type only
     * @param {string} currentPostType - The current post type slug
     * @param {boolean} isEditScreen - Whether this is a list/edit screen
     */
    constructor(customStatuses, currentPostType, isEditScreen) {
        this.customStatuses = customStatuses;
        this.currentPostType = currentPostType;
        this.isEditScreen = isEditScreen;
        this.init();
    }

    /**
     * Initialize custom status support
     */
    init() {
        // Validate we have statuses for THIS post type only
        if (!this.customStatuses || Object.keys(this.customStatuses).length === 0) {
            return;
        }

        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setupHandlers());
        } else {
            this.setupHandlers();
        }
    }

    /**
     * Setup all handlers
     */
    setupHandlers() {
        // Classic Editor support
        this.addToClassicEditor();

        // Gutenberg support
        this.addToGutenberg();

        // Quick Edit support (only on list screens)
        if (this.isEditScreen) {
            this.addToQuickEdit();
        }
    }

    /**
     * Add custom statuses to Classic Editor
     */
    addToClassicEditor() {
        const postStatusSelect = document.getElementById('post_status');
        if (!postStatusSelect) {
            return;
        }

        // Get current post status
        const hiddenPostStatus = document.getElementById('hidden_post_status');
        const currentStatus = hiddenPostStatus ? hiddenPostStatus.value : '';

        // Add custom statuses to dropdown
        Object.keys(this.customStatuses).forEach(statusKey => {
            const statusData = this.customStatuses[statusKey];
            const label = statusData.label || statusKey.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

            // Check if option already exists
            const existingOption = postStatusSelect.querySelector(`option[value="${statusKey}"]`);
            if (!existingOption) {
                const option = document.createElement('option');
                option.value = statusKey;
                option.textContent = label;
                postStatusSelect.appendChild(option);
            }
        });

        // Set selected status if post has custom status
        if (currentStatus && this.customStatuses[currentStatus]) {
            postStatusSelect.value = currentStatus;

            // Update the status display text in publish meta box
            const postStatusDisplay = document.getElementById('post-status-display');
            if (postStatusDisplay) {
                const label = this.customStatuses[currentStatus].label || currentStatus;
                postStatusDisplay.textContent = label;
            }
        }

        // Handle status change
        postStatusSelect.addEventListener('change', (e) => {
            const selectedStatus = e.target.value;
            if (this.customStatuses[selectedStatus]) {
                const postStatusDisplay = document.getElementById('post-status-display');
                if (postStatusDisplay) {
                    postStatusDisplay.textContent = this.customStatuses[selectedStatus].label || selectedStatus;
                }
            }
        });
    }

    /**
     * Add custom statuses to Gutenberg Editor
     */
    addToGutenberg() {
        // Check if Gutenberg is available
        if (typeof wp === 'undefined' || !wp.data) {
            return;
        }

        const { select, dispatch, subscribe } = wp.data;
        let isInitialized = false;

        // Wait for editor to be ready
        const unsubscribe = subscribe(() => {
            const editor = select('core/editor');
            if (!editor || isInitialized) {
                return;
            }

            const currentPost = editor.getCurrentPost();
            if (!currentPost || !currentPost.type) {
                return;
            }

            // Only initialize for the correct post type
            if (currentPost.type !== this.currentPostType) {
                return;
            }

            isInitialized = true;

            // Register custom statuses with Gutenberg
            Object.keys(this.customStatuses).forEach(statusKey => {
                const statusData = this.customStatuses[statusKey];

                // Try to register the status if not already registered
                try {
                    const existingStatuses = select('core').getPostStatuses();
                    if (existingStatuses && !existingStatuses[statusKey]) {
                        // Note: Gutenberg doesn't have a direct API to register statuses
                        // They need to be registered server-side, which we do in PHP
                        // This code ensures the UI updates correctly
                    }
                } catch (error) {
                    // Silent fail - status registration happens in PHP
                }
            });

            unsubscribe();
        });
    }

    /**
     * Add custom statuses to Quick Edit
     */
    addToQuickEdit() {
        // Use jQuery since WordPress Quick Edit relies on it
        if (typeof jQuery === 'undefined') {
            return;
        }

        const $ = jQuery;
        const self = this;

        // Wait for inline edit to be ready
        $(document).ready(function () {
            // Hook into WordPress inline edit
            if (typeof inlineEditPost !== 'undefined') {
                const wpEdit = inlineEditPost.edit;

                inlineEditPost.edit = function (id) {
                    // Call original edit function
                    wpEdit.apply(this, arguments);

                    // Add custom statuses to Quick Edit dropdown
                    const statusSelect = $('.inline-edit-row select[name="_status"]');
                    if (statusSelect.length) {
                        // Remove any previously added custom statuses to avoid duplicates
                        statusSelect.find('option[data-custom-status]').remove();

                        // Add custom statuses
                        Object.keys(self.customStatuses).forEach(statusKey => {
                            const statusData = self.customStatuses[statusKey];
                            const label = statusData.label || statusKey.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                            // Check if option already exists
                            if (statusSelect.find(`option[value="${statusKey}"]`).length === 0) {
                                statusSelect.append(
                                    $('<option>', {
                                        value: statusKey,
                                        text: label,
                                        'data-custom-status': 'true'
                                    })
                                );
                            }
                        });

                        // Get the current post status from the row
                        const postId = typeof id === 'object' ? $(id).attr('id').replace('post-', '') : id;
                        const row = $('#post-' + postId);
                        const currentStatus = row.find('.column-status').text().trim();

                        // Try to match and select the current status
                        Object.keys(self.customStatuses).forEach(statusKey => {
                            const statusData = self.customStatuses[statusKey];
                            if (statusData.label === currentStatus) {
                                statusSelect.val(statusKey);
                            }
                        });
                    }
                };
            }
        });
    }
}

/**
 * Admin initialization function
 * Calls the smart init function and adds admin-specific features
 */
async function awm_admin_init() {
    // Call the smart init function from core
    await awm_init_inputs();

    // Admin-specific: initialize maps if present
    if (document.querySelector('.awm_map')) {
        const mapsModule = await import('../modules/awm-maps-module.js');
        mapsModule.awm_add_map();
        // Expose map functions globally for backwards compatibility
        window.awm_add_map = mapsModule.awm_add_map;
        window.awm_call_maps_api = mapsModule.awm_call_maps_api;
        window.awmInitMap = mapsModule.awmInitMap;
        window.removeMarkers = mapsModule.removeMarkers;
        window.placeMarker = mapsModule.placeMarker;
        window.noenter = mapsModule.noenter;
    }

    // Initialize custom post status support if data is available
    if (typeof ewpCustomPostStatus !== 'undefined') {
        new EWPCustomPostStatus(
            ewpCustomPostStatus.customStatuses,
            ewpCustomPostStatus.currentPostType,
            ewpCustomPostStatus.isEditScreen
        );
    }
}

// Initialize on page load
awm_admin_init();

// Re-initialize when widgets are sorted
jQuery('div.widgets-sortables').bind('sortstop', function (event, ui) {
    awm_admin_init();
});






function awmSelectrBoxes() {
    var elems = document.querySelectorAll('.awm-meta-field select,.awm-term-input select,.awm-user-input select');
    if (elems) {
        elems.forEach(function (elem) {
            if (elem.id != '' && !elem.getAttribute('data-id') && !elem.getAttribute('awm-template') && !elem.getAttribute('awm-skip-selectr')) {
                awm_selectr_box(elem);
            }
        });
    }
}







/**
 * 
 * get the query additional informations
 * @param {*} element the div to show
 */
function awm_get_query_fields(element) {
    if (!element.disabled) {
        var id = document.getElementById('ewp_content_id').value;
        var input_name = element.getAttribute('name');
        var defaults = {
            data: { name: input_name, meta: 'query_fields' },
            method: 'get',
            url: awmGlobals.url + "/wp-json/extend-wp/v1/get-query-fields/?meta=query_fields&id=" + id + "&field=" + element.value + "&name=" + input_name,
            callback: 'awm_show_query_details',
        };
        awm_ajax_call(defaults);
    }
}

function awm_show_query_details(data, options) {

    var name = options.data.name.replace('query_fields', '').replace('[query_type]', '').replace('[', '').replace(']', '');
    var element = document.querySelector('#awm-query_fields-' + name + ' .awm-query-type-configuration');
    if (element) {
        element.innerHTML = data;
    }
    awm_init_inputs();
}


function awm_get_case_fields(element) {
    if (!element.disabled) {

        var meta = element.getAttribute('input-name');
        var id = document.getElementById('ewp_content_id').value;
        var input_name = element.getAttribute('name');
        var defaults = {
            data: { name: input_name, meta: meta },
            method: 'get',
            url: awmGlobals.url + "/wp-json/extend-wp/v1/get-case-fields/?meta=" + meta + "&id=" + id + "&field=" + element.value + "&name=" + input_name,
            callback: 'awm_show_field_details',
        };
        awm_ajax_call(defaults);
    }
}

function awm_show_field_details(data, options) {
    var name = options.data.name.replace(options.data.meta, '').replace('[case]', '').replace('[', '').replace(']', '');
    var element = document.querySelector('#awm-' + options.data.meta + '-' + name + ' .awm-field-type-configuration');
    if (element) {
        element.innerHTML = data;
    }
    awm_init_inputs();
}


function awm_get_position_settings(element) {
    var id = document.getElementById('ewp_content_id').value;
    if (!element.disabled) {
        var input_name = element.getAttribute('name');
        var defaults = {
            data: { name: input_name },
            method: 'get',
            url: awmGlobals.url + "/wp-json/extend-wp/v1/get-position-fields/?id=" + id + "&position=" + element.value + "&name=" + input_name,
            callback: 'awm_show_position_settings',
        };
        awm_ajax_call(defaults);
    }
}

function awm_show_position_settings(data, options) {
    var name = options.data.name.replace('awm_positions', '').replace('[case]', '').replace('[', '').replace(']', '');
    var element = document.querySelector('#awm-awm_positions-' + name + ' .awm-position-configuration');
    if (element) {
        element.innerHTML = data;
    }
    setTimeout(() => {
        awm_init_inputs();
    }, 100);

}

function ewp_get_php_code(id) {

    var defaults = {
        method: 'get',
        url: awmGlobals.url + "/wp-json/extend-wp/v1/get-php-code/?awm_post_id=" + id,
        callback: 'awm_show_php_code',
    };
    awm_ajax_call(defaults);

}

function awm_show_php_code(data) {
    var element = document.getElementById('awm-php-code');
    if (element) {
        element.innerHTML = data;
    }
}





/**
 * with this function we create a rest options page entry
 * @param form string the name of the form
 * @param endpoint the endpoint to send results
 * @param callback the callback to manipulate the data
 * @param method the method to send the data
 */
function awm_options_rest_call(form, endpoint, callback, method) {
    window.event.preventDefault();
    window.event.stopPropagation();
    if (!document.getElementById(form)) {
        console.log('no such form');
    }

    var form_data = ewp_jsVanillaSerialize(document.getElementById(form));

    var defaults = {
        method: method,
        data: form_data,
        url: awmGlobals.url + "/wp-json/" + endpoint,
        callback: callback,
        loading: '#awm-rest-options-results',
    }
    awm_ajax_call(defaults);
}
/**
 * with this function we show the rest data of the call
 * @param data json_aray with the return of the rest
 */
function awm_rest_options_callback(data) {
    document.getElementById('awm-rest-options-results').innerHTML = data;
}



/* Gallery and image upload logic now handled by AWMMediaField class (class-awm-media-field.js) */

/**
 * Expose callback functions globally for data-callback attributes and onclick handlers
 * These functions are called from HTML attributes and need to be accessible on the window object
 */
window.awm_get_case_fields = awm_get_case_fields;
window.awm_show_field_details = awm_show_field_details;
window.awm_get_position_settings = awm_get_position_settings;
window.awm_show_position_settings = awm_show_position_settings;
window.awm_get_query_fields = awm_get_query_fields;
window.awm_show_query_details = awm_show_query_details;
window.ewp_get_php_code = ewp_get_php_code;
window.awm_show_php_code = awm_show_php_code;
window.awmSelectrBoxes = awmSelectrBoxes;
window.awm_options_rest_call = awm_options_rest_call;
window.awm_rest_options_callback = awm_rest_options_callback;