/**
 * Tests for the repeater and map delegated actions.
 */
import { resolveRepeaterAction, handleRepeaterClick, registerRepeaterActions } from '../awm-repeater-module';
import { shouldBlockEnter, handleNoEnterKeydown } from '../awm-maps-module';

const html =
    '<div class="awm-actions"><div data-ewp-action="repeater-clone"><span id="c">Clone</span></div>' +
    '<div data-ewp-action="repeater-up"><span id="u"></span></div><div data-ewp-action="repeater-down" id="d"></div>' +
    '<div class="awm-repeater-remove"><span id="r" data-ewp-action="repeater-remove"></span></div>' +
    '<span id="a" data-ewp-action="repeater-add"></span><span id="sync" class="flx-sync-now-btn"></span></div>';

describe('resolveRepeaterAction', () => {
    beforeEach(() => {
        document.body.innerHTML = html;
    });

    it.each([
        ['c', 'ewp_repeater_clone_row', []],
        ['u', 'awm_repeater_order', [true]],
        ['d', 'awm_repeater_order', [false]],
        ['r', 'repeater', []],
        ['a', 'repeater', []],
    ])('maps %s to %s', (id, fn, args) => {
        const action = resolveRepeaterAction(document.getElementById(id));
        expect(action.fn).toBe(fn);
        expect(action.args).toEqual(args);
    });

    it('passes the element carrying the attribute, not the inner target', () => {
        expect(resolveRepeaterAction(document.getElementById('c')).element.getAttribute('data-ewp-action')).toBe('repeater-clone');
    });

    it('ignores elements without an action (e.g. the sync button)', () => {
        expect(resolveRepeaterAction(document.getElementById('sync'))).toBeNull();
        expect(resolveRepeaterAction(null)).toBeNull();
    });
});

describe('handleRepeaterClick', () => {
    beforeEach(() => {
        document.body.innerHTML = html;
    });

    it('prefers the global function and passes element + args', () => {
        const globalFn = jest.fn();
        const fallback = jest.fn();
        const event = { target: document.getElementById('u') };
        expect(handleRepeaterClick(event, { awm_repeater_order: fallback }, { awm_repeater_order: globalFn })).toBe(true);
        expect(globalFn).toHaveBeenCalledWith(document.getElementById('u').closest('[data-ewp-action]'), true);
        expect(fallback).not.toHaveBeenCalled();
    });

    it('falls back to the module function', () => {
        const fallback = jest.fn();
        handleRepeaterClick({ target: document.getElementById('r') }, { repeater: fallback }, {});
        expect(fallback).toHaveBeenCalledWith(document.getElementById('r'));
    });

    it('does nothing for unrelated clicks', () => {
        expect(handleRepeaterClick({ target: document.getElementById('sync') }, {}, {})).toBe(false);
    });
});

describe('registerRepeaterActions', () => {
    it('registers once per document', () => {
        const doc = { addEventListener: jest.fn() };
        expect(registerRepeaterActions(doc, {})).toBe(true);
        expect(registerRepeaterActions(doc, {})).toBe(false);
        expect(doc.addEventListener).toHaveBeenCalledTimes(1);
    });
});

describe('noenter', () => {
    beforeEach(() => {
        document.body.innerHTML = '<input id="s" data-ewp-noenter><input id="o">';
    });

    it('blocks Enter only on flagged inputs', () => {
        const flagged = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true, bubbles: true });
        document.getElementById('s').dispatchEvent(flagged);
        expect(flagged.defaultPrevented).toBe(true);
        const other = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true, bubbles: true });
        document.getElementById('o').dispatchEvent(other);
        expect(other.defaultPrevented).toBe(false);
        const letter = new KeyboardEvent('keydown', { key: 'a', cancelable: true, bubbles: true });
        document.getElementById('s').dispatchEvent(letter);
        expect(letter.defaultPrevented).toBe(false);
    });

    it('exposes the pure predicate and handler', () => {
        expect(shouldBlockEnter({ key: 'Enter', target: document.getElementById('s') })).toBe(true);
        expect(handleNoEnterKeydown({ key: 'x', target: document.getElementById('s'), preventDefault: jest.fn() })).toBe(false);
    });
});
