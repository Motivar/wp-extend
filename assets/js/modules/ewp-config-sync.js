/**
 * Configuration sync module.
 *
 * Powers the "Migrate to per-object files" button on Settings > Extend WP:
 * POSTs to `ewp/v1/config-sync/migrate` and reports the outcome next to the
 * button. Lazy-loaded by the Dynamic Asset Loader when `.ewp-config-sync` is on
 * the page (registered in Extend_WP_Default_Content::register_config_sync_assets()).
 *
 * Configuration comes from the `ewpConfigSync` global: { restUrl, nonce, strings }.
 *
 * @since 1.15.0
 */

/**
 * Read the localized configuration.
 *
 * @param {Window} win Global scope holding `ewpConfigSync`.
 * @return {{restUrl: string, nonce: string, strings: Object}} Configuration with safe fallbacks.
 */
export function readConfig(win = window) {
    const config = win.ewpConfigSync || {};
    return {
        restUrl: config.restUrl || '',
        nonce: config.nonce || '',
        strings: config.strings || {}
    };
}

/**
 * Turn a migrate response into a one-line message.
 *
 * @param {Object} body Parsed JSON body of the response.
 * @param {boolean} ok Whether the HTTP status was a success.
 * @param {Object} strings Localized strings.
 * @return {string} Message to show.
 */
export function describeResult(body, ok, strings) {
    if (!ok) {
        return (body && body.message) || strings.error || '';
    }
    const warnings = body && Array.isArray(body.warnings) ? body.warnings : [];
    return [strings.success || ''].concat(warnings).filter(Boolean).join(' ');
}

/**
 * Run the migration and show the outcome.
 *
 * @param {HTMLButtonElement} button The clicked button.
 * @param {Object} config Configuration from readConfig().
 * @return {Promise<void>}
 */
export async function migrate(button, config) {
    const result = button.closest('.ewp-config-sync').querySelector('.ewp-config-sync__result');
    if (!window.confirm(config.strings.confirm || '')) {
        return;
    }
    button.disabled = true;
    result.textContent = config.strings.working || '';
    try {
        const response = await fetch(config.restUrl + 'config-sync/migrate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
            body: '{}'
        });
        const body = await response.json().catch(() => ({}));
        result.textContent = describeResult(body, response.ok, config.strings);
        button.disabled = response.ok;
    } catch (error) {
        result.textContent = config.strings.error || '';
        button.disabled = false;
    }
}

/**
 * Wire the buttons of every panel on the page.
 *
 * @param {ParentNode} root Where to look (default: document).
 * @return {void}
 */
export function init(root = document) {
    const config = readConfig();
    root.querySelectorAll('[data-ewp-config-sync-action="migrate"]').forEach((button) => {
        button.addEventListener('click', () => migrate(button, config));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init());
} else {
    init();
}
