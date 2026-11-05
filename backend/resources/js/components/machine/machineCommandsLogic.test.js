import { describe, expect, it } from 'vitest';
import pl from '../../../../lang/pl.json';
import {
    ERROR_KEYS,
    buildCommandPayload,
    capabilityReasonKey,
    errorMessageKey,
    formatCommandType,
    hasActive,
    isActiveCommand,
    latestActive,
    newIdempotencyKey,
    outcomeKey,
    shouldShowPanel,
    statusTone,
    stepStatusKey,
} from './machineCommandsLogic';

const cncCaps = {
    is_cnc: true,
    can_load: true,
    can_m30: false,
    can_labels: true,
    line_id: 7,
};

const pending = { id: 1, type: 'load_nc', status: 'pending' };
const processing = { id: 2, type: 'generate_labels', status: 'processing' };
const done = { id: 3, type: 'load_nc', status: 'done' };
const failed = { id: 4, type: 'manual_m30', status: 'failed' };
const cancelled = { id: 5, type: 'manual_m30', status: 'cancelled' };

describe('isActiveCommand', () => {
    it('treats pending and processing as active', () => {
        expect(isActiveCommand(pending)).toBe(true);
        expect(isActiveCommand(processing)).toBe(true);
    });

    it('treats terminal and missing commands as inactive', () => {
        expect(isActiveCommand(done)).toBe(false);
        expect(isActiveCommand(failed)).toBe(false);
        expect(isActiveCommand(cancelled)).toBe(false);
        expect(isActiveCommand(null)).toBe(false);
    });
});

describe('hasActive', () => {
    it('reports whether any command is still running', () => {
        expect(hasActive([done, pending])).toBe(true);
        expect(hasActive([done, failed, cancelled])).toBe(false);
    });

    it('is false for a missing list', () => {
        expect(hasActive(null)).toBe(false);
        expect(hasActive(undefined)).toBe(false);
    });
});

describe('latestActive', () => {
    it('returns the first active command in server order', () => {
        expect(latestActive([done, processing, pending])).toBe(processing);
    });

    it('returns null when nothing is active', () => {
        expect(latestActive([done, failed])).toBeNull();
        expect(latestActive(null)).toBeNull();
    });
});

describe('statusTone', () => {
    it('maps each status onto a StatusPill tone', () => {
        expect(statusTone('pending')).toBe('pending');
        expect(statusTone('processing')).toBe('running');
        expect(statusTone('done')).toBe('done');
        expect(statusTone('failed')).toBe('blocked');
        expect(statusTone('cancelled')).toBe('blocked');
    });

    it('falls back to pending for an unknown status', () => {
        expect(statusTone('queued')).toBe('pending');
        expect(statusTone(undefined)).toBe('pending');
    });
});

describe('capabilityReasonKey', () => {
    it('maps backend reasons onto translation keys', () => {
        expect(capabilityReasonKey('feature_disabled')).toBe('Feature disabled');
        expect(capabilityReasonKey('not_cnc')).toBe('Not a CNC step');
        expect(capabilityReasonKey('step_not_in_progress')).toBe('Step is not in progress');
        expect(capabilityReasonKey('line_not_allowed')).toBe('Line not allowed');
        expect(capabilityReasonKey('line_mismatch')).toBe('Workstation is on another line');
        expect(capabilityReasonKey('forbidden')).toBe('No permission');
    });

    it('returns null when the capability is available', () => {
        expect(capabilityReasonKey(null)).toBeNull();
        expect(capabilityReasonKey('something_new')).toBeNull();
    });
});

describe('formatCommandType', () => {
    it('maps command types onto translation keys', () => {
        expect(formatCommandType('load_nc')).toBe('Load NC');
        expect(formatCommandType('manual_m30')).toBe('Finish cut (M30)');
        expect(formatCommandType('generate_labels')).toBe('Generate labels');
    });

    it('falls back to the raw type for an unknown value', () => {
        expect(formatCommandType('feed_hold')).toBe('feed_hold');
        expect(formatCommandType(null)).toBe('');
    });
});

describe('stepStatusKey', () => {
    it('maps backend step statuses onto translation keys', () => {
        expect(stepStatusKey('IN_PROGRESS')).toBe('In progress');
        expect(stepStatusKey('PENDING')).toBe('Pending');
        expect(stepStatusKey('READY')).toBe('Ready');
        expect(stepStatusKey('DONE')).toBe('Done');
        expect(stepStatusKey('SKIPPED')).toBe('Skipped');
    });

    it('falls back to the raw status', () => {
        expect(stepStatusKey('WEIRD')).toBe('WEIRD');
        expect(stepStatusKey(null)).toBe('');
    });
});

describe('outcomeKey', () => {
    it('maps gateway outcomes onto translation keys', () => {
        expect(outcomeKey('ok')).toBe('OK');
        expect(outcomeKey('already_loaded')).toBe('Already loaded');
        expect(outcomeKey('already_exists')).toBe('Already exists');
        expect(outcomeKey('skipped')).toBe('Skipped');
        expect(outcomeKey('duplicate')).toBe('Duplicate');
    });

    it('is empty for a missing outcome', () => {
        expect(outcomeKey(null)).toBe('');
        expect(outcomeKey(undefined)).toBe('');
    });
});

