<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain failure for the machine-command outbox (P1a).
 *
 * The service throws this instead of abort()/HTTP-aware exceptions: the HTTP
 * layer (P1b) maps `reason`/`httpStatus` onto the API response. Keeping it
 * transport-free lets the same guards run from a future scheduler or console
 * command.
 */
class MachineCommandException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function featureDisabled(string $feature = 'machine_commands'): self
    {
        // 409: the feature exists but this instance has it switched off. It is
        // not a client input error, so 422 would be misleading.
        return new self(
            'feature_disabled',
            __('Machine commands are disabled (:feature).', ['feature' => $feature]),
            409,
            ['feature' => $feature],
        );
    }

    public static function invalidType(string $type): self
    {
        return new self(
            'invalid_type',
            __('Unknown machine command type ":type".', ['type' => $type]),
            422,
            ['type' => $type],
        );
    }

    public static function invalidStep(string $message): self
    {
        return new self('invalid_step', $message, 422);
    }

    public static function stepMismatch(): self
    {
        return new self(
            'batch_step_mismatch',
            __('The batch step in the body does not match the step in the route.'),
            422,
        );
    }

    public static function forbidden(): self
    {
        return new self(
            'forbidden',
            __('You are not allowed to issue this machine command.'),
            403,
        );
    }

    public static function notCncStep(): self
    {
        return new self(
            'not_cnc_step',
            __('This batch step is not a CNC step — machine commands are not allowed here.'),
            422,
        );
    }

    public static function invalidStepStatus(string $status, string $type): self
    {
        return new self(
            'invalid_step_status',
            __('A :type command requires an IN_PROGRESS step (current: :status).', [
                'type' => $type,
                'status' => $status,
            ]),
            422,
            ['status' => $status, 'type' => $type],
        );
    }

    public static function lineForbidden(?int $lineId = null): self
    {
        return new self(
            'line_forbidden',
            __('You do not have access to this line.'),
            403,
            ['line_id' => $lineId],
        );
    }

    public static function lineNotAllowed(string $code): self
    {
        return new self(
            'line_forbidden',
            __('Line :code is not allowed to issue machine commands.', ['code' => $code]),
            403,
            ['line_code' => $code],
        );
    }

    public static function commandInProgress(string $type, ?int $stepId = null): self
    {
        return new self(
            'command_in_progress',
            __('An active :type command already exists for this step.', ['type' => $type]),
            409,
            ['type' => $type, 'batch_step_id' => $stepId],
        );
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            'idempotency_conflict',
            __('This idempotency key was already used for a different request.'),
            409,
        );
    }

    public static function reasonRequired(string $type): self
    {
        return new self(
            'reason_required',
            __('A :type command requires a reason (at least 5 characters).', ['type' => $type]),
            422,
            ['type' => $type],
        );
    }

    public static function invalidTransition(?string $from = null, ?string $to = null): self
    {
        return new self(
            'invalid_transition',
            __('Cannot change the machine command from :from to :to.', [
                'from' => $from ?? '—',
                'to' => $to ?? '—',
            ]),
            409,
            ['from' => $from, 'to' => $to],
        );
    }

    public static function leaseLost(): self
    {
        return new self(
            'lease_lost',
            __('The machine command lease has expired or belongs to another worker.'),
            409,
        );
    }

    public static function invalidOutcome(?string $outcome = null): self
    {
        return new self(
            'invalid_outcome',
            __('A done command requires a valid outcome.'),
            422,
            ['outcome' => $outcome],
        );
    }

    public static function deliveryNotAllowed(string $type): self
    {
        return new self(
            'delivery_not_allowed',
            __('A delivery id is only valid for manual_m30 commands (current: :type).', ['type' => $type]),
            422,
            ['type' => $type],
        );
    }

    public static function deliveryMismatch(): self
    {
        return new self(
            'delivery_mismatch',
            __('The delivery id does not match the one assigned to this manual_m30 command.'),
            409,
        );
    }

    public static function forceNotAllowed(string $type): self
    {
        return new self(
            'force_not_allowed',
            __('The force flag is only valid for manual_m30 commands (current: :type).', ['type' => $type]),
            422,
            ['type' => $type],
        );
    }

    public static function invalidPayload(string $field, string $message): self
    {
        return new self(
            'invalid_payload',
            $message,
            422,
            ['field' => $field],
        );
    }

    public static function errorRequired(): self
    {
        return new self(
            'error_required',
            __('A failed command requires an error message.'),
            422,
        );
    }

    public static function requeueNotAllowed(string $errorClass): self
    {
        return new self(
            'requeue_not_allowed',
            __('Only transient failures may return a command to the queue (current: :class).', [
                'class' => $errorClass,
            ]),
            409,
            ['error_class' => $errorClass],
        );
    }

    public function getStatusCode(): int
    {
        return $this->httpStatus;
    }
}
