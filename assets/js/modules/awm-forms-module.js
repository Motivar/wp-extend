/**
 * Forms Module
 * Handles form validation and submission
 * Lazy-loaded only when forms with validation are present
 */

/**
 * Check form validation
 * 
 * @param {HTMLElement} form - The form element to validate
 * @return {Object} Object with check (boolean) and error (element) properties
 */
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

/**
 * Show form validation error
 * Scrolls to first error element
 */
function awmShowError() {
    /*scroll to first item with class .awm-form-error*/
    var firstError = document.querySelector('.awm-form-error');
    if (firstError) {
        firstError.scrollIntoView();
    };
}

/**
 * Initialize form validation
 */
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

/**
 * Initialize forms module
 */
function initForms() {
    awmInitForms();
}

// Export functions
export { awmInitForms, awmCheckValidation, awmShowError, initForms };
