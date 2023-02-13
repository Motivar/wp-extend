/**check when dom is ready to initatate the forms */

document.addEventListener('DOMContentLoaded', () => {
    ewp_search_forms();
});

/**
 * 
 * @param {string} id the name of the input to change
 * @param {int} form_id the form box id
 */
function ewp_search_remove_filter(id, form_id) {
    let skip_reset_value = false;
    var form_box = document.querySelector('#ewp-search-' + form_id);
    var form = form_box.querySelector('form');
    var options = JSON.parse(form_box.getAttribute('options').replace(/\'/g, '\"'));
    options.search_id = form_box.getAttribute('search-id');
    var show_results = document.querySelector(options.show_results);
    var filters = form.querySelectorAll('#' + id);
    if (filters.length === 0) {
        /*check if is a radio or checkbox*/
        filters = form.querySelectorAll('[name="' + id + '"]');
    }
    if (filters.length > 0) {
        Array.from(filters).forEach(function(filter) {
            if (filter.checked) {
                filter.checked = false;
                skip_reset_value = true;
            }
            filter.value = skip_reset_value ? filter.value : '';
        });
        ewp_search_action(form, options, show_results, 1);
    }
}

/**
 *  apply the search form
 * @param {*} id the form id
 */
function ewp_apply_search_form(id) {
    var form_box = document.querySelector('#ewp-search-' + id);
    var options = JSON.parse(form_box.getAttribute('options').replace(/\'/g, '\"'));
    options.search_id = form_box.getAttribute('search-id');
    var form = form_box.querySelector('form');
    var show_results = document.querySelector(options.show_results);
    if (show_results !== null) {
        ewp_search_action(form, options, show_results, 1);
    }
}

/**
 * reset the form
 * @param {*} id the form id
 */
function ewp_reset_search_form(id) {
    var form_box = document.querySelector('#ewp-search-' + id);
    var options = JSON.parse(form_box.getAttribute('options').replace(/\'/g, '\"'));
    options.search_id = form_box.getAttribute('search-id');
    var form = form_box.querySelector('form');
    var show_results = document.querySelector(options.show_results);
    if (show_results !== null) {
        form.reset();
        ewp_search_action(form, options, show_results, 1);
    }
}


/**
 * 
 */
function ewp_search_forms() {
    /*get the forms*/
    var form_boxes = document.querySelectorAll('.ewp-search-box');
    if (form_boxes.length > 0) {
        /*check form box configuration and set the actions*/
        Array.from(form_boxes).forEach(function(form_box) {
            var form = form_box.querySelector('form');
            var options = JSON.parse(form_box.getAttribute('options').replace(/\'/g, '\"'));

            options.search_id = form_box.getAttribute('search-id');
            var show_results = document.querySelector(options.show_results);
            if (show_results !== null) {
                if (options.async == 'async') {
                    form.addEventListener('change', () => {
                        ewp_search_action(form, options, show_results, 1);
                    });
                }
            }
            /*execute on load*/
            if (options.run_on_load) {
                ewp_search_action(form, options, show_results, 1);
            }

        });
    }
}

/**
 * handle the call to the server
 */
function ewp_search_action(form, options, show_results, paged) {
    document.body.classList.add('ewp-search-loading');
    /* set the data with the paged variable*/
    var send_data = jsVanillaSerialize(form);
    send_data.push("paged=" + paged);
    var defaults = {
        form: form,
        search_options: options,
        data: send_data,
        method: 'get',
        url: awmGlobals.url + "/wp-json/ewp-filter/" + options.search_id + "/",
        callback: 'ewp_search_form_callback',
        element: show_results
    };
    awm_ajax_call(defaults);
}


/**
 * show the results from search query
 */
function ewp_search_form_callback(response, options) {
    /*remove the content and display the content*/
    document.body.classList.remove('ewp-search-loading');
    options.element.innerHTML = response;

    /*check for pagination and set the event*/
    var pagination_links = options.element.querySelectorAll('a.page-numbers');
    if (pagination_links.length > 0) {
        Array.from(pagination_links).forEach(function(pagination_link) {
            pagination_link.addEventListener('click', () => {
                /*prevent click on link*/
                window.event.preventDefault();
                window.event.stopPropagation();
                var page = parseInt(pagination_link.innerText);
                /*make the query*/
                ewp_search_action(options.form, options.search_options, options.element, page)
            });
        });
    }

    /*set the hook for devs to do staff after the results*/
    var data = { response: response, options: options };
    const event = new CustomEvent("ewp_search_results_loaded", { detail: data });
    document.dispatchEvent(event);
}