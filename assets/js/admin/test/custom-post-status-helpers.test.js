/**
 * Unit tests for the custom post status helpers (`npm run test:js`).
 */
import { statusLabel, mergeStatusRecords, columnLabelFor } from '../custom-post-status-helpers';

const statuses = { in_review: { label: 'In review' }, on_hold: {} };

describe('statusLabel', () => {
    it('uses the configured label, else the key in title case', () => {
        expect(statusLabel('in_review', statuses.in_review)).toBe('In review');
        expect(statusLabel('on_hold', statuses.on_hold)).toBe('On Hold');
    });
});

describe('mergeStatusRecords', () => {
    it('keeps existing statuses and adds the custom ones', () => {
        const records = mergeStatusRecords({ draft: { name: 'Draft', slug: 'draft' } }, statuses);
        expect(records.map((record) => record.slug)).toEqual(['draft', 'in_review', 'on_hold']);
        expect(records[1]).toEqual({ name: 'In review', slug: 'in_review', _links: {} });
    });

    it('accepts the array getEntityRecords returns', () => {
        const records = mergeStatusRecords([{ name: 'Draft', slug: 'draft' }], statuses);
        expect(records.map((record) => record.slug)).toEqual(['draft', 'in_review', 'on_hold']);
    });

    it('copes with an empty store', () => {
        expect(mergeStatusRecords(null, { a: { label: 'A' } })).toEqual([{ name: 'A', slug: 'a', _links: {} }]);
    });
});

describe('columnLabelFor', () => {
    it('matches the key as printed, any case', () => {
        expect(columnLabelFor(' in_review ', statuses)).toBe('In review');
        expect(columnLabelFor('On Hold', statuses)).toBe('on_hold');
    });

    it('returns null for other text, and the label as given (no HTML parsing here)', () => {
        expect(columnLabelFor('Published', statuses)).toBeNull();
        expect(columnLabelFor('x', { x: { label: '<img src=x onerror=alert(1)>' } })).toBe('<img src=x onerror=alert(1)>');
    });
});
