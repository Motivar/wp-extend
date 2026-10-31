/**
 * Pure helper for the options-page REST button (awm-admin-script.js).
 * Free of DOM globals so it can be unit-tested (`npm run test:js`).
 */

/**
 * Arguments for `awm_options_rest_call` read from a button's data attributes.
 *
 * @param {Element|null} element Element with `data-ewp-action="options-rest"`.
 * @return {{form: string, endpoint: string, callback: string, method: string}|null} Arguments, or null when the element is not an options-rest button.
 */
export function optionsRestArgs(element) {
    if (!element || !element.dataset || element.dataset.ewpAction !== 'options-rest') {
        return null;
    }
    return {
        form: element.dataset.ewpForm,
        endpoint: element.dataset.ewpEndpoint,
        callback: element.dataset.ewpCallback,
        method: element.dataset.ewpMethod,
    };
}
