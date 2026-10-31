/**
 * Unit tests for the options REST button helper (`npm run test:js`).
 */
import { optionsRestArgs } from '../options-rest-helpers';

describe('optionsRestArgs', () => {
    it('reads the call arguments from data attributes', () => {
        document.body.innerHTML = '<button data-ewp-action="options-rest" data-ewp-form="f" data-ewp-endpoint="ns/e" data-ewp-callback="cb" data-ewp-method="post"></button>';
        expect(optionsRestArgs(document.querySelector('button'))).toEqual({ form: 'f', endpoint: 'ns/e', callback: 'cb', method: 'post' });
    });

    it('ignores other elements and null', () => {
        document.body.innerHTML = '<button data-ewp-action="other"></button>';
        expect(optionsRestArgs(document.querySelector('button'))).toBeNull();
        expect(optionsRestArgs(null)).toBeNull();
    });
});
