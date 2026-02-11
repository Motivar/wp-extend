awm_auto_fill_inputs();
awm_toggle_password();
awmShowInputs();
awm_ensure_disabled_inputs();


function jsVanillaSerialize(form, returnAsObject = false) {
    return ewp_jsVanillaSerialize(form, returnAsObject);
}

// --- Editor utility: shared activation helpers (Safari-safe) ---
window.AWMEditorUtil = window.AWMEditorUtil || (function () {
    'use strict';
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

    function isTemplate(elem) {
        const wrapper = elem.closest('.awm-repeater-content');
        return wrapper && wrapper.classList.contains('temp-source');
    }

    function waitForEditor(id, callback, tries = 0) {
        if (window.tinymce && tinymce.get(id) && tinymce.get(id).initialized) {
            callback(tinymce.get(id));
            return;
        }
        if (tries > 40) return; // ~4s
        setTimeout(() => waitForEditor(id, callback, tries + 1), 100);
    }

    function activateVisual(id, editor) {
        // Preserve current scroll to avoid jump
        var y = window.scrollY;
        try { window.wpActiveEditor = id; } catch (e) { }
        if (isSafari && typeof window.switchEditors !== 'undefined' && typeof window.switchEditors.go === 'function') {
            try { window.switchEditors.go(id, 'tmce'); } catch (e) { }
            // Restore scroll ASAP after mode switch
            setTimeout(function(){ try { window.scrollTo(0, y); } catch (e) {} }, 0);
        }
        // Do not force-show or focus to prevent auto-scroll
        try { editor.save(); } catch (e) { }
    }

    function initNonRepeaterEditors() {
        const textareas = document.querySelectorAll('textarea.wp-editor-area');
        if (!textareas || !textareas.length) return;
        textareas.forEach((ta) => {
            if (!ta.id || isTemplate(ta)) return;
            waitForEditor(ta.id, (editor) => activateVisual(ta.id, editor));
        });
    }

    function runOnLoad() {
        initNonRepeaterEditors();
        setTimeout(initNonRepeaterEditors, 400);
        setTimeout(initNonRepeaterEditors, 1200);
    }

    return { isSafari, waitForEditor, activateVisual, initNonRepeaterEditors, runOnLoad };
})();

// Initialize non-repeater editors on load using the shared util
window.addEventListener('load', function () {
    if (window.AWMEditorUtil && typeof window.AWMEditorUtil.runOnLoad === 'function') {
        window.AWMEditorUtil.runOnLoad();
    }
});

/*!
 * Serialize all form data into a query string or object
 * (c) 2018 Chris Ferdinandi, MIT License, https://gomakethings.com
 * @param  {Node}    form           The form to serialize
 * @param  {Boolean} returnAsObject If true, return as an object; otherwise, return as a query string
 * @return {String|Object}          The serialized form data
 */
function ewp_jsVanillaSerialize(form, returnAsObject = false) {
    // Setup our serialized data
    var serialized = [];
    var exclude = ['option_page', 'action', '_wpnonce', '_wp_http_referer'];
    if (form) {
        var loopData = form.querySelectorAll('input, select, checkbox, textarea');
        // Loop through each field in the form

        if (form.elements) {
            loopData = form.elements;
        }
        for (var i = 0; i < loopData.length; i++) {
            var field = loopData[i];

            // Don't serialize fields without a name, submits, buttons, file and reset inputs, and disabled fields
            if (!field.name ||
                field.disabled ||
                field.type === "file" ||
                field.type === "reset" ||
                field.type === "submit" ||
                field.type === "button" ||
                exclude.includes(field.name)
            )
                continue;

            // If a multi-select, get all selections
            if (field.type === "select-multiple") {
                for (var n = 0; n < field.options.length; n++) {
                    if (!field.options[n].selected) continue;
                    serialized.push(
                        encodeURIComponent(field.name) +
                        "=" +
                        encodeURIComponent(field.options[n].value)
                    );
                }
            }

            // Convert field data to a query string
            else if (
                (field.type !== "checkbox" && field.type !== "radio") ||
                field.checked
            ) {
                serialized.push(
                    encodeURIComponent(field.name) + "=" + encodeURIComponent(field.value)
                );
            }

        }
    }
    // Convert serialized data to an object if returnAsObject is true
    if (returnAsObject) {
        var serializedObject = {};
        serialized.forEach(function (item) {
            var pair = item.split('=');
            var key = decodeURIComponent(pair[0]);
            var value = decodeURIComponent(pair[1] || '');
            if (key in serializedObject) {
                // Handle multiple values for the same key (e.g., checkboxes)
                if (!Array.isArray(serializedObject[key])) {
                    serializedObject[key] = [serializedObject[key]];
                }
                serializedObject[key].push(value);
            } else {
                serializedObject[key] = value;
            }
        });
        return serializedObject;
    }

    return serialized; // Return as a query string array if returnAsObject is false
}



function awm_ensure_disabled_inputs() {
    setTimeout(() => {
        var repeaters = document.querySelectorAll('.awm-repeater-content.temp-source');
        if (repeaters) {
            for (var i = 0; i < repeaters.length; i++) {
                var inputs = repeaters[i].querySelectorAll('input,select,textarea');
                for (var j = 0; j < inputs.length; j++) {
                    inputs[j].setAttribute('disabled', 'disabled');
                }

            }
        }
    }, 250);
}

/**
 * function to parse select with slim
 */
