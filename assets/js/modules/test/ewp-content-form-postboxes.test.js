/**
 * Tests for the content form postboxes module.
 */
import { openNoJsClosedBoxes, enablePostboxToggles } from '../ewp-content-form-postboxes';

describe('openNoJsClosedBoxes', () => {
    it('swaps if-js-closed for closed', () => {
        document.body.innerHTML = '<div class="postbox if-js-closed" id="a"></div><div class="postbox" id="b"></div>';
        openNoJsClosedBoxes();
        expect(document.getElementById('a').className).toBe('postbox closed');
        expect(document.getElementById('b').className).toBe('postbox');
    });
});

describe('enablePostboxToggles', () => {
    it('passes the screen id to postboxes', () => {
        const add = jest.fn();
        expect(enablePostboxToggles({ postboxes: { add_postbox_toggles: add }, pagenow: 'extend-wp_page_ewp_fields_form' })).toBe(true);
        expect(add).toHaveBeenCalledWith('extend-wp_page_ewp_fields_form');
    });

    it('does nothing without postboxes or pagenow', () => {
        expect(enablePostboxToggles({})).toBe(false);
        expect(enablePostboxToggles({ postboxes: { add_postbox_toggles: jest.fn() } })).toBe(false);
    });
});
