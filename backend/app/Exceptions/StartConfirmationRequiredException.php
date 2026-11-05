<?php

namespace App\Exceptions;

use Exception;

/**
 * The order is merged into a shared sheet but is not its source: starting it
 * means starting shared work for every order on that sheet. The operator must
 * explicitly confirm (`confirm_shared_sheet`) before the step may start.
 */
class StartConfirmationRequiredException extends Exception
{
    /**
     * @param  array{n_orders?: int, orders?: array<int, array{order_no: string, wo_ids: array<int, int>}>, target_job?: string|null, message?: string}  $context
     */
    public function __construct(
        public readonly array $context = [],
    ) {
        parent::__construct(
            $context['message'] ?? __('This sheet is shared by :count orders. Confirm to start the shared work for all of them.', [
                'count' => (int) ($context['n_orders'] ?? 0),
            ])
        );
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