function awm_selectr_box(elem) {
    var id = elem.id;

    var showSearch = elem.length > 3 ? true : false;
    var data = [];
    var soptions = elem.options;
    var selected_options = [];
    var no_show = [];
    for (var option of soptions) {
        if (option.selected) {
            selected_options.push(option.value);
        }
    }
    var optgroups = elem.getElementsByTagName('optgroup');
    if (optgroups.length > 0) {
        for (var o = 0; o < optgroups.length; o++) {
            var html_value = optgroups[o].getAttribute('data-html') ? JSON.parse(optgroups[o].getAttribute('data-html').replace(/\'/g, '\"')) : '';
            var obj = {
                label: html_value,
                options: [],
                placeholder: false
            };
            var opt_options = optgroups[o].getAttribute('options').split(',');
            for (var opt = 0; opt < opt_options.length; opt++) {
                for (var i = 0; i < soptions.length; i++) {
                    if (opt_options[opt] === soptions[i].value) {
                        obj.options.push(awm_select_box_values(soptions[i], selected_options));
                        no_show.push(soptions[i].value);
                        break;
                    }

                }
            }
            data.push(obj);

        }
    }
    for (var i = 0; i < soptions.length; i++) {
        if (!no_show.includes(soptions[i].value)) {
            data.push(awm_select_box_values(soptions[i], selected_options));
        }
    }
    data.sort(function (a, b) {
        return b.placeholder - a.placeholder;
    });


    var slim_options = {
        select: elem,
        data: data,
        settings: {
            showSearch: showSearch,
            searchPlaceholder: awmGlobals.strings.searchText,
            searchText: awmGlobals.strings.noResults,
            placeholderText: awmGlobals.strings.placeholderText,
            allowDeselect: true,
        }
    };
    if (document.getElementById(id + '_select')) {
        slim_options.settings.contentLocation = document.getElementById(id + '_select');
        slim_options.settings.contentPosition = 'absolute';

    }
    new SlimSelect(slim_options);

}



function awm_select_box_values(option, selected_options) {

    var html_value = option.getAttribute('data-html') ? JSON.parse(option.getAttribute('data-html').replace(/(^'|'$)/g, '\"')) : '';

    var selected = selected_options.includes(option.value) ? true : false;
    var placeholder = option.getAttribute('data-placeholder') ? (option.getAttribute('data-placeholder') === 'true' ? true : false) : false;
    var text = option.text;
    if (placeholder) {
        text = '';
    }
    var obj = {
        text: text,
        value: option.value,
        innerHTML: html_value,
        selected: selected,
        placeholder: placeholder
    };
    return obj;
}



/**
 * this function is used to toggle the password to show text or not
 */
function awm_toggle_password() {
    document.querySelectorAll('[data-toggle="password"]').forEach(function (el) {
        el.addEventListener("click", function (e) {
            var target = document.getElementById(el.getAttribute('data-id'));
            var type = target.getAttribute('type') === 'password' ? 'text' : 'password';
            target.setAttribute('type', type);
        });
    });
}

/**
 this function is used in order to get all the inputs tha will be autofilled by others
 */
function awm_auto_fill_inputs() {
    var elems = document.querySelectorAll('input[fill-from]');
    if (elems) {
        elems.forEach(function (elem) {
            var origin = elem.getAttribute('fill-from');
            var element = document.getElementById(origin);
            if (element) {
                element.addEventListener('change', function () {
                    elem.value = element.value;
                });
            }
        });
    }
}


function awm_open_tab(evt, div) {
    var i, awm_tabcontent, awm_tablinks;
    div = div.trim();
    awm_tabcontent = document.getElementsByClassName("awm_tabcontent");
    for (i = 0; i < awm_tabcontent.length; i++) {
        awm_tabcontent[i].style.display = "none";
    }
    awm_tablinks = document.getElementsByClassName("awm_tablinks");

    for (i = 0; i < awm_tablinks.length; i++) {
        awm_tablinks[i].className = awm_tablinks[i].className.replace(" active", "");
    }
    document.getElementById(div + '_content_tab').style.display = "block";
    evt.currentTarget.className += " active";

    /*open the first*/
}

if (document.getElementsByClassName("awm_custom_image_image_uploader_field-show").length) {
    var clickables = document.getElementsByClassName("awm-tab-show");
    clickables[0].click()
}

/**
 * Legacy AJAX GET call - redirects to awm_ajax_call
 * 
 * @deprecated Use awm_ajax_call() directly with method: 'GET'
 * @param {string} url Target URL for the GET request
 * @param {string} js_callback Callback function name to execute on success
 * @return {boolean} Returns true if request was initiated
 */
function awm_js_ajax_call(url, js_callback) {
    return awm_ajax_call({
        method: 'GET',
        url: url,
        callback: js_callback
    });
}

function awmCallbacks() {
    var elems = document.querySelectorAll('input[data-callback],select[data-callback],textarea[data-callbak]');
    if (elems) {
        elems.forEach(function (elem) {
            if (!elem.classList.contains('awm-callback-checked')) {
                awm_check_call_back(elem, false);
                elem.addEventListener("change", function () {
                    awm_check_call_back(elem, true);
                });
                elem.classList.add('awm-callback-checked')
            }

        });
    }
}

function awm_check_call_back(elem, action) {
    var call_back = window[elem.getAttribute('data-callback')];

    if (typeof call_back == 'function') {
        call_back(elem, action);

    } else {
        console.log(elem.getAttribute('data-callback') + ' function does not exist!');
    }
}

function awmInitForms() {
    var forms = document.querySelectorAll('form');
    if (forms) {

        forms.forEach(function (form) {
            if (document.getElementById('publish')) {
                document.getElementById('publish').addEventListener('click', function (e) {
                    if (!awmCheckValidation(form).check) {
                        awmShowError();
                        e.preventDefault();
                    }
                });
            } else {
                form.addEventListener('submit', function (e) {
                    if (!awmCheckValidation(form).check) {
                        awmShowError();
                        e.preventDefault();
                    }
                }, false);
            }

        });
    }
}

function awmShowError() {
    /*scroll to first item with class .awm-form-error*/
    var firstError = document.querySelector('.awm-form-error');
    if (firstError) {
        firstError.scrollIntoView();
    };
}






function awmCheckValidation(form) {
    var check = true;
    var error = '';
    var requireds = form.querySelectorAll('.awm-needed:not(.awm_no_show)');

    function isInputValid(input) {
        return input.value.replace(/\s/g, '') !== '';
    }

    function isCheckboxMultipleValid(inputs) {
        return Array.from(inputs).some(input => input.type === 'checkbox' && input.checked);
    }

    function isValidRequiredElement(element) {
        // Check if the parent has the class "awm-repeater-content" with "data-counter=template"
        var parent = element.closest('.awm-repeater-content[data-counter="template"]');
        return !parent;
    }

    requireds.forEach(function (required) {
        if (check && isValidRequiredElement(required)) {
            var type = required.getAttribute('data-type');
            var inputs = required.querySelectorAll('input:not(:disabled), select:not(:disabled), textarea:not(:disabled)');
            if (inputs.length > 0) {
                required.classList.remove("awm-form-error");

                switch (type) {
                    case 'checkbox_multiple':
                        if (!isCheckboxMultipleValid(inputs)) {
                            check = false;
                            error = required;
                            required.classList.add("awm-form-error");
                        }
                        break;
                    default:
                        if (inputs.length === 0 || !isInputValid(inputs[0])) {
                            check = false;
                            error = required;
                            required.classList.add("awm-form-error");
                        }
                        break;
                }
            }
        }
    });

    return { check: check, error: error };
}




function awmShowInputs() {
    var elems = document.querySelectorAll('div[show-when]:not(.awm-initialized),tr[show-when]:not(.awm-initialized)');
    if (elems) {
        elems.forEach(function (elem) {
            var parent = elem;

            var inputs = JSON.parse(elem.getAttribute('show-when').replace(/\'/g, '\"'));
            for (var p in inputs) {
                var element = document.getElementById(p)
                if (element && element !== null && typeof element === 'object') {
                    element.addEventListener('change', function () {
                        var shouldShow = false;

                        switch (element.tagName) {
                            case 'SELECT':
                                if (this.value in inputs[p].values) {
                                    if (inputs[p].values[this.value]) {
                                        shouldShow = true;
                                    }
                                }
                                break;
                            case 'INPUT':
                                switch (element.getAttribute('type')) {
                                    case 'checkbox':
                                        if (element.checked == inputs[p].values) {
                                            shouldShow = true;
                                        }
                                        break;
                                }
                                break;
                        }

                        if (shouldShow) {
                            parent.classList.remove('awm_no_show');
                            awmToggleDisabledInputs(parent, false);
                        } else {
                            parent.classList.add('awm_no_show');
                            awmToggleDisabledInputs(parent, true);
                        }
                    });
                    element.dispatchEvent(new window.Event('change', { bubbles: true }));
                }
            }
            elem.classList.add('awm-initialized')
        });
    }
}

function awmToggleDisabledInputs(container, disable) {
    var inputElements = container.querySelectorAll('input, select, textarea, button');
    inputElements.forEach(function (input) {
        if (disable) {
            input.setAttribute('disabled', 'disabled');
        } else {
            input.removeAttribute('disabled');
        }
    });
}



function awm_create_calendar() {
    var values = [];
    jQuery('.awm_cl_date:not(.hasDatepicker)').each(function () {
        var idd = jQuery(this).attr('id');
        var extra_parameters = jQuery(this).attr('date-params') ? jQuery.parseJSON(jQuery(this).attr('date-params').replace(/\'/g, '\"')) : {};
        var value = jQuery('#' + idd).val();
        var default_parameters = {
            dateFormat: 'dd-mm-yy',
            changeMonth: false,
            altFormat: 'YYYY-DD-MM',
            minDate: '-24M',
            maxDate: '+24M',
        };


        const parameters = {
            ...default_parameters,
            ...extra_parameters,
        };
        if (jQuery(this).hasClass('awm-no-limit-date')) {
            parameters.minDate = null;
        }

        parameters.onSelect = function (d, i) {
            if (d !== i.lastVal) {
                /*check for jquery events*/
                var stop = false;
                var date = jQuery('#' + idd).datepicker('getDate');
                if (date !== null) {
                    var change = jQuery('#' + idd).attr('data-change');
                    var max_days = jQuery('#' + idd).attr('data-maxDays');
                    if (change != '' && change) {
                        var next_date = jQuery('#' + change).datepicker('getDate');
                        var add_days = jQuery('#' + change).attr('data-days') ? parseInt(jQuery('#' + change).attr('data-days')) : 1;
                        if (next_date !== null) {
                            if (awm_timestamp(date) > awm_timestamp(next_date)) {
                                stop = true;
                            }
                        }
                        date.setDate(date.getDate() + add_days);
                        jQuery('#' + change).datepicker('option', 'minDate', date);
                        if (stop) {
                            jQuery('#' + change).datepicker('setDate', date);
                        }

                        if (max_days) {
                            /*var date2 = jQuery('#' + change).datepicker('getDate', '+' + parseInt(max_days) + 'd');
                            date2.setDate(date2.getDate() + 1);
                            jQuery('#' + change).datepicker('option', 'maxDate', date2);*/
                        }
                    }
                }

                document.getElementById(idd).dispatchEvent(new Event('change'));
            }
        };

        values.push({ 'id': idd, 'value': jQuery('#' + idd).val() });
        jQuery('#' + idd).datepicker(parameters);

    });



    values.forEach(function (val) {

        jQuery('#' + val.id).datepicker('setDate', '');
        if (val.value != '' && val.value != 0) {
            jQuery('#' + val.id).datepicker('setDate', val.value);
            jQuery('#' + val.id).change();
        }
    });
}




function awm_timestamp(d) {
    "use strict";
    d = new Date(d);
    d = d.setUTCHours(24, 0, 0, 0);
    return (d / 1000);
}


/**
 * Handles the reordering of repeater elements when moving up or down
 * 
 * @param {HTMLElement} elem - The DOM element that triggered the action
 * @param {boolean} action - True for move up, false for move down
 */
function awm_repeater_order(elem, action) {
    try {
        // Get the repeater container element
        var repeater_div = elem.closest('.awm-repeater-content');
        if (repeater_div) {
            var repeater = repeater_div.getAttribute('data-id');
            var counter = parseInt(repeater_div.getAttribute('data-counter'));
            var parent = repeater_div.parentNode;

            // Find the element to swap with based on action (up or down)
            var targetElement = action ? repeater_div.previousElementSibling : repeater_div.nextElementSibling;

            // Only proceed if there's an element to swap with and it's not the template
            if (targetElement && !targetElement.classList.contains('temp-source')) {
                var targetCounter = parseInt(targetElement.getAttribute('data-counter'));

                console.log('Swapping element', counter, action ? 'up with' : 'down with', targetCounter);

                // Execute all reordering actions synchronously
                awm_execute_reorder_actions(repeater_div, targetElement, repeater, counter, targetCounter, action);


                return true;
            }
        }
        return false;
    } catch (error) {
        console.error('Error in awm_repeater_order:', error);
        console.error('Error stack:', error.stack);
        return false;
    }
}

/**
 * Execute all reordering actions synchronously
 * 
 * @param {HTMLElement} repeater_div - The repeater element being moved
 * @param {HTMLElement} targetElement - The target element to swap with
 * @param {string} repeater - Repeater ID
 * @param {number} counter - Original counter of moving element
 * @param {number} targetCounter - Original counter of target element
 * @param {boolean} action - True for move up, false for move down
 */
function awm_execute_reorder_actions(repeater_div, targetElement, repeater, counter, targetCounter, action) {
    console.log('Step 1: Calculating DOM positions...');
    // Store original positions for DOM manipulation
    var nextSibling = action ? targetElement : repeater_div.nextElementSibling.nextElementSibling;

    console.log('Step 2: Swapping data-counter attributes...');
    // Swap data-counter attributes
    repeater_div.setAttribute('data-counter', targetCounter);
    targetElement.setAttribute('data-counter', counter);

    console.log('Step 3: Updating element IDs...');
    // Update IDs to reflect new positions
    repeater_div.id = 'awm-' + repeater + '-' + targetCounter;
    targetElement.id = 'awm-' + repeater + '-' + counter;

    console.log('Step 4: Swapping input attributes...');
    // Preserve all input values and update their names/ids
    swapInputAttributes(repeater_div, targetElement, repeater, counter, targetCounter);

    console.log('Step 5: Moving DOM elements...');
    // Move the DOM elements
    repeater_div.parentNode.insertBefore(repeater_div, nextSibling);

    console.log('Step 6: All DOM operations completed');
}


/**
 * Get wp-editor arguments from localized PHP configuration
 * Uses DRY principle by getting config from awm_get_wp_editor_args()
 * 
 * @param {string} editorId - The ID of the editor
 * @return {Object} TinyMCE configuration object
 */
function awm_get_tinymce_args(editorId) {
    // Get localized wp_editor args from PHP
    const wpEditorArgs = (typeof awmGlobals !== 'undefined' && awmGlobals.wpEditorArgs)
        ? awmGlobals.wpEditorArgs
        : {};

    // Helper to convert a stringified function to a real function
    function awmStringToFunction(maybeFn) {
        if (typeof maybeFn !== 'string') return maybeFn;
        const s = maybeFn.trim();
        if (!s) return undefined;
        // Accept only plain function syntax for safety
        const looksLikeFn = s.startsWith('function');
        if (!looksLikeFn) return undefined;
        try {
            // Wrap in parentheses so it evaluates to a function expression
            /* eslint no-eval: 0 */
            const fn = eval('(' + s + ')');
            return typeof fn === 'function' ? fn : undefined;
        } catch (e) {
            return undefined;
        }
    }

    // Base TinyMCE configuration
    const baseConfig = {
        selector: '#' + editorId,
        theme: 'modern',
        skin: 'lightgray',
        language: 'en',
        relative_urls: false,
        remove_script_host: false,
        convert_urls: false,
        browser_spellcheck: true,
        fix_list_elements: true,
        entities: '38,amp,60,lt,62,gt',
        entity_encoding: 'raw',
        keep_styles: false,
        paste_webkit_styles: 'font-weight font-style color',
        paste_strip_class_attributes: 'mso',
        paste_remove_spans: true,
        paste_remove_styles: true,
        paste_auto_cleanup_on_paste: true,
        wpeditimage_disable_captions: false,
        wpeditimage_html5_captions: true,
        plugins: 'charmap,colorpicker,hr,lists,media,paste,tabfocus,textcolor,fullscreen,wordpress,wpautoresize,wpeditimage,wpgallery,wplink,wpdialogs,wpview',
        formats: {
            alignleft: [
                { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li', styles: { textAlign: 'left' } },
                { selector: 'img,table,dl.wp-caption', classes: 'alignleft' }
            ],
            aligncenter: [
                { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li', styles: { textAlign: 'center' } },
                { selector: 'img,table,dl.wp-caption', classes: 'aligncenter' }
            ],
            alignright: [
                { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li', styles: { textAlign: 'right' } },
                { selector: 'img,table,dl.wp-caption', classes: 'alignright' }
            ],
            strikethrough: { inline: 'del' }
        },
        setup: function (editor) {
            // Ensure editor shows properly and is interactive
            editor.on('init', function () {
                editor.show();
            });

            // Save content on change
            editor.on('change', function () {
                editor.save();
            });

            // Save content on blur
            editor.on('blur', function () {
                editor.save();
            });
        }
    };

    // Merge PHP configuration with base config (normalize callbacks)
    if (wpEditorArgs.tinymce) {
        const phpTiny = Object.assign({}, wpEditorArgs.tinymce);
        // Normalize callback fields that TinyMCE expects to be functions
        if (typeof phpTiny.setup === 'string') {
            const parsedSetup = awmStringToFunction(phpTiny.setup);
            if (parsedSetup) {
                phpTiny.setup = parsedSetup;
            } else {
                delete phpTiny.setup; // fallback to baseConfig.setup
            }
        }
        Object.assign(baseConfig, phpTiny);
    }

    // Apply other wp_editor settings
    if (wpEditorArgs.wpautop !== undefined) {
        baseConfig.wpautop = wpEditorArgs.wpautop;
    }

    return baseConfig;
}

/**
 * Initialize wp-editor in repeater context
 * Handles TinyMCE editor setup for cloned repeater fields
 * 
 * @param {string} editorId - The ID of the wp-editor textarea
 */
function awm_initialize_repeater_wp_editor(editorId) {
    // Immediate check to prevent multiple simultaneous inits
    if (window.awmEditorInitializing && window.awmEditorInitializing[editorId]) {
        return; // Already initializing this editor
    }
    
    // Mark as initializing globally
    if (!window.awmEditorInitializing) window.awmEditorInitializing = {};
    window.awmEditorInitializing[editorId] = true;
    
    // Wait longer to ensure any other init processes complete first
    setTimeout(() => {
        // First, properly destroy any existing editor instance
        if (typeof tinymce !== 'undefined') {
            // Ensure wrapper is in a clean state and hidden during init to avoid UI glitches
            var wrap = document.getElementById('wp-' + editorId + '-wrap');
            var container = document.getElementById('wp-' + editorId + '-editor-container');
            if (wrap) {
                wrap.classList.remove('html-active');
                wrap.classList.add('tmce-active');
                // Hide visually but keep layout to prevent jumps
                wrap.style.visibility = 'hidden';
            }
            // Remove any existing QuickTags toolbar for this cloned editor to prevent duplicates
            var qtToolbar = document.getElementById('qt_' + editorId + '_toolbar');
            if (qtToolbar && qtToolbar.parentNode) {
                qtToolbar.parentNode.removeChild(qtToolbar);
            }
            // Check if there's an existing editor instance
            if (tinymce.get(editorId)) {
                // Save content to textarea before removing
                tinymce.get(editorId).save();
                // Remove the editor instance
                tinymce.remove('#' + editorId);
            }

            // Nuclear cleanup: remove ALL TinyMCE instances related to this editor
            if (container) {
                // First pass: remove all TinyMCE containers
                const mceContainers = container.querySelectorAll('.mce-tinymce, .mce-container');
                mceContainers.forEach(function(el) { el.remove(); });
                
                // Second pass: remove any remaining non-textarea, non-quicktags children
                Array.prototype.slice.call(container.children).forEach(function(child) {
                    if (child.tagName && child.tagName.toLowerCase() === 'textarea') return;
                    if (child.id && child.id.indexOf('qt_') === 0) return; // Keep QuickTags
                    child.remove();
                });
            }
            
            // Global cleanup: find and remove any orphaned TinyMCE instances
            document.querySelectorAll('.mce-tinymce').forEach(function(mceEl) {
                // Check if this belongs to our editor by iframe ID or container proximity
                const iframe = mceEl.querySelector('iframe');
                if (iframe && iframe.id) {
                    const baseId = editorId.replace(/template/g, '').replace(/__/g, '_').replace(/_+/g, '_');
                    if (iframe.id.indexOf(baseId) !== -1 || iframe.id.indexOf(editorId) !== -1) {
                        mceEl.remove();
                    }
                }
            });

            // Force remove any existing TinyMCE instances by all possible ID variations
            const possibleIds = [
                editorId,
                editorId.replace(/_/g, '__'), // double underscore variant
                editorId.replace(/template/g, '0'), // template replacement variant
            ];
            possibleIds.forEach(function(id) {
                if (tinymce.get(id)) {
                    tinymce.remove('#' + id);
                }
            });

            // Get our custom TinyMCE configuration
            const tinymceConfig = awm_get_tinymce_args(editorId);

            // Initialize the new editor instance with custom configuration
            tinymce.init(tinymceConfig);
            // After init, ensure Visual is active and editor is interactive (Safari-safe)
            if (window.AWMEditorUtil) {
                window.AWMEditorUtil.waitForEditor(editorId, function (editor) {
                    window.AWMEditorUtil.activateVisual(editorId, editor);
                    // Reveal wrapper now that TinyMCE is ready
                    if (wrap) {
                        // Prefer reveal on skin loaded for stable UI
                        var reveal = function(){
                            try { editor.execCommand('mceRepaint'); } catch (e) {}
                            wrap.style.visibility = '';
                            // Clear the global init flag
                            if (window.awmEditorInitializing) {
                                delete window.awmEditorInitializing[editorId];
                            }
                        };
                        try {
                            editor.once('SkinLoaded', reveal);
                            // Fallback in case event doesn't fire
                            setTimeout(reveal, 300);
                        } catch (e) { setTimeout(reveal, 300); }
                    }
                    // Reinitialize QuickTags toolbar once after TinyMCE is set up
                    try {
                        if (window.quicktags) {
                            quicktags({ id: editorId });
                            if (window.QTags && typeof QTags._buttonsInit === 'function') {
                                QTags._buttonsInit();
                            }
                        }
                    } catch (e) { /* noop */ }
                });
            }
        }
    }, 500); // Longer delay to prevent race conditions
}

/**
 * Helper function to swap input attributes between two repeater elements
 * 
 * @param {HTMLElement} elem1 - First repeater element
 * @param {HTMLElement} elem2 - Second repeater element
 * @param {string} repeater - Repeater ID
 * @param {number} counter1 - Original counter of first element
 * @param {number} counter2 - Original counter of second element
 */
function swapInputAttributes(elem1, elem2, repeater, counter1, counter2) {
    // Process all inputs in both elements
    updateInputAttributes(elem1, repeater, counter1, counter2);
    updateInputAttributes(elem2, repeater, counter2, counter1);
}

/**
 * Updates input attributes in a repeater element
 * 
 * @param {HTMLElement} elem - Repeater element to update
 * @param {string} repeater - Repeater ID
 * @param {number} oldCounter - Original counter value
 * @param {number} newCounter - New counter value
 */
function updateInputAttributes(elem, repeater, oldCounter, newCounter) {
    // Update all input elements
    var inputs = elem.querySelectorAll('input, select, textarea');
    inputs.forEach(function(input) {
        // Update name attribute
        if (input.name) {
            input.name = input.name.replace(
                new RegExp(repeater + '\\[' + oldCounter + '\\]', 'g'), 
                repeater + '[' + newCounter + ']'
            );
        }
        
        // Update id attribute - but skip wp-editor textareas
        if (input.id && !input.classList.contains('wp-editor-area')) {
            input.id = input.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );
        }
    });
    
    // Update data-input attributes on containers
    var containers = elem.querySelectorAll('[data-input]');
    containers.forEach(function(container) {
        var dataInput = container.getAttribute('data-input');
        if (dataInput) {
            container.setAttribute('data-input', dataInput.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            ));
        }
        
        // Also update the id attribute if it contains the element prefix
        if (container.id && container.id.includes('awm-element-')) {
            container.id = container.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );
        }
    });
    
    // Update media field containers (unified image/gallery)
    var mediaContainers = elem.querySelectorAll('.awm-media-field');
    mediaContainers.forEach(function (container) {
        if (container.id) {
            container.id = container.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );
        }
        /* Reset data-awm-init so AWMMediaField re-initialises the cloned field */
        container.removeAttribute('data-awm-init');
    });


    var inputs = elem.querySelectorAll('textarea.wp-editor-area');
    if (inputs) {
        inputs.forEach(function (input) {
            awm_initialize_repeater_wp_editor(input.id);
        });
    }

}
function ewp_repeater_clone_row(elem) {
    // Find the repeater content div that contains this element
    const repeater_div = elem.closest('.awm-repeater-content');

    // Find the parent repeater that contains this element
    const repeater_parent = elem.closest('.repeater');

    if (!repeater_div || !repeater_parent) return false;

    // Get the repeater ID from the repeater content div
    const repeaterId = repeater_div.getAttribute('data-id');

    // Store all input values from the current row
    const originalValues = {};
    const inputs = repeater_div.querySelectorAll('input:not([disabled]), select:not([disabled]), textarea:not([disabled])');

    // Create a map of all input values by their input-key attribute
    inputs.forEach(function (input) {
        const key = input.getAttribute('input-key');
        if (key) {
            if (input.type === 'checkbox' || input.type === 'radio') {
                originalValues[key] = input.checked;
            } else {
                originalValues[key] = input.value;
            }
        }
    });

    // Find the template source for this specific repeater
    const templateSource = repeater_parent.querySelector(`.awm-repeater-content.temp-source[data-id="${repeaterId}"]`);
    if (!templateSource) return false;
    console.log(templateSource);
    // Call the repeater function with the add button from the template source
    const addButton = templateSource.querySelector(`.awm-repeater-add[data-id="${repeaterId}"] .awm-add`);
    console.log(addButton);
    if (addButton) {
        repeater(addButton, originalValues);
    }
}
/**
 * 
 * @param domobject elem 
 */
function repeater(elem, prePopulated = []) {
    var repeater_div = elem.closest('.awm-repeater');

    var maxRows = repeater_div.getAttribute('maxrows') ? parseInt(repeater_div.getAttribute('maxrows')) : 0;
    var repeater = repeater_div.getAttribute('data-id');

    var clicked_element = elem.closest('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content[data-id="' + repeater + '"]');
    var add = false;
    if (elem.classList.contains('awm-add')) {
        add = true;
        var last_element = document.querySelectorAll('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content:not(.temp-source)[data-id="' + repeater + '"]');
        var old_counter = last_element.length - 1;
        switch (last_element.length) {
            case 1:
                old_counter = 0;
                break;
            case 0:
                old_counter = -1;
                break;
        }
        var new_counter = old_counter + 1;
        if (maxRows == 0 || new_counter < maxRows) {
            var template = document.querySelector('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content.temp-source[data-id="' + repeater + '"]');
            var cloned = template.cloneNode(true);
            cloned.classList.add('cloned');
            cloned.classList.remove('temp-source');

            var inner = cloned.innerHTML;
            var res = inner.replace(/template/g, new_counter);
            cloned.innerHTML = res;

            var selects = cloned.querySelectorAll('.ss-main');
            if (selects) {
                selects.forEach(function (select) {
                    select.innerHTML = '';
                });
            }
            var inputs = cloned.querySelectorAll('input,select,textarea');
            if (inputs) {
                inputs.forEach(function (input) {
                    input.removeAttribute('disabled');
                    input.removeAttribute('readonly');
                    input.classList.remove('hasDatepicker');
                    input.classList.remove('awm-callback-checked');
                    input.removeAttribute('data-id');
                    input.removeAttribute('style');
                    input.removeAttribute('checked');
                });
            }
            cloned = awm_repeater_clone(cloned, new_counter, repeater);
            cloned.querySelector(':scope > .awm-actions .awm-repeater-add').innerHTML = '';
            cloned.querySelector('input[input-key="awm_key"]').value = new Date().getTime() + new_counter;
            jQuery('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content.temp-source[data-id="' + repeater + '"]').before(cloned);
            cloned.classList.remove('cloned');
            template.parentNode.insertBefore(cloned, template);
            var template_inputs = template.querySelectorAll('input,select,textarea');
            if (template_inputs) {
                template_inputs.forEach(function (input) {
                    input.setAttribute('disabled', true);
                    input.setAttribute('readonly', true);
                });
            }
            awm_init_inputs();
            var inputs = cloned.querySelectorAll('input,select,textarea');

            if (inputs) {
                inputs.forEach(function (input) {
                    if (input.classList.contains('wp-editor-area')) {
                        awm_initialize_repeater_wp_editor(input.id);

                    }
                    var inputKey = input.getAttribute('input-key') || '';
                    if (inputKey && prePopulated[inputKey]) {
                        input.value = prePopulated[inputKey];
                        input.dispatchEvent(new Event('change'));
                    }
                });
            }

        }
    } else {
        var rep_wrapper = elem.closest('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content[data-id="' + repeater + '"]');
        rep_wrapper.querySelectorAll('input,select,textarea').forEach(function (input) {
            input.value = 0;
            input.dispatchEvent(new Event('change'));
        });
        elem.closest('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content[data-id="' + repeater + '"]').outerHTML = '';
    }
    var elements = document.querySelectorAll('.awm-repeater[data-id="' + repeater + '"] > input:last-child,.awm-repeater[data-id="' + repeater + '"] > select:last-child,.awm-repeater[data-id="' + repeater + '"] > textarea:last-child');
    var data = { repeater_id: repeater, add: add, elem: clicked_element };
    var event = new CustomEvent("awm_repeater_change_row", { detail: data });
    document.dispatchEvent(event);
    if (elements.length > 0) {
        var last = elements[elements.length - 1];
        last.dispatchEvent(new Event('change'));
    }
    return true;


}


function awm_repeater_clone(cloned, new_counter, repeater) {
    cloned.setAttribute('data-counter', new_counter);
    // Replace the problematic :scope selector with direct children selection
    var inputsAll = cloned.querySelectorAll('input,select,textarea');

    if (inputsAll && inputsAll.length > 0) {
        inputsAll.forEach(function (input) {
            var old_id = input.getAttribute('id');
            var label = cloned.querySelector('label[for="' + old_id + '"]');
            var namee, id;
            if (input.name) {
                // Get input-name and input-key attributes with fallbacks
                var inputName = input.getAttribute("input-name") || repeater;
                var inputKey = input.getAttribute("input-key") || '';
                
                // For wp-editor fields, if input-key is missing, try to get it from the label
                if (input.classList.contains('wp-editor-area') && (!inputKey || inputKey === '')) {
                    // Find the editor container
                    var editorContainer = input.closest('.awm-wp-editor');
                    if (editorContainer) {
                        // Try to get key from label text
                        var editorLabel = editorContainer.querySelector('.awm-input-label span');
                        if (editorLabel && editorLabel.textContent) {
                            inputKey = editorLabel.textContent.toLowerCase().trim();
                            // Update the input-key attribute for future reference
                            input.setAttribute('input-key', inputKey);
                        }
                    }
                }
                
                // Create proper name and id attributes
                namee = inputName + "[" + new_counter + "]" + "[" + inputKey + "]";
                id = namee.replace(/\[/g, '_').replace(/\]/g, '_');
                input.setAttribute("name", namee);
                input.setAttribute("id", id);
            }
            if (label) {
                label.setAttribute('for', id);
            }
            var image_input = input.closest('.awm-meta-field');
            if (image_input && image_input.classList.contains('awm-custom-image-meta')) {
                // Handle the main image container
                var imageContainer = image_input;
                if (imageContainer) {
                    // Get the label text to use as key if needed
                    var imageLabel = imageContainer.querySelector('.awm-input-label');
                    var labelText = '';
                    if (imageLabel) {
                        labelText = imageLabel.textContent.toLowerCase().trim();
                    }
                    
                    // Update data-input attribute
                    imageContainer.setAttribute('data-input', id);
                    
                    // Update media field container (unified image/gallery)
                    var mediaField = imageContainer.querySelector('.awm-media-field');
                    if (mediaField) {
                        mediaField.setAttribute('id', id + '-field');
                        mediaField.setAttribute('data-id', id);
                        /* Reset init flag so AWMMediaField re-initialises */
                        mediaField.removeAttribute('data-awm-init');
                        
                        // Find and update hidden input fields
                        var hiddenInputs = mediaField.querySelectorAll('input[type="hidden"]');
                        hiddenInputs.forEach(function (hiddenInput) {
                            // If input-key is missing or empty, use the label text
                            if (!hiddenInput.getAttribute('input-key') || hiddenInput.getAttribute('input-key') === '') {
                                hiddenInput.setAttribute('input-key', labelText);
                            }
                            // Update name and id attributes
                            var inputName = hiddenInput.getAttribute('input-name') || repeater;
                            var inputKey = hiddenInput.getAttribute('input-key') || labelText;
                            var isSingle = mediaField.getAttribute('data-max') === '1';
                            var newName = inputName + '[' + new_counter + '][' + inputKey + ']' + (isSingle ? '' : '[]');
                            var newId = newName.replace(/\[/g, '_').replace(/\]/g, '_');
                            hiddenInput.setAttribute('name', newName);
                            hiddenInput.setAttribute('id', newId);
                        });

                        // Update the button data-id
                        var mediaBtn = mediaField.querySelector('.awm-media-field-button');
                        if (mediaBtn) {
                            mediaBtn.setAttribute('data-id', id);
                        }
                    }
                }
            }

        });
    }
    cloned.setAttribute('id', 'awm-' + repeater + '-' + new_counter);
    cloned.setAttribute('data-id', repeater);
    return cloned;
}


function awm_serialize_data(obj, prefix) {

    var str = [],
        p;
    for (p in obj) {
        if (obj.hasOwnProperty(p)) {
            var k = prefix ? prefix + "[" + p + "]" : p,
                v = obj[p];
            str.push((v !== null && typeof v === "object") ?
                awm_serialize_data(v, k) :
                encodeURIComponent(k) + "=" + encodeURIComponent(v));
        }
    }
    return str.join("&");


}
/**
 * AJAX Call Function
 * 
 * Performs AJAX requests with WordPress REST API integration.
 * Supports both GET and POST methods with automatic nonce handling.
 * 
 * @param {Object} options Configuration options for the AJAX request
 * @param {string} options.method HTTP method (GET or POST, default: POST)
 * @param {Object|Array} options.data Data to send with request
 * @param {string} options.url Target URL for the request
 * @param {Array} options.headers Array of header objects {header: 'name', value: 'value'}
 * @param {string|Function} options.callback Success callback function name or function reference
 * @param {string|Function} options.errorCallback Error callback function name or function reference (called on 4xx errors)
 * @param {boolean} options.log Enable console logging for debugging
 * @param {HTMLElement|string} options.element Element to pass to callback
 * @return {boolean} Returns true if request was initiated
 * 
 * @since 1.0.0
 */
function awm_ajax_call(options) {
    try {
        // Default configuration options
        var defaults = {
            method: 'POST',
            data: {},
            url: '',
            headers: [
                { 'header': 'Content-Type', 'value': 'application/json' },
                { 'header': 'X-WP-Nonce', 'value': awmGlobals.nonce }
            ],
            callback: false,
            errorCallback: false,
            log: false,
            element: false
        };

        // Merge user options with defaults
        const Options = { ...defaults, ...options };

        EWPDynamicAssetLoader.log('Initializing AJAX request', {
            method: Options.method,
            url: Options.url,
            hasCallback: !!Options.callback,
            hasErrorCallback: !!Options.errorCallback
        });

        // Handle GET request data serialization
        // Convert data object/array to query string parameters
        if (Options.method.toLowerCase() === 'get' && Options.data) {
            try {
                var queryParams = [];
                
                // Check if data is an array (already serialized)
                if (Array.isArray(Options.data)) {
                    EWPDynamicAssetLoader.log('GET request: data is array', { length: Options.data.length });
                    queryParams = Options.data;
                } 
                // Check if data is an object (needs serialization)
                else if (typeof Options.data === 'object' && Object.keys(Options.data).length > 0) {
                    EWPDynamicAssetLoader.log('GET request: serializing object data', Options.data);
                    // Convert object to query string array
                    for (var key in Options.data) {
                        if (Options.data.hasOwnProperty(key)) {
                            queryParams.push(encodeURIComponent(key) + '=' + encodeURIComponent(Options.data[key]));
                        }
                    }
                }
                
                // Append query parameters to URL if any exist
                if (queryParams.length > 0) {
                    var separator = Options.url.indexOf('?') === -1 ? '?' : '&';
                    Options.url += separator + queryParams.join("&");
                    EWPDynamicAssetLoader.log('GET request: URL with parameters', Options.url);
                }
                
                // Mark GET data as serialized into URL (send body will be null)
                Options._dataSerialized = true;
            } catch (e) {
                console.error('[AWM AJAX] Error serializing GET request data:', e);
                throw e;
            }
        }

        // Create XMLHttpRequest instance
        var request = new XMLHttpRequest();
        
        try {
            request.open(Options.method, Options.url, true);
            EWPDynamicAssetLoader.log('Request opened', { method: Options.method, url: Options.url });
        } catch (e) {
            console.error('[AWM AJAX] Error opening request:', e);
            throw e;
        }

        // Set request headers (Content-Type, Nonce, etc.)
        try {
            Options.headers.forEach(function (header) {
                request.setRequestHeader(header.header, header.value);
            });
            EWPDynamicAssetLoader.log('Headers set', { count: Options.headers.length });
        } catch (e) {
            console.error('[AWM AJAX] Error setting request headers:', e);
            throw e;
        }

        // Handle response when request completes
        request.onreadystatechange = function () {
            // Check if request is complete (readyState 4 = DONE)
            if (request.readyState === 4) {
                EWPDynamicAssetLoader.log('Request complete', { status: request.status });
                
                try {
                    // Success: HTTP status 2xx
                    if (request.status >= 200 && request.status < 300) {
                        EWPDynamicAssetLoader.log('Request successful', { status: request.status });
                        
                        try {
                            // Parse JSON response
                            var responseData = JSON.parse(request.responseText);
                            EWPDynamicAssetLoader.log('Response parsed', responseData);

                            // Attach element reference to response if provided
                            if (Options.element) {
                                responseData.element = Options.element;
                                EWPDynamicAssetLoader.log('Element attached to response');
                            }

                            // Execute success callback if provided
                            if (Options.callback) {
                                try {
                                    // Support both function reference and function name string
                                    var callbackFunction = typeof Options.callback === 'function'
                                        ? Options.callback
                                        : window[Options.callback];

                                    if (typeof callbackFunction === 'function') {
                                        EWPDynamicAssetLoader.log('Executing success callback', { callback: Options.callback });
                                        callbackFunction(responseData, Options);
                                    } else {
                                        console.error('[AWM AJAX] ' + Options.callback + ' function does not exist!');
                                    }
                                } catch (e) {
                                    console.error('[AWM AJAX] Error executing success callback:', e);
                                }
                            }

                            // Dispatch custom event for global listeners
                            try {
                                var data = { response: responseData, options: Options };
                                const event = new CustomEvent("awm_ajax_call_callback", { detail: data });
                                document.dispatchEvent(event);
                                EWPDynamicAssetLoader.log('Success event dispatched');
                            } catch (e) {
                                console.error('[AWM AJAX] Error dispatching success event:', e);
                            }
                        } catch (e) {
                            // Handle JSON parsing errors
                            console.error('[AWM AJAX] Error parsing JSON response:', e);
                            handleError(request.status, 'JSON parse error: ' + e.message);
                        }
                    } 
                    // Error: HTTP status 4xx or 5xx
                    else {
                        EWPDynamicAssetLoader.log('Request failed', { status: request.status });
                        handleError(request.status, request.responseText);
                    }
                } catch (e) {
                    // Handle any unexpected errors in response processing
                    console.error('[AWM AJAX] Error processing response:', e);
                    handleError(request.status, e.message);
                }
            }
        };

        /**
         * Handle error responses
         * 
         * Processes failed requests and calls error callback if provided.
         * Specifically handles 4xx client errors with callback support.
         * 
         * @param {number} status HTTP status code
         * @param {string} responseText Response text or error message
         * @return {void}
         */
        function handleError(status, responseText) {
            try {
                // Log error using consistent logging pattern
                console.error('[AWM AJAX] Request failed with status: ' + status);
                EWPDynamicAssetLoader.log('Handling error', { status: status, message: responseText });
                
                // Check if this is a 4xx client error (400-499)
                var isClientError = status >= 400 && status < 500;
                
                // Execute error callback if provided and it's a 4xx error
                if (isClientError && Options.errorCallback) {
                    try {
                        // Support both function reference and function name string
                        var errorCallbackFunction = typeof Options.errorCallback === 'function'
                            ? Options.errorCallback
                            : window[Options.errorCallback];

                        if (typeof errorCallbackFunction === 'function') {
                            // Prepare error data object
                            var errorData = {
                                status: status,
                                message: responseText || 'Request failed',
                                options: Options
                            };
                            
                            EWPDynamicAssetLoader.log('Executing error callback', { callback: Options.errorCallback, status: status });
                            // Call error callback with error data
                            errorCallbackFunction(errorData);
                        } else {
                            console.error('[AWM AJAX] ' + Options.errorCallback + ' error callback function does not exist!');
                        }
                    } catch (e) {
                        console.error('[AWM AJAX] Error executing error callback:', e);
                    }
                }
                
                // Dispatch custom error event for global listeners
                try {
                    var errorEventData = {
                        status: status,
                        message: responseText || 'Request failed',
                        options: Options
                    };
                    const errorEvent = new CustomEvent("awm_ajax_call_error", { detail: errorEventData });
                    document.dispatchEvent(errorEvent);
                    EWPDynamicAssetLoader.log('Error event dispatched', { status: status });
                } catch (e) {
                    console.error('[AWM AJAX] Error dispatching error event:', e);
                }
            } catch (e) {
                // Catch-all for any errors in error handling itself
                console.error('[AWM AJAX] Critical error in handleError:', e);
            }
        }

        // Send the request
        try {
            // For POST requests, send data as JSON string
            // For GET requests, data was serialized into URL — send null body
            var requestData = (Options.data && !Options._dataSerialized) ? JSON.stringify(Options.data) : null;
            EWPDynamicAssetLoader.log('Sending request', { hasData: !!requestData });
            request.send(requestData);
        } catch (e) {
            // Handle network errors or send failures
            console.error('[AWM AJAX] Error sending the request:', e);
            handleError(0, e.message);
        }

        return true;
        
    } catch (e) {
        // Catch-all for any initialization errors
        console.error('[AWM AJAX] Critical error initializing AJAX call:', e);
        return false;
    }
}

function awmMultipleCheckBox() {
    var elems = document.querySelectorAll('.checkbox_multiple.awm-meta-field');
    if (elems) {
        elems.forEach(function (elem) {
            inputs = elem.querySelectorAll('input[type="checkbox"]');

            if (inputs) {
                inputs.forEach(function (input) {
                    var dataValue = input.getAttribute('data-value');
                    if (dataValue == 'awm_apply_all') {
                        input.addEventListener('change', function (e) {
                            var checked = input.checked;
                            var text = input.getAttribute('data-extra');

                            elem.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                                if (checkbox.value != '') {
                                    checkbox.checked = checked;
                                }
                            });
                            var element_to_change = document.querySelector('#label_' + input.id + ' span');
                            input.setAttribute('data-extra', element_to_change.innerText);
                            element_to_change.innerText = text;
                        });
                    }
                });
            }
        });
    }
}