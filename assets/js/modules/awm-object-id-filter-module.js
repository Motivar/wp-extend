/**
 * Object ID Filter Module
 *
 * Powers the reusable `object_id_filter` field type: a type selector
 * (post type / taxonomy / custom content) plus a SlimSelect multi-select that
 * loads matching object IDs on demand from the REST endpoint
 * `extend-wp/v1/objects/search`.
 *
 * Lazy-loaded by awm-global-script.js only when `.awm-object-id-filter-wrap`
 * is present in the DOM. SlimSelect is bundled into this chunk by webpack.
 *
 * @since 1.4.0
 */

import SlimSelect from 'slim-select';
import 'slim-select/styles';

// Tracks instances so re-initialization (e.g. after a widget re-sort) is a no-op.
const INIT_FLAG = 'awmObjectIdFilterInit';

/**
 * Read the per-instance configuration from the wrapper's data-config attribute.
 * The attribute stores JSON with single quotes (PHP-side convention) so we swap
 * them back before parsing.
 *
 * @param {HTMLElement} wrap The .awm-object-id-filter-wrap element.
 * @returns {Object} Parsed config with sensible fallbacks.
 */
function parseConfig(wrap) {
    const fallback = {
        restUrl: (window.awmGlobals && window.awmGlobals.restUrl) || '/wp-json/extend-wp/v1',
        minSearchChars: 2,
        maxResults: 20,
        searchMeta: true,
        typeFieldName: '',
        idFieldName: ''
    };
    const raw = wrap.getAttribute('data-config');
    if (!raw) {
        return fallback;
    }
    try {
        return Object.assign(fallback, JSON.parse(raw.replace(/'/g, '"')));
    } catch (e) {
        console.error('[AWM] object_id_filter: invalid data-config', e);
        return fallback;
    }
}

/**
 * Debounce helper.
 *
 * @param {Function} func
 * @param {number} wait
 * @returns {Function}
 */
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

/**
 * Fetch matching objects from the REST search endpoint.
 *
 * @param {string} objectType  Format "{group}:{slug}".
 * @param {string} searchTerm  The search term.
 * @param {Object} config      Instance config.
 * @param {string[]} exclude   IDs already selected (excluded from results).
 * @param {AbortSignal} signal Abort signal for superseded requests.
 * @returns {Promise<Array>} Resolves to [{id,label,type}].
 */
function fetchObjects(objectType, searchTerm, config, exclude, signal) {
    const params = new URLSearchParams({
        object_type: objectType,
        search: searchTerm,
        limit: String(config.maxResults),
        search_meta: config.searchMeta ? '1' : '0',
        exclude: (exclude || []).join(',')
    });
    const headers = { 'Content-Type': 'application/json' };
    if (window.awmGlobals && window.awmGlobals.nonce) {
        headers['X-WP-Nonce'] = window.awmGlobals.nonce;
    }
    const url = config.restUrl.replace(/\/$/, '') + '/objects/search?' + params.toString();

    return fetch(url, { headers, credentials: 'same-origin', signal })
        .then((res) => res.json())
        .then((json) => (json && json.success && Array.isArray(json.data)) ? json.data : []);
}

/**
 * Return the currently selected options of the IDs select as SlimSelect data,
 * so they stay visible/selected when search results replace the option list.
 *
 * @param {HTMLSelectElement} selectElem
 * @returns {Array} SlimSelect partial-data objects, marked selected.
 */
function getSelectedAsData(selectElem) {
    return Array.from(selectElem.options)
        .filter((opt) => opt.selected && opt.value !== '')
        .map((opt) => ({ value: opt.value, text: opt.textContent, selected: true }));
}

/**
 * Merge fetched results with currently selected options (selected pinned first).
 *
 * @param {Array} results       REST results [{id,label}].
 * @param {Array} selectedData  Currently selected SlimSelect data.
 * @returns {Array} Combined SlimSelect data array.
 */
function updateSlimSelectOptions(results, selectedData) {
    const seen = new Set(selectedData.map((o) => String(o.value)));
    const mapped = results
        .filter((r) => !seen.has(String(r.id)))
        .map((r) => ({ value: String(r.id), text: r.label }));
    return selectedData.concat(mapped);
}

/**
 * Build the SlimSelect instance for the IDs multi-select, wiring its native
 * remote-search event to the REST endpoint.
 *
 * @param {HTMLSelectElement} selectElem
 * @param {HTMLElement} wrap
 * @param {Object} config
 * @returns {SlimSelect}
 */
function createSlimSelectInstance(selectElem, wrap, config) {
    let abortController = null;

    // Debounced fetch wrapped in a promise SlimSelect can await.
    const runSearch = debounce((searchValue, resolve) => {
        const objectType = wrap.dataset.objectType || '';
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();
        const exclude = selectElem.value === '' ? [] : getSelectedAsData(selectElem).map((o) => o.value);

        fetchObjects(objectType, searchValue, config, exclude, abortController.signal)
            .then((results) => resolve(updateSlimSelectOptions(results, getSelectedAsData(selectElem))))
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('[AWM] object_id_filter search failed', err);
                }
                resolve(getSelectedAsData(selectElem));
            });
    }, 300);

    return new SlimSelect({
        select: selectElem,
        settings: {
            placeholderText: '',
            allowDeselect: true,
            minimumChars: 0,
            searchingText: (window.awmGlobals && window.awmGlobals.strings && window.awmGlobals.strings.loading) || 'Searching…'
        },
        events: {
            search: (searchValue, currentData) => {
                const objectType = wrap.dataset.objectType || '';
                if (!objectType) {
                    return Promise.reject('Select a type first');
                }
                if (searchValue.length < config.minSearchChars) {
                    return Promise.reject('Type at least ' + config.minSearchChars + ' characters');
                }
                return new Promise((resolve) => runSearch(searchValue, resolve));
            }
        }
    });
}

/**
 * Handle a change on the type selector: clear the IDs select, reset its state
 * and enable/disable it based on whether a type is chosen.
 *
 * @param {HTMLElement} wrap
 */
function handleObjectTypeChange(wrap) {
    const typeSelect = wrap.querySelector('.awm-object-type-select');
    const slim = wrap[INIT_FLAG];
    if (!typeSelect || !slim) {
        return;
    }
    const value = typeSelect.value || '';
    wrap.dataset.objectType = value;

    // Clear previous selections + options when the type changes.
    slim.setData([]);
    slim.setSelected([]);

    if (value) {
        slim.enable();
    } else {
        slim.disable();
    }
}

/**
 * Initialize a single object_id_filter instance.
 *
 * @param {HTMLElement} wrap
 */
function initInstance(wrap) {
    if (wrap[INIT_FLAG]) {
        return; // already initialized
    }
    const typeSelect = wrap.querySelector('.awm-object-type-select');
    const idSelect = wrap.querySelector('.awm-object-id-search');
    if (!typeSelect || !idSelect) {
        return;
    }
    const config = parseConfig(wrap);
    wrap.dataset.objectType = typeSelect.value || '';

    // Type selector: initialize with SlimSelect (plain single-select, no remote search).
    // We skip the global awmSelectrBoxes for this element (awm-skip-selectr attr)
    // because the global handler expects an `options="..."` attribute on optgroups
    // that we don't emit. Initialize manually here instead.
    new SlimSelect({
        select: typeSelect,
        settings: {
            allowDeselect: true,
            placeholderText: typeSelect.options[0] ? typeSelect.options[0].textContent : '',
        }
    });

    const slim = createSlimSelectInstance(idSelect, wrap, config);
    wrap[INIT_FLAG] = slim;

    if (!wrap.dataset.objectType) {
        slim.disable();
    }

    // React to type changes. SlimSelect re-dispatches change on the original select.
    typeSelect.addEventListener('change', () => handleObjectTypeChange(wrap));
}

/**
 * Initialize all object_id_filter instances currently in the DOM.
 */
function initObjectIdFilters() {
    document.querySelectorAll('.awm-object-id-filter-wrap').forEach(initInstance);
}

export {
    initObjectIdFilters,
    handleObjectTypeChange,
    updateSlimSelectOptions,
    createSlimSelectInstance,
    fetchObjects,
    parseConfig,
    debounce
};
