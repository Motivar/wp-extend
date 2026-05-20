/**
 * Admin Script - Admin-specific initialization
 * Extends the core awm_init_inputs() with admin-only features
 */

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