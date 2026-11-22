<?php

namespace App\Exceptions;

/**
 * A correction of a settled consumption that must not be posted.
 *
 * The refusal is part of the answer, not an incident: the caller (the review
 * screen or an integration) has to learn which rule stopped it — the line is
 * not in the pilot scope, it has not settled yet, the same person would approve
 * what they declared, or the idempotency key already names another document.
 *
 * `Exception::getCode()` is final and integer-only, so the machine-readable
 * reason travels in its own (immutable) property.
 */
class MaterialCorrectionException extends \DomainException
{
    /** Only a pilot (operation-settled) line has a correction path. */
    public const NOT_APPLICABLE = 'material_correction_not_applicable';

    /** Nothing was posted yet: a pending line closes through `settle`, not here. */
    public const REQUIRES_SETTLED = 'material_correction_requires_settled_settlement';

    /** Four eyes: the author of the confirmation cannot approve its correction. */
    public const REQUIRES_OTHER_APPROVER = 'material_correction_requires_other_approver';

    /** The key already names another correction. */
    public const KEY_CONFLICT = 'material_correction_key_conflict';

    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
