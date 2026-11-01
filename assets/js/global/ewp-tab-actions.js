/**
 * Tab field actions (case `awm_tab`).
 *
 * Pure helpers behind `awm_open_tab()` and the delegated `data-ewp-action="open-tab"` listener.
 * Kept free of side effects on import so they can be unit tested.
 */

/**
 * Switches to one tab. Only the tab set (`.awm-tab-wrapper`) of the clicked link is touched;
 * without a wrapper the whole document is used (legacy markup).
 *
 * @param {{currentTarget: HTMLElement}} evt Event-like object; `currentTarget` is the clicked `.awm_tablinks`.
 * @param {string} div Tab id (the panel is `<id>_content_tab`); surrounding whitespace is ignored.
 * @return {void}
 */
function openTab(evt, div) {
    const id = String(div).trim();
    const link = evt.currentTarget;
    const wrapper = link.closest('.awm-tab-wrapper');
    const contents = wrapper
        ? wrapper.querySelectorAll(':scope > .awm_tabcontent')
        : document.getElementsByClassName('awm_tabcontent');
    const links = wrapper
        ? wrapper.querySelectorAll(':scope > .awm-tab > .awm_tablinks')
        : document.getElementsByClassName('awm_tablinks');

    Array.prototype.forEach.call(contents, (content) => {
        content.style.display = 'none';
    });
    Array.prototype.forEach.call(links, (item) => {
        item.className = item.className.replace(' active', '');
    });
    const panel = document.getElementById(id + '_content_tab');
    if (panel) {
        panel.style.display = 'block';
    }
    link.className += ' active';
}

/**
 * Resolves a click target to a tab action.
 *
 * @param {EventTarget|null} target Click target.
 * @return {{link: HTMLElement, id: string}|null} The link and tab id, or null when the click is not on a tab link.
 */
function resolveTabAction(target) {
    if (!target || typeof target.closest !== 'function') {
        return null;
    }
    const link = target.closest('[data-ewp-action="open-tab"]');
    if (!link) {
        return null;
    }
    return { link, id: link.getAttribute('data-ewp-tab') || '' };
}

/**
 * Delegated click handler for tab links.
 *
 * @param {Event} event Click event.
 * @param {Function} open Opener with the `awm_open_tab(evt, id)` signature.
 * @return {boolean} True when the click was handled.
 */
function handleTabClick(event, open) {
    const action = resolveTabAction(event.target);
    if (!action) {
        return false;
    }
    open({ currentTarget: action.link }, action.id);
    return true;
}

export { openTab, resolveTabAction, handleTabClick };
