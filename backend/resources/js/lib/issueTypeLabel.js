import { __ } from './i18n';

/**
 * Canonical English labels for issue-type codes. They double as the i18n keys,
 * so an untranslated locale shows readable English instead of a raw code.
 */
export const ISSUE_TYPE_LABEL_KEYS = {
    MATERIAL_DEFECT: 'Material Defect',
    MATERIAL_SHORTAGE: 'Material Shortage',
    TOOL_FAILURE: 'Tool / Equipment Failure',
    QUALITY_ISSUE: 'Quality Issue',
    PROCESS_CLARIFICATION: 'Process Clarification Required',
    SAFETY_CONCERN: 'Safety Concern',
    MEASUREMENT_ERROR: 'Measurement / Dimension Error',
    OPERATOR_ASSISTANCE: 'Operator Assistance Required',
    INBOUND_QC_FAIL: 'Inbound Quality — Inspection Failed',
    IN_PROCESS_QC_FAIL: 'In-process Quality — Control Failed',
    OTHER: 'Other Issue',
    BAD_CNC_PROGRAM: 'Bad CNC Program',
    MOISTURE: 'Board Moisture / Swollen Edge',
    DECOR_MISMATCH: 'Decor Batch Mismatch',
    EDGE_LIFT: 'Edge Tape Lifting / Open Joint',
    BORE_POSITION: 'Hole Pattern Out of Position',
    PANEL_SIZE: 'Panel Out of Size / Out of Square',
    FITTING_SHORT: 'Fittings Pack Short or Wrong',
    BOARD_DAMAGE: 'Board Face Damaged or Scratched',
    CHIPPING: 'Saw Chipping on Face',
    GLOSS_DEFECT: 'Gloss Front Orange Peel or Pinhole',
    CARTON_DAMAGE: 'Carton Crushed or Split',
    MACHINE_CYCLE_SUSPENDED: 'Machine Cycle Suspended',
};

/**
 * Translated issue-type label. Accepts a type object (`{ code, name }`) or a
 * plain string. A known code always resolves through its canonical English
 * label, so an untranslated locale shows that label instead of the DB name.
 * An unknown code falls back to the DB name, translated when it is a key.
 */
export function issueTypeLabel(type, translate = __) {
    if (type == null) return '';

    if (typeof type === 'string') {
        const name = type.trim();
        if (!name) return '';
        const translated = translate(name);
        return translated === name ? name : translated;
    }

    const knownKey = ISSUE_TYPE_LABEL_KEYS[type.code];
    if (knownKey) return translate(knownKey);

    const name = String(type.name ?? '').trim();
    if (!name) return '';
    const translated = translate(name);
    return translated === name ? name : translated;
}

/** True only for the issue type that suspends a machine cycle. */
export function isSuspendedCycleIssueType(type) {
    return type?.code === 'MACHINE_CYCLE_SUSPENDED';
}
