awm_auto_fill_inputs();
awm_toggle_password();
awmShowInputs();
awm_ensure_disabled_inputs();


function jsVanillaSerialize(form, returnAsObject = false) {
    return ewp_jsVanillaSerialize(form, returnAsObject);
}

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



function awm_js_ajax_call(url, js_callback) {

    var request = new XMLHttpRequest();
    request.open('GET', url, true);

    request.onload = function () {
        if (request.status >= 200 && request.status < 400) {
            var call_back = window[js_callback];
            if (typeof call_back == 'function') {
                call_back(request.responseText);
            } else {
                console.log(js_callback + ' function does not exist!');
            }
        }
    };
    request.send();
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
                        switch (element.tagName) {
                            case 'SELECT':
                                if (this.value in inputs[p].values) {
                                    if (inputs[p].values[this.value]) {
                                        parent.classList.remove('awm_no_show');
                                        return true;
                                    }
                                }
                                break;
                            case 'INPUT':
                                switch (element.getAttribute('type')) {
                                    case 'checkbox':
                                        if (element.checked == inputs[p].values) {
                                            parent.classList.remove('awm_no_show');
                                            return true;
                                        }
                                        break;
                                }
                                break;
                        }
                        parent.classList.add('awm_no_show');

                    });
                    element.dispatchEvent(new window.Event('change', { bubbles: true }));
                }
            }
            elem.classList.add('awm-initialized')
        });
    }
    /*check for disabled elements*/
    var elems_disabled = document.querySelectorAll('input[disable-elements]');
    if (elems_disabled) {

        elems_disabled.forEach(function (elem) {
            elem.addEventListener('change', function () {
                var inputs = JSON.parse(elem.getAttribute('disable-elements').replace(/\'/g, '\"'));
                var prop = elem.checked;
                for (var p in inputs) {
                    var element = document.getElementById(inputs[p]);
                    if (element && element !== null && typeof element === 'object') {
                        element.removeAttribute('disabled');
                        if (prop) {
                            element.setAttribute('disabled', prop);
                        }
                    }
                }

            });

        });
    }
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
            
            // Store original positions for DOM manipulation
            var nextSibling = action ? targetElement : repeater_div.nextElementSibling.nextElementSibling;
            
            // Swap data-counter attributes
            repeater_div.setAttribute('data-counter', targetCounter);
            targetElement.setAttribute('data-counter', counter);
            
            // Update IDs to reflect new positions
            repeater_div.id = 'awm-' + repeater + '-' + targetCounter;
            targetElement.id = 'awm-' + repeater + '-' + counter;
            
            // Preserve all input values and update their names/ids
            swapInputAttributes(repeater_div, targetElement, repeater, counter, targetCounter);
            
            // Move the DOM elements
            parent.insertBefore(repeater_div, nextSibling);
            
            return true;
        }
    }
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
        
        // Update id attribute
        if (input.id) {
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
    
    // Update image upload containers
    var imageContainers = elem.querySelectorAll('.awm-image-upload');
    imageContainers.forEach(function(container) {
        if (container.id) {
            container.id = container.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );
        }
    });
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
                        setTimeout(() => {
                            // First, properly destroy any existing editor instance
                            if (typeof tinymce !== 'undefined') {
                                // Check if there's an existing editor instance
                                if (tinymce.get(input.id)) {
                                    // Save content to textarea before removing
                                    tinymce.get(input.id).save();
                                    // Remove the editor instance
                                    tinymce.EditorManager.execCommand('mceRemoveEditor', true, input.id);
                                }

                                // Remove any leftover TinyMCE containers
                                const tinyMceContainers = document.querySelectorAll('#wp-' + input.id + '-editor-container .mce-tinymce.mce-container');
                                if (tinyMceContainers && tinyMceContainers.length > 0) {
                                    for (let i = 0; i < tinyMceContainers.length; i++) {
                                        tinyMceContainers[i].remove();
                                    }
                                }

                                // Initialize the new editor instance
                                tinymce.EditorManager.execCommand('mceAddEditor', true, input.id);

                                // Add event listener to ensure content is saved to textarea
                                const editor = tinymce.get(input.id);
                                if (editor) {
                                    editor.on('change', function () {
                                        editor.save();
                                    });

                                    // Also save on blur
                                    editor.on('blur', function () {
                                        editor.save();
                                    });
                                }
                            }
                        }, 300); // Increased timeout for better stability

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
                    
                    // Update image upload container
                    var imageUpload = imageContainer.querySelector('.awm-image-upload');
                    if (imageUpload) {
                        imageUpload.setAttribute('id', 'awm_image' + id);
                        
                        // Find and update the hidden input field
                        var hiddenInput = imageUpload.querySelector('input[type="hidden"]');
                        if (hiddenInput) {
                            // If input-key is missing or empty, use the label text
                            if (!hiddenInput.getAttribute('input-key') || hiddenInput.getAttribute('input-key') === '') {
                                hiddenInput.setAttribute('input-key', labelText);
                            }
                            
                            // Update name and id attributes
                            var inputName = hiddenInput.getAttribute('input-name') || repeater;
                            var inputKey = hiddenInput.getAttribute('input-key') || labelText;
                            var newName = inputName + '[' + new_counter + '][' + inputKey + ']';
                            var newId = newName.replace(/\[/g, '_').replace(/\]/g, '_');
                            
                            hiddenInput.setAttribute('name', newName);
                            hiddenInput.setAttribute('id', newId);
                            
                            // Update the label's for attribute if it exists
                            if (imageLabel) {
                                imageLabel.setAttribute('for', newId);
                            }
                        }
                        
                        // Reset image if needed
                        const removeButton = imageUpload.querySelector('.awm_custom_image_remove_image_button');
                        if (removeButton) {
                            removeButton.click();
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
function awm_ajax_call(options) {
    var defaults = {
        method: 'POST',
        data: {},
        url: '',
        headers: [
            { 'header': 'Content-Type', 'value': 'application/json' },
            { 'header': 'X-WP-Nonce', 'value': awmGlobals.nonce }
        ],
        callback: false,
        log: false,
        element: false
    };

    const Options = { ...defaults, ...options };

    if (Options.method.toLowerCase() === 'get' && Options.data.length > 0) {
        Options.url += '?' + Options.data.join("&");
        Options.data = null;
    }

    if (Options.log) {
        console.log(Options);
    }

    var request = new XMLHttpRequest();
    request.open(Options.method, Options.url, true);

    Options.headers.forEach(function (header) {
        request.setRequestHeader(header.header, header.value);
    });

    request.onreadystatechange = function () {
        if (request.readyState === 4) {
            try {
                if (request.status >= 200 && request.status < 300) {
                    var responseData = JSON.parse(request.responseText);

                    if (Options.log) {
                        console.log(responseData);
                    }

                    if (Options.element) {
                        responseData.element = Options.element;
                    }

                    if (Options.callback) {
                        var callbackFunction = typeof Options.callback === 'function'
                            ? Options.callback
                            : window[Options.callback];

                        if (typeof callbackFunction === 'function') {
                            callbackFunction(responseData, Options);
                        } else {
                            console.error(Options.callback + " function does not exist!");
                        }
                    }

                    var data = { response: responseData, options: Options };
                    const event = new CustomEvent("awm_ajax_call_callback", { detail: data });
                    document.dispatchEvent(event);
                } else {
                    handleError(request.status);
                }
            } catch (e) {
                console.error("Error processing the request: ", e);
            }
        }
    };

    function handleError(status) {
        console.error("Request failed with status: " + status);
    }

    try {
        request.send(Options.data ? JSON.stringify(Options.data) : null);
    } catch (e) {
        console.error("Error sending the request: ", e);
    }

    return true;
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