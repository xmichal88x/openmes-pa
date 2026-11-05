<?php

namespace App\Exceptions;

use Exception;

/**
 * Blocking verdict from the queue start-preflight check.
 *
 * `reason` is one of:
 *  - `rebuilding` — the queue is rebuilding nesting for this order; retry later.
 *  - `source`     — the order is the source of a merged sheet; the operator must
 *                   run the merged `target_job` instead of this order.
 */
class StartPreflightException extends Exception
{
    /**
     * @param  array{n_orders?: int, orders?: array<int, array{order_no: string, wo_ids: array<int, int>}>, target_job?: string|null, message?: string}  $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct(
            $context['message'] ?? $this->defaultMessage($reason, $context)
        );
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /** @param array<string, mixed> $context */
    private function defaultMessage(string $reason, array $context): string
    {
        $nOrders = (int) ($context['n_orders'] ?? 0);

        return match ($reason) {
            'rebuilding' => __('Start is blocked: the queue is rebuilding nesting for this order. Try again shortly.'),
            'source' => __('This order is part of a merged sheet (:count orders). Start the merged job :job instead.', [
                'count' => $nOrders,
                'job' => (string) ($context['target_job'] ?? '—'),
            ]),
            default => __('Start is blocked by the queue preflight (:reason).', ['reason' => $reason]),
        };
    }
}
