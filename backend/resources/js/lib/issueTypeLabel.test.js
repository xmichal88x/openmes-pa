import { describe, expect, it } from 'vitest';
import { ISSUE_TYPE_LABEL_KEYS, issueTypeLabel, isSuspendedCycleIssueType } from './issueTypeLabel';

const identity = (key) => key;

describe('issueTypeLabel', () => {
    it('translates a known code using the canonical label, not the DB name', () => {
        const type = { code: 'BAD_CNC_PROGRAM', name: 'Zly program CNC' };
        const translate = (key) => (key === 'Bad CNC Program' ? 'Zły program CNC' : key);

        expect(issueTypeLabel(type, translate)).toBe('Zły program CNC');
    });

    it('translates a known code whose DB name differs from the canonical label', () => {
        const type = { code: 'BAD_CNC_PROGRAM', name: 'Zly program CNC' };

        expect(issueTypeLabel(type, identity)).toBe('Bad CNC Program');
    });

    it('uses the DB name as the key for an unknown code', () => {
        const type = { code: 'SOMETHING_NEW', name: 'Custom Type' };
        const seen = [];
        const translate = (key) => {
            seen.push(key);
            return key;
        };

        expect(issueTypeLabel(type, translate)).toBe('Custom Type');
        expect(seen).toEqual(['Custom Type']);
    });

    it('translates a plain string', () => {
        const translate = (key) => (key === 'Material Defect' ? 'Wada materiału' : key);

        expect(issueTypeLabel('  Material Defect  ', translate)).toBe('Wada materiału');
        expect(issueTypeLabel('Material Defect', identity)).toBe('Material Defect');
    });

    it('returns an empty string for missing or blank input', () => {
        expect(issueTypeLabel(null, identity)).toBe('');
        expect(issueTypeLabel(undefined, identity)).toBe('');
        expect(issueTypeLabel('   ', identity)).toBe('');
        expect(issueTypeLabel({}, identity)).toBe('');
    });

    it('returns the canonical label when a known code has no DB name', () => {
        expect(issueTypeLabel({ code: 'MATERIAL_DEFECT' }, identity)).toBe('Material Defect');
    });

    it('does not throw with the default translator', () => {
        expect(typeof issueTypeLabel({ code: 'OTHER', name: 'Other' })).toBe('string');
        expect(typeof issueTypeLabel('Quality Issue')).toBe('string');
    });
});

describe('isSuspendedCycleIssueType', () => {
    it('is true only for MACHINE_CYCLE_SUSPENDED', () => {
        expect(isSuspendedCycleIssueType({ code: 'MACHINE_CYCLE_SUSPENDED' })).toBe(true);
    });

    it('is false for other codes and empty input', () => {
        expect(isSuspendedCycleIssueType({ code: 'MATERIAL_DEFECT' })).toBe(false);
        expect(isSuspendedCycleIssueType('MACHINE_CYCLE_SUSPENDED')).toBe(false);
        expect(isSuspendedCycleIssueType(null)).toBe(false);
        expect(isSuspendedCycleIssueType(undefined)).toBe(false);
    });
});

describe('ISSUE_TYPE_LABEL_KEYS', () => {
    it('covers 23 codes', () => {
        expect(Object.keys(ISSUE_TYPE_LABEL_KEYS)).toHaveLength(23);
    });
});
