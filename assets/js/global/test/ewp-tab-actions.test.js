/**
 * Tests for the tab field actions.
 */
import { openTab, resolveTabAction, handleTabClick } from '../ewp-tab-actions';

const tabSet = (a, b) =>
    '<div class="awm-tab-wrapper"><div class="awm-tab">' +
    `<div id="${a}_tab" class="awm_tablinks awm-tab-show active" data-ewp-action="open-tab" data-ewp-tab="${a}"><b>A</b></div>` +
    `<div id="${b}_tab" class="awm_tablinks" data-ewp-action="open-tab" data-ewp-tab="${b}">B</div></div>` +
    `<div id="${a}_content_tab" class="awm_tabcontent" style="display: block;"></div>` +
    `<div id="${b}_content_tab" class="awm_tabcontent"></div></div>`;

describe('openTab', () => {
    beforeEach(() => {
        document.body.innerHTML = tabSet('one', 'two') + tabSet('three', 'four');
    });

    it('only touches the tab set of the clicked link', () => {
        openTab({ currentTarget: document.getElementById('two_tab') }, 'two');
        expect(document.getElementById('two_content_tab').style.display).toBe('block');
        expect(document.getElementById('one_content_tab').style.display).toBe('none');
        expect(document.getElementById('one_tab').className).not.toContain('active');
        expect(document.getElementById('two_tab').className).toContain('active');
        // second set untouched
        expect(document.getElementById('three_content_tab').style.display).toBe('block');
        expect(document.getElementById('three_tab').className).toContain('active');
    });

    it('trims the id and works without a wrapper (document scope)', () => {
        document.body.innerHTML =
            '<div id="x_tab" class="awm_tablinks active"></div><div id="x_content_tab" class="awm_tabcontent"></div><div id="y_content_tab" class="awm_tabcontent" style="display:block"></div>';
        openTab({ currentTarget: document.getElementById('x_tab') }, ' x ');
        expect(document.getElementById('x_content_tab').style.display).toBe('block');
        expect(document.getElementById('y_content_tab').style.display).toBe('none');
    });
});

describe('resolveTabAction / handleTabClick', () => {
    beforeEach(() => {
        document.body.innerHTML = tabSet('one', 'two');
    });

    it('resolves a click on a child of the link to the link and its tab id', () => {
        const action = resolveTabAction(document.querySelector('#one_tab b'));
        expect(action.link.id).toBe('one_tab');
        expect(action.id).toBe('one');
    });

    it('ignores other targets', () => {
        expect(resolveTabAction(document.body)).toBeNull();
        expect(resolveTabAction(null)).toBeNull();
    });

    it('calls the opener with the link as currentTarget', () => {
        const open = jest.fn();
        const link = document.getElementById('two_tab');
        expect(handleTabClick({ target: link }, open)).toBe(true);
        expect(open).toHaveBeenCalledWith({ currentTarget: link }, 'two');
        expect(handleTabClick({ target: document.body }, open)).toBe(false);
        expect(open).toHaveBeenCalledTimes(1);
    });
});
