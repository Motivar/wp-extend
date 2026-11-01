/**
 * Content form postboxes module.
 *
 * Collapsible meta boxes on custom content edit forms (#ewp-content-form):
 * opens boxes rendered closed for no-JS (`.if-js-closed`) and turns on
 * WordPress's postbox toggles. Lazy-loaded by the Dynamic Asset Loader when
 * #ewp-content-form is on the page (registered in
 * EWP\Setup → Extend_WP::register_content_form_assets()); replaces the inline
 * footer script AWM_Add_Custom_List printed before 1.14.0.
 *
 * Uses WordPress's `pagenow` (the screen id), which is also the key core uses
 * to remember which boxes a user collapsed.
 */

/**
 * Open the boxes rendered closed for browsers without JavaScript.
 *
 * @param {ParentNode} root Where to look (default: document).
 * @return {void}
 */
export function openNoJsClosedBoxes(root = document) {
    root.querySelectorAll('.if-js-closed').forEach((box) => {
        box.classList.remove('if-js-closed');
        box.classList.add('closed');
    });
}

/**
 * Turn on WordPress's postbox toggles for the current screen.
 *
 * @param {Window} win Global scope providing `postboxes` and `pagenow`.
 * @return {boolean} Whether the toggles were set up.
 */
export function enablePostboxToggles(win = window) {
    if (!win.postboxes || typeof win.postboxes.add_postbox_toggles !== 'function' || !win.pagenow) {
        return false;
    }
    win.postboxes.add_postbox_toggles(win.pagenow);
    return true;
}

/**
 * Set up the content form's postboxes once the DOM is ready.
 *
 * @return {void}
 */
function init() {
    openNoJsClosedBoxes();
    enablePostboxToggles();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