describe('errorMessageKey', () => {
    it('maps known gateway errors onto operator messages', () => {
        expect(errorMessageKey('cycle_active_unconfirmed')).toBe(
            'The program was not confirmed as loaded. Check that the correct program is on the machine, then try again.',
        );
        expect(errorMessageKey('cycle_active_other_sheet')).toBe(
            'A program for a different sheet is loaded on the machine. This sheet was not loaded.',
        );
        expect(errorMessageKey('state_read_failed')).toBe(
            'Could not read the machine state. Try again in a moment.',
        );
    });

    it('ignores surrounding whitespace on known codes', () => {
        expect(errorMessageKey('  state_read_failed\n')).toBe(
            'Could not read the machine state. Try again in a moment.',
        );
    });

    it('keeps unknown errors visible verbatim', () => {
        expect(errorMessageKey('some_new_gateway_code')).toBe('some_new_gateway_code');
        expect(errorMessageKey('The machine command lease expired.')).toBe('The machine command lease expired.');
    });

    it('is empty for a missing or non-string error', () => {
        expect(errorMessageKey(null)).toBe('');
        expect(errorMessageKey(undefined)).toBe('');
        expect(errorMessageKey({ code: 'state_read_failed' })).toBe('');
        expect(errorMessageKey(['state_read_failed'])).toBe('');
        expect(errorMessageKey(42)).toBe('');
    });

    it('is empty for whitespace-only errors', () => {
        expect(errorMessageKey('')).toBe('');
        expect(errorMessageKey('   ')).toBe('');
        expect(errorMessageKey('\n\t ')).toBe('');
    });

    it('trims unknown errors before returning them', () => {
        expect(errorMessageKey('  some_new_gateway_code  ')).toBe('some_new_gateway_code');
        expect(errorMessageKey(' The machine command lease expired. ')).toBe('The machine command lease expired.');
    });

    it('has a distinct Polish translation for every known code', () => {
        for (const code of Object.keys(ERROR_KEYS)) {
            const source = errorMessageKey(code);
            const translated = pl[source];

            expect(source).not.toBe('');
            expect(typeof translated).toBe('string');
            expect(translated.trim()).not.toBe('');
            expect(translated).not.toBe(source);
        }
    });
});

describe('shouldShowPanel', () => {
    it('shows the panel for a CNC step with any action', () => {
        expect(shouldShowPanel(cncCaps)).toBe(true);
        expect(shouldShowPanel({ ...cncCaps, can_load: false, can_labels: false, can_m30: true })).toBe(true);
    });

    it('keeps the panel for a CNC step with history', () => {
        const blocked = { ...cncCaps, can_load: false, can_labels: false };
        expect(shouldShowPanel(blocked, [pending])).toBe(true);
        expect(shouldShowPanel(blocked, [], pending)).toBe(true);
    });

    it('hides the panel when there is nothing to do or show', () => {
        expect(shouldShowPanel({ ...cncCaps, can_load: false, can_labels: false })).toBe(false);
        expect(shouldShowPanel({ ...cncCaps, is_cnc: false })).toBe(false);
        expect(shouldShowPanel(null)).toBe(false);
    });
});

describe('buildCommandPayload', () => {
    it('sends only the type for load and labels', () => {
        expect(buildCommandPayload('load_nc')).toEqual({ type: 'load_nc' });
        expect(buildCommandPayload('generate_labels', { force: true, reason: 'x', canM30: true }))
            .toEqual({ type: 'generate_labels' });
    });

    it('includes the trimmed reason for manual_m30', () => {
        expect(buildCommandPayload('manual_m30', { reason: '  awaria wrzeciona  ' }))
            .toEqual({ type: 'manual_m30', reason: 'awaria wrzeciona' });
    });

    it('adds force only for manual_m30 when canM30 is true', () => {
        expect(buildCommandPayload('manual_m30', { reason: 'awaria', force: true, canM30: true }))
            .toEqual({ type: 'manual_m30', reason: 'awaria', force: true });
        expect(buildCommandPayload('manual_m30', { reason: 'awaria', force: true, canM30: false }))
            .toEqual({ type: 'manual_m30', reason: 'awaria' });
    });

    it('does not validate the reason length (that is UI validation)', () => {
        expect(buildCommandPayload('manual_m30', { reason: 'abc' }))
            .toEqual({ type: 'manual_m30', reason: 'abc' });
    });
});

describe('newIdempotencyKey', () => {
    it('uses crypto.randomUUID when available', () => {
        const key = '11111111-2222-4333-8444-555555555555';
        expect(newIdempotencyKey({ randomUUID: () => key })).toBe(key);
    });

    it('generates a v4-shaped key without randomUUID', () => {
        expect(newIdempotencyKey({})).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
    });
});
