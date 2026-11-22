<?php

namespace App\Services\WorkOrder;

use App\Models\Batch;
use App\Models\WorkOrder;
use App\Services\Settings\RuntimeSettings;

/**
 * The work order's planned quantity is the one limit every door creating or
 * resizing a batch has to pass (RQ-PA-273).
 *
 * It started inside `Api\V1\BatchController`, which meant the operator's web
 * path (`Web\Operator\BatchController::store`) could add batches without any
 * plan check at all. One rule, one place: the projection, the comparison and
 * the message live here, and every caller reads the same numbers.
 *
 * Semantics carried over unchanged from the controller (do not "improve" them
 * without an owner decision):
 * - replacement batches (re-cuts, RQ-PA-214) live outside the plan;
 * - cancelled batches no longer consume capacity;
 * - a legacy NULL `is_replacement` still counts;
 * - the comparison is tolerant by 0.001 because quantities are decimal;
 * - the stored switch decides whether overproduction is allowed at all
 *   (Settings -> System), with the env var only as a declared default.
 */
class BatchPlanGuard
{
    /** Plan comparison tolerance: quantities are decimal, so exact equality is not a test. */
    public const TOLERANCE = 0.001;

    public function __construct(protected RuntimeSettings $runtimeSettings) {}

    /**
     * The planned total the work order would carry once `$additionalQty` is added.
     *
     * When a batch itself is being edited it is already inside the sum, so it is
     * excluded and re-added as `$additionalQty` rather than counted twice.
     */
    public function projectedTotal(WorkOrder $workOrder, float $additionalQty, ?Batch $exclude = null): float
    {
        $sum = $workOrder->batches()
            ->where(fn ($query) => $query->whereNull('is_replacement')->orWhere('is_replacement', false))
            ->where('status', '!=', Batch::STATUS_CANCELLED)
            ->when($exclude !== null, fn ($query) => $query->where('id', '!=', $exclude->getKey()))
            ->sum('target_qty');

        return (float) $sum + $additionalQty;
    }

    /**
     * Whether the projection overshoots the plan by more than the qty tolerance.
     *
     * Must be read under the work order row lock (see the callers): the sum and
     * the write it guards have to be one decision, otherwise two concurrent
     * requests both pass the check and together overshoot the plan.
     */
    public function exceeds(WorkOrder $workOrder, float $additionalQty, ?Batch $exclude = null): bool
    {
        if ($this->runtimeSettings->allowOverproduction()) {
            return false;
        }

        return ($this->projectedTotal($workOrder, $additionalQty, $exclude) - (float) $workOrder->planned_qty) > self::TOLERANCE;
    }

    /**
     * The refusal naming the numbers, for the callers whose message carries them.
     *
     * The bare message on the API `store()` path stays as it is (parity with the
     * published contract); this form is what the operator UI and the PATCH path
     * answer with.
     */
    public function message(WorkOrder $workOrder, float $projected): string
    {
        return sprintf(
            'Total batch quantity would exceed planned quantity (%s of %s)',
            self::formatQty($projected),
            self::formatQty((float) $workOrder->planned_qty),
        );
    }

    /** Numbers in the message without trailing zeros ("11" not "11.000"). */
    public static function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }
}
