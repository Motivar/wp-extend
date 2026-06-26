/**
 * AWM Global Script - Core Module
 * Smart loader that dynamically imports feature modules based on DOM presence
 * Optimized for performance with lazy-loading of non-essential features
 */

/**
 * Set webpack public path dynamically based on the script's location
 * This ensures chunks are loaded from the correct path regardless of where the plugin is installed
 * Chunks are in /build/ directory, script is in /build/global/, so we need to go up one level
 */
if (typeof __webpack_public_path__ !== 'undefined') {
    // Prefer the server-provided absolute build URL. Robust against WP Rocket
    // minify/combine, which rewrites the entry script URL into the cache dir.
    if (typeof awmGlobals !== 'undefined' && awmGlobals.buildUrl) {
        __webpack_public_path__ = awmGlobals.buildUrl;
    } else {
        const scriptTag = document.currentScript || document.querySelector('script[src*="awm-global-script"]');
        if (scriptTag && scriptTag.src) {
            const scriptUrl = new URL(scriptTag.src);
            const scriptPath = scriptUrl.href.substring(0, scriptUrl.href.lastIndexOf('/') + 1);
            __webpack_public_path__ = scriptPath.replace(/\/global\/$/, '/');
        }
    }
}

/**
 * Global queue for deferred callbacks
 * Stores callbacks that are triggered before their functions are loaded
 * Used by Dynamic Asset Loader to execute callbacks after scripts load
 */
window.awmDeferredCallbacks = window.awmDeferredCallbacks || [];

/**
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

/**
 * Wrapper for legacy AJAX GET calls
 */
function jsVanillaSerialize(form, returnAsObject = false) {
    return ewp_jsVanillaSerialize(form, returnAsObject);
}


/**
 * Serialize data object to query string
 */
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
 * Show loading spinner in target element
 * 
 * @param {string} selector - CSS selector for target element
 * @since 1.0.0
 */
function awm_show_loading(selector) {
    if (!selector) return;

    // Get spinner HTML from localized data with fallback
    const spinnerHtml = (typeof awmGlobals !== 'undefined' && awmGlobals.spinnerHtml)
        ? awmGlobals.spinnerHtml
        : '<div class="awm-ajax-spinner"><div class="bounce1"></div><div class="bounce2"></div><div class="bounce3"></div></div>';

    const elements = document.querySelectorAll(selector);
    elements.forEach(function (element) {
        element.innerHTML = spinnerHtml;
        element.style.display = 'block';
        // Fade in effect
        element.style.opacity = '0';
        setTimeout(function () {
            element.style.transition = 'opacity 0.3s';
            element.style.opacity = '1';
        }, 10);
    });
}

/**
 * Hide loading spinner from target element
 * 
 * @param {string} selector - CSS selector for target element
 * @since 1.0.0
 */
function awm_hide_loading(selector) {
    if (!selector) return;

    const elements = document.querySelectorAll(selector);
    elements.forEach(function (element) {
        // Fade out effect
        element.style.transition = 'opacity 0.3s';
        element.style.opacity = '0';
        setTimeout(function () {
            element.style.display = 'none';
            element.innerHTML = '';
        }, 300);
    });
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
 * @param {string|boolean} options.loading CSS selector for loading element (false to disable)
 * @param {boolean} options.loadingAutoHide Auto-hide loading spinner on success/error (default: false)
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
            element: false,
            loading: false,
            loadingAutoHide: false
        };

        // Merge user options with defaults
        const Options = { ...defaults, ...options };

        // Add 'awm-ajax-started' class to body
        document.body.classList.add('awm-ajax-started');

        // Dispatch started event
        const startedEvent = new CustomEvent('awm_ajax_started', { detail: { options: Options } });
        document.dispatchEvent(startedEvent);

        // Show loading spinner if selector provided
        if (Options.loading) {
            awm_show_loading(Options.loading);
        }

        EWPDynamicAssetLoader.log('Initializing AJAX request', {
            method: Options.method,
            url: Options.url,
            hasCallback: !!Options.callback,
            hasErrorCallback: !!Options.errorCallback,
            hasLoading: !!Options.loading,
            loadingAutoHide: Options.loadingAutoHide
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
        
        // Add 'awm-ajax-processing' class when request starts
        request.addEventListener('loadstart', function () {
            document.body.classList.remove('awm-ajax-started');
            document.body.classList.add('awm-ajax-processing');

            // Dispatch processing event
            const processingEvent = new CustomEvent('awm_ajax_processing', { detail: { options: Options } });
            document.dispatchEvent(processingEvent);
        });

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
                        
                        // Remove processing class, add succeeded class
                        document.body.classList.remove('awm-ajax-processing');
                        document.body.classList.add('awm-ajax-succeeded');

                        // Hide loading spinner on success if auto-hide is enabled
                        if (Options.loading && Options.loadingAutoHide) {
                            awm_hide_loading(Options.loading);
                        }

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

                                // Remove succeeded class after a short delay
                                setTimeout(function () {
                                    document.body.classList.remove('awm-ajax-succeeded');
                                }, 300);
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
         */
        function handleError(status, responseText) {
            // Remove processing class, add failed class
            document.body.classList.remove('awm-ajax-started', 'awm-ajax-processing');
            document.body.classList.add('awm-ajax-failed');

            // Hide loading spinner on error if auto-hide is enabled
            if (Options.loading && Options.loadingAutoHide) {
                awm_hide_loading(Options.loading);
            }

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

                    // Remove failed class after a short delay
                    setTimeout(function () {
                        document.body.classList.remove('awm-ajax-failed');
                    }, 300);
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

/**
 * Open tab functionality
 */
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

// Initialize custom image uploader if present
if (document.getElementsByClassName("awm_custom_image_image_uploader_field-show").length) {
    var clickables = document.getElementsByClassName("awm-tab-show");
    clickables[0].click()
}

/**
 * Smart initialization function
 * Detects which features are needed and dynamically imports modules
 */
async function awm_init_inputs() {
    const modulePromises = [];

    // Load inputs module if form content exists (selects, checkboxes, callbacks, etc.)
    if (document.querySelector('.awm-show-content')) {
        modulePromises.push(
            import(/* webpackChunkName: "awm-inputs-module" */ '@modules/awm-inputs-module.js').then(m => {
                // Expose all input module functions globally for backwards compatibility
                window.awm_create_calendar = m.awm_create_calendar;
                window.awmMultipleCheckBox = m.awmMultipleCheckBox;
                window.awmSelectrBoxes = m.awmSelectrBoxes;
                window.awm_selectr_box = m.awm_selectr_box;
                window.awmCallbacks = m.awmCallbacks;
                window.awmShowInputs = m.awmShowInputs;
                window.awm_auto_fill_inputs = m.awm_auto_fill_inputs;
                window.awm_toggle_password = m.awm_toggle_password;
                window.awm_ensure_disabled_inputs = m.awm_ensure_disabled_inputs;
                window.awm_timestamp = m.awm_timestamp;

                // Initialize all input features
                m.awm_create_calendar();
                m.awmMultipleCheckBox();
                m.awmSelectrBoxes();
                m.awmCallbacks();
                m.awmShowInputs();
                m.awm_auto_fill_inputs();
                m.awm_toggle_password();
                m.awm_ensure_disabled_inputs();
            }).catch(err => console.error('[AWM] Error loading inputs module:', err))
        );

        modulePromises.push(
            import(/* webpackChunkName: "awm-forms-module" */ '@modules/awm-forms-module.js').then(m => {
                // Expose module functions globally for backwards compatibility
                window.awmInitForms = m.awmInitForms;
                window.awmCheckValidation = m.awmCheckValidation;
                window.awmShowError = m.awmShowError;
                m.awmInitForms();
            }).catch(err => console.error('[AWM] Error loading forms module:', err))
        );
    }

    // Check for repeaters
    if (document.querySelector('.awm-repeater')) {
        modulePromises.push(
            import(/* webpackChunkName: "awm-repeater-module" */ '@modules/awm-repeater-module.js').then(m => {
                // Expose module functions globally for backwards compatibility
                window.repeater = m.repeater;
                window.ewp_repeater_clone_row = m.ewp_repeater_clone_row;
                window.awm_repeater_order = m.awm_repeater_order;
            }).catch(err => console.error('[AWM] Error loading repeater module:', err))
        );
    }

    // Check for TinyMCE editors
    if (document.querySelector('textarea.wp-editor-area')) {
        modulePromises.push(
            import(/* webpackChunkName: "awm-tinymce-module" */ '@modules/awm-tinymce-module.js').then(m => {
                // Expose module functions globally for backwards compatibility
                window.awm_initialize_repeater_wp_editor = m.awm_initialize_repeater_wp_editor;
                window.awm_get_tinymce_args = m.awm_get_tinymce_args;
                m.initTinyMCEEditors();
            }).catch(err => console.error('[AWM] Error loading TinyMCE module:', err))
        );
    }

    // Check for object_id_filter fields
    if (document.querySelector('.awm-object-id-filter-wrap')) {
        modulePromises.push(
            import(/* webpackChunkName: "awm-object-id-filter-module" */ '@modules/awm-object-id-filter-module.js').then(m => {
                window.initObjectIdFilters = m.initObjectIdFilters;
                m.initObjectIdFilters();
            }).catch(err => console.error('[AWM] Error loading object ID filter module:', err))
        );
    }


    // Wait for all needed modules to load and initialize
    if (modulePromises.length > 0) {
        await Promise.all(modulePromises);
        // Execute any callbacks queued by external plugins
        if (typeof window.awmExecuteReadyCallbacks === 'function') {
            window.awmExecuteReadyCallbacks();
        }
    }
}


    // DOM already loaded (e.g., script loaded after DOMContentLoaded)
awm_init_inputs();

// Re-initialize when widgets are sorted (for admin)
jQuery('div.widgets-sortables').bind('sortstop', function (event, ui) {
    awm_init_inputs();
});

// Expose critical functions globally for admin scripts and backwards compatibility
window.ewp_jsVanillaSerialize = ewp_jsVanillaSerialize;
window.awm_serialize_data = awm_serialize_data;
window.awm_ajax_call = awm_ajax_call;
window.awm_open_tab = awm_open_tab;
window.awm_js_ajax_call = awm_js_ajax_call;
window.jsVanillaSerialize = jsVanillaSerialize;
window.awm_init_inputs = awm_init_inputs;
window.awm_show_loading = awm_show_loading;
window.awm_hide_loading = awm_hide_loading;

/**
 * Wait for a module function to be available on the window object
 * Useful for external plugins that need to use AWM functions
 * 
 * @param {string} functionName - Name of the function to wait for
 * @param {number} timeout - Maximum time to wait in milliseconds (default: 5000)
 * @returns {Promise} Resolves when function is available, rejects on timeout
 */
window.awmWaitForFunction = function (functionName, timeout = 5000) {
    return new Promise((resolve, reject) => {
        const startTime = Date.now();
        const checkInterval = 50;

        const check = () => {
            if (typeof window[functionName] === 'function') {
                resolve(window[functionName]);
                return;
            }

            if (Date.now() - startTime > timeout) {
                reject(new Error(`Function "${functionName}" not available after ${timeout}ms`));
                return;
            }

            setTimeout(check, checkInterval);
        };

        check();
    });
};

/**
 * Queue for external plugin code that needs to run after AWM modules load
 * External plugins can use: window.awmOnReady(callback)
 */
window.awmReadyCallbacks = [];
window.awmOnReady = function (callback) {
    if (typeof callback !== 'function') {
        console.error('[AWM] awmOnReady: callback must be a function');
        return;
    }

    // Check if critical functions are already loaded
    if (typeof window.awm_selectr_box === 'function' &&
        typeof window.repeater === 'function' &&
        typeof window.awm_create_calendar === 'function') {
        // Functions already loaded, execute immediately
        callback();
    } else {
        // Queue for later execution
        window.awmReadyCallbacks.push(callback);
    }
};

/**
 * Execute all queued callbacks when modules are ready
 * Called internally after all modules load
 */
window.awmExecuteReadyCallbacks = function () {
    while (window.awmReadyCallbacks.length > 0) {
        const callback = window.awmReadyCallbacks.shift();
        try {
            callback();
        } catch (error) {
            console.error('[AWM] Error in awmOnReady callback:', error);
        }
    }
};
