/**
 * Unit tests for the delegated click actions of the classic search script.
 */
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'ewp-search-script.js'), 'utf8');

describe('ewp-search-script delegated actions', () => {
    beforeAll(() => {
        window.EWPDynamicAssetLoader = { log: jest.fn() };
        // Eval the classic script in the jsdom global scope.
        (0, eval)(source);
    });

    beforeEach(() => {
        window.ewp_apply_search_form = jest.fn();
        window.ewp_reset_search_form = jest.fn();
        window.ewp_search_remove_filter = jest.fn();
        document.body.innerHTML =
            '<div id="a" data-ewp-action="search-apply" data-ewp-search-id="7"><b id="inner">x</b></div>' +
            '<div id="r" data-ewp-action="search-reset" data-ewp-search-id="7"></div>' +
            '<div id="f" data-ewp-action="search-remove-filter" data-ewp-filter-key="color" data-ewp-search-id="7"></div>' +
            '<div id="n">no action</div>';
    });

    it('apply calls ewp_apply_search_form with a numeric id (also from a child)', () => {
        document.getElementById('inner').click();
        expect(window.ewp_apply_search_form).toHaveBeenCalledWith(7);
    });

    it('reset calls ewp_reset_search_form', () => {
        document.getElementById('r').click();
        expect(window.ewp_reset_search_form).toHaveBeenCalledWith(7);
    });

    it('remove-filter passes the string key and numeric id', () => {
        document.getElementById('f').click();
        expect(window.ewp_search_remove_filter).toHaveBeenCalledWith('color', 7);
    });

    it('does nothing without data-ewp-action', () => {
        document.getElementById('n').click();
        expect(window.ewp_apply_search_form).not.toHaveBeenCalled();
        expect(window.ewp_reset_search_form).not.toHaveBeenCalled();
        expect(window.ewp_search_remove_filter).not.toHaveBeenCalled();
    });
});
