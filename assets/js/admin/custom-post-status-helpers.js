/**
 * Pure helpers for EWPCustomPostStatus (awm-admin-script.js): custom post
 * statuses registered through Extend WP's post-type UI. Kept free of DOM and
 * wp.data access so they can be unit-tested (`npm run test:js`).
 */

/**
 * Human label of a status: its configured label, else the key in title case.
 *
 * @param {string} statusKey  Status slug.
 * @param {Object} statusData Status config (`label` optional).
 * @return {string} Label.
 */
export function statusLabel(statusKey, statusData) {
    if (statusData && statusData.label) {
        return statusData.label;
    }
    return statusKey.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

/**
 * Status records for the block editor's `core` store: the existing ones plus
 * the custom statuses, as `wp.data.dispatch('core').receiveEntityRecords('root', 'status', …)` expects.
 *
 * @param {Object[]|Object|null} existing Statuses already in the store: the array
 *                                         `select('core').getEntityRecords('root', 'status')` returns,
 *                                         or an object keyed by slug (may be empty/null).
 * @param {Object} customStatuses Custom statuses keyed by slug.
 * @return {Object[]} Records (`name` = label, `slug`, `_links`).
 */
export function mergeStatusRecords(existing, customStatuses) {
    const merged = {};
    (Array.isArray(existing) ? existing : Object.values(existing || {})).forEach((record) => {
        if (record && record.slug) {
            merged[record.slug] = record;
        }
    });
    Object.keys(customStatuses || {}).forEach((statusKey) => {
        merged[statusKey] = {
            name: statusLabel(statusKey, customStatuses[statusKey]),
            slug: statusKey,
            _links: {},
        };
    });
    return Object.values(merged);
}

/**
 * Label to show in a list screen's Status column for its current text, when
 * the text is a custom status key as WordPress prints it (`my_status` or
 * `my status`, any case).
 *
 * @param {string} text           Current cell text.
 * @param {Object} customStatuses Custom statuses keyed by slug.
 * @return {string|null} The label, or null when the text is not a custom status.
 */
export function columnLabelFor(text, customStatuses) {
    const current = (text || '').trim().toLowerCase();
    const match = Object.keys(customStatuses || {}).find((statusKey) => {
        return current === statusKey.toLowerCase() || current === statusKey.replace(/_/g, ' ').toLowerCase();
    });
    if (!match) {
        return null;
    }
    return customStatuses[match].label || match;
}
