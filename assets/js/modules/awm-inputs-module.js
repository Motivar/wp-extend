/**
 * Inputs Module
 * Handles input field interactions (calendars, checkboxes, selects, etc.)
 * Lazy-loaded only when these field types are present
 */

/**
 * Parse select with SlimSelect
 */
function awm_selectr_box(elem) {
    var id = elem.id;

    // Check for onchange attribute BEFORE SlimSelect initialization to prevent errors
    var onchangeAttr = elem.getAttribute('onchange');
    var funcName = null;

    if (onchangeAttr) {
        // Extract function name from "functionName()" or "functionName(args)"
        var match = onchangeAttr.match(/^(\w+)\s*\(/);
        if (match) {
            funcName = match[1];

            // If function doesn't exist yet, remove the attribute to prevent error
            // and queue it for later execution
            if (typeof window[funcName] !== 'function') {
                console.log('[AWM] Deferring callback: ' + funcName + ' (not loaded yet)');

                // Remove onchange to prevent SlimSelect from triggering it
                elem.removeAttribute('onchange');

                // Queue it for later execution
                window.awmDeferredCallbacks.push({
                    funcName: funcName,
                    element: elem,
                    originalAttr: onchangeAttr,
                    timestamp: Date.now()
                });
            }
        }
    }

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

    // Check for onchange attribute and queue callback if function doesn't exist yet
    var onchangeAttr = elem.getAttribute('onchange');
    if (onchangeAttr) {
        // Extract function name from "functionName()" or "functionName(args)"
        var match = onchangeAttr.match(/^(\w+)\s*\(/);
        if (match) {
            var funcName = match[1];

            // Check if function exists
            if (typeof window[funcName] !== 'function') {
                console.log('[AWM] Deferring callback: ' + funcName + ' (not loaded yet)');

                // Queue it for later execution
                window.awmDeferredCallbacks.push({
                    funcName: funcName,
                    element: elem,
                    originalAttr: onchangeAttr,
                    timestamp: Date.now()
                });
            }
        }
    }
}

/**
 * Get select box option values
 */
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
 * Initialize select boxes with SlimSelect
 */
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
 * Toggle password visibility
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
 * Auto-fill inputs from other inputs
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

/**
 * Initialize callbacks on input elements
 */
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

/**
 * Check and execute callback
 */
function awm_check_call_back(elem, action) {
    var call_back = window[elem.getAttribute('data-callback')];

    if (typeof call_back == 'function') {
        call_back(elem, action);

    } else {
        console.log(elem.getAttribute('data-callback') + ' function does not exist!');
    }
}

/**
 * Show/hide inputs based on conditions
 */
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

/**
 * Toggle disabled state on inputs
 */
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

/**
 * Create calendar date pickers
 */
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

/**
 * Get timestamp from date
 */
function awm_timestamp(d) {
    "use strict";
    d = new Date(d);
    d = d.setUTCHours(24, 0, 0, 0);
    return (d / 1000);
}

/**
 * Handle multiple checkboxes
 */
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

/**
 * Ensure disabled inputs in template repeaters
 */
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
 * Initialize inputs module
 */
function initInputs() {
    awm_auto_fill_inputs();
    awm_toggle_password();
    awmShowInputs();
    awm_ensure_disabled_inputs();
    awmCallbacks();
    awm_create_calendar();
    awmSelectrBoxes();
    awmMultipleCheckBox();
}

// Export functions
export { 
    awm_create_calendar, 
    awmMultipleCheckBox, 
    awmSelectrBoxes, 
    awm_selectr_box,
    awmCallbacks, 
    awmShowInputs, 
    awm_auto_fill_inputs, 
    awm_toggle_password,
    awm_ensure_disabled_inputs,
    awm_timestamp,
    initInputs 
};
