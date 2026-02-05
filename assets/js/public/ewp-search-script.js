/**check when dom is ready to initatate the forms */

document.addEventListener('DOMContentLoaded', () => {
    EWPDynamicAssetLoader.log('Search script: DOMContentLoaded fired');
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
        Array.from(filters).forEach(function (filter) {
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
    EWPDynamicAssetLoader.log('Search script: ewp_search_forms() called');
    /*get the forms*/
    var form_boxes = document.querySelectorAll('.ewp-search-box');
    EWPDynamicAssetLoader.log('Search script: Found form boxes', { count: form_boxes.length });
    if (form_boxes.length > 0) {
        /*check form box configuration and set the actions*/
        Array.from(form_boxes).forEach(function (form_box, index) {
            EWPDynamicAssetLoader.log('Search script: Processing form box', { index: index, id: form_box.id });
            var form = form_box.querySelector('form');
            EWPDynamicAssetLoader.log('Search script: Form found', { hasForm: !!form });

            var options = JSON.parse(form_box.getAttribute('options').replace(/\'/g, '\"'));
            EWPDynamicAssetLoader.log('Search script: Options parsed', options);

            options.search_id = form_box.getAttribute('search-id');
            var show_results = document.querySelector(options.show_results);
            EWPDynamicAssetLoader.log('Search script: Show results element', {
                selector: options.show_results,
                found: !!show_results
            });
            if (show_results !== null) {
                if (options.async == 'async') {
                    EWPDynamicAssetLoader.log('Search script: Adding change event listener', { formId: form_box.id });
                    form.addEventListener('change', () => {
                        EWPDynamicAssetLoader.log('Search script: Change event fired', { formId: form_box.id });
                        ewp_search_action(form, options, show_results, 1);
                    });
                } else {
                    EWPDynamicAssetLoader.log('Search script: Async not enabled', { async: options.async });
                }
            } else {
                EWPDynamicAssetLoader.log('Search script: Show results element is null', { selector: options.show_results });
            }
            /*execute on load*/
            if (options.run_on_load) {
                EWPDynamicAssetLoader.log('Search script: Run on load enabled');
                let empty = true;

                if (options.run_on_load_empty) {
                    empty = false;
                } else {
                    form.querySelectorAll('input,select,textarea').forEach(function (input) {
                        if (input.value !== '' && input.type !== 'submit' && input.type !== 'button' && input.type !== 'hidden') {
                            empty = false;
                        }
                    });
                }
                if (!empty) {
                    EWPDynamicAssetLoader.log('Search script: Executing search on load', { empty: empty });
                    ewp_search_action(form, options, show_results, 1);
                } else {
                    EWPDynamicAssetLoader.log('Search script: Form is empty, skipping run on load');
                }
            }

        });
    } else {
        EWPDynamicAssetLoader.log('Search script: No form boxes found with selector .ewp-search-box');
    }
}

/**
 * handle the call to the server
 */
function ewp_search_action(form, options, show_results, paged) {
    EWPDynamicAssetLoader.log('Search script: ewp_search_action() called', {
        paged: paged,
        loadType: options.load_type,
        searchId: options.search_id
    });
    if (paged == 1 || options.load_type != 'button') {
        document.body.classList.add('ewp-search-loading');
    }
    /* set the data with the paged variable*/
    var send_data = ewp_jsVanillaSerialize(form);
    send_data.push("paged=" + paged);
    options.paged = paged;
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
    EWPDynamicAssetLoader.log('Search script: Callback received', {
        responseLength: response.length,
        searchId: options.search_options.search_id
    });
    /*remove the content and display the content*/
    document.body.classList.remove('ewp-search-loading');
    let display_div = options.element;
    /* swicth load type */
    let html_added = false;
    switch (options.search_options.pagination_styles.load_type) {
        case 'button':
            /*convert response to html element*/


            if (display_div.querySelector('.ewp-search-articles') !== null && options.search_options.paged > 1) {
                var response_element = document.createElement('div');
                response_element.innerHTML = response;
                var response_element = document.createElement('div');
                response_element.innerHTML = response;
                var results = response_element.querySelectorAll('.ewp-search-result');
                /*get all the html from the results variable*/
                Array.from(results).forEach(function (result) {
                    display_div.querySelector('.ewp-search-articles').appendChild(result);
                });
                /*check if we have add more button*/
                var load_more = response_element.querySelector('.ewp-load-more');
                if (load_more !== null) {
                    display_div.querySelector('.ewp-search-pagination').appendChild(load_more);
                }
                html_added = true;
            }
            break;
    }
    if (!html_added) {
        display_div.innerHTML = response;
    }

    /*check for pagination and set the event*/
    var pagination_links = display_div.querySelectorAll('a.page-numbers');
    if (pagination_links.length > 0) {
        Array.from(pagination_links).forEach(function (pagination_link) {
            pagination_link.addEventListener('click', () => {
                /*prevent click on link*/
                window.event.preventDefault();
                window.event.stopPropagation();
                var page = parseInt(pagination_link.innerText);
                if (isNaN(page)) {
                    page = parseInt(pagination_link.getAttribute('href').split('page/')[1]);
                }
                /*make the query*/
                ewp_search_action(options.form, options.search_options, display_div, page)
            });
        });
    }

    var load_more = display_div.querySelector('.ewp-load-more');
    if (load_more !== null) {

        load_more.addEventListener('click', () => {
            /*prevent click on link*/
            window.event.preventDefault();
            window.event.stopPropagation();
            var page = parseInt(load_more.getAttribute('data-page')) + 1;
            /*make the query*/
            ewp_search_action(options.form, options.search_options, display_div, page)
            load_more.remove();
        });
    }

    /*set the hook for devs to do staff after the results*/
    var data = { response: response, options: options };
    const event = new CustomEvent("ewp_search_results_loaded", { detail: data });
    document.dispatchEvent(event);
}

/*register ewp_sorting function when sorting exists in results*/
function ewp_sorting(element) {
    var form = element.closest('.ewp-search-results').getAttribute('data-form');
    /*add element value to the form*/
    var form_box = document.querySelector('#ewp-search-' + form);
    /*create input hidden to send the value*/

    if (form_box.querySelector('input[name="ewp_sorting"]') !== null) {
        form_box.querySelector('input[name="ewp_sorting"]').remove();
    }
    if (element.value === '') {
        return;
    }
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = "ewp_sorting";
    input.value = element.value;
    /*add the input inside the form*/
    form_box.querySelector('form').appendChild(input);

    /*submit the form*/
    ewp_apply_search_form(form);


}