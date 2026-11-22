<?php

namespace App\Services\Material;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\MaterialCorrectionException;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\BatchStepLotConsumption;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\StockMovementReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaterialAllocationService
{
    /** The number was declared by a person at the machine. */
    public const SOURCE_OPERATOR = 'operator';

    /** The number was booked by the system itself (packaging, batch completion). */
    public const SOURCE_SYSTEM = 'system';

    public function __construct(
        protected StockMovementService $stockMovements,
        protected LotPickingService $lotPicking,
        protected ConsumptionLocationService $consumptionLocation,
    ) {}

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     *                                                                                                                     Operator-chosen lot picks keyed by material id (WO-time override).
     * @param  int|null  $attributeStepId  Step to attribute these allocations to for
     *                                     genealogy (sets batch_step_id without changing the stock-movement source).
     */
    public function allocateForBatch(Batch $batch, User $user, array $picksByMaterial = [], ?int $attributeStepId = null): Collection
    {
        return $this->allocateMatching(
            $batch,
            $user,
            fn ($bom) => $this->isStartItem($bom),
            picksByMaterial: $picksByMaterial,
            attributeStepId: $attributeStepId,
        );
    }

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     */
    public function allocateForStep(BatchStep $step, User $user, array $picksByMaterial = []): Collection
    {
        $batch = $step->batch;
        $stepNumber = $step->step_number;

        return $this->allocateMatching(
            $batch,
            $user,
            fn ($bom) => $this->isDuringItem($bom) && (int) ($bom['step_number'] ?? 0) === $stepNumber,
            stepId: $step->id,
            picksByMaterial: $picksByMaterial,
        );
    }

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     */
    public function allocateForBatchEnd(Batch $batch, User $user, array $picksByMaterial = [], ?int $attributeStepId = null): Collection
    {
        return $this->allocateMatching(
            $batch,
            $user,
            fn ($bom) => $this->isEndItem($bom),
            picksByMaterial: $picksByMaterial,
            attributeStepId: $attributeStepId,
        );
    }

    public function previewForBatch(Batch $batch): array
    {
        return $this->previewBom(
            $batch->workOrder->process_snapshot['bom'] ?? [],
            (float) $batch->target_qty,
        );
    }

    /**
     * The same component check one level up: can this work order's planned
     * quantity be covered at all?
     *
     * Asked while the order is still only scheduled — before any batch exists —
     * so the planner and the order screen can flag a shortage rather than
     * letting it surface when an operator tries to start.
     *
     * A subassembly is a BOM line like any other here: the snapshot is read as
     * written, so "not enough pleat packs" is reported as such rather than
     * being resolved into the media it would be made from.
     */
    public function previewForWorkOrder(WorkOrder $workOrder): array
    {
        return $this->previewBom(
            $workOrder->process_snapshot['bom'] ?? [],
            (float) $workOrder->planned_qty,
        );
    }

    /**
     * Which of these work orders current stock cannot cover, and by what.
     *
     * Every material across every snapshot is loaded in one pass, so a planner
     * board can be flagged without a query per tile. By default each order is
     * measured on its own against on-hand — orders are not netted against each
     * other, so this answers "could this one run today", not "can the whole week".
     *
     * With `$netted`, orders are taken in the given sequence and each covered
     * order consumes the stock it needs before the next is measured — the
     * question a scheduler asks ("can all of these run, in this order?"). An
     * order that is short consumes nothing: it will not run, so it does not
     * take stock from the orders behind it.
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     * @return array<int, list<array<string, mixed>>> short lines keyed by work
     *                                                order id; covered orders are absent
     */
    public function shortagesForWorkOrders(Collection $workOrders, bool $netted = false): array
    {
        $consumed = []; // material id => quantity taken by earlier covered orders (netted mode)
        $workOrders = $workOrders->reject(fn ($order) => in_array($order->status, ['DONE', 'CANCELLED', 'REJECTED'], true));
        $boms = [];
        $ids = [];
        $codes = [];

        foreach ($workOrders as $workOrder) {
            $bom = $workOrder->process_snapshot['bom'] ?? [];
            if (empty($bom)) {
                continue;
            }

            $boms[$workOrder->id] = $bom;

            foreach ($bom as $bomItem) {
                if (! empty($bomItem['material_id'])) {
                    $ids[] = $bomItem['material_id'];
                } elseif (! empty($bomItem['material_code'])) {
                    $codes[] = $bomItem['material_code'];
                }
            }
        }

        if ($boms === []) {
            return [];
        }

        $byId = $ids !== []
            ? Material::whereIn('id', array_unique($ids))->get()->keyBy('id')
            : collect();
        $byCode = $codes !== []
            ? Material::whereIn('code', array_unique($codes))->get()->keyBy('code')
            : collect();

        $covered = MaterialAllocation::whereIn('work_order_id', $workOrders->pluck('id'))
            ->where('status', '!=', MaterialAllocation::STATUS_RETURNED)
            ->whereHas('batch', fn ($query) => $query->where('status', '!=', 'CANCELLED'))
            ->get()->groupBy('work_order_id')->map(fn ($rows) => $rows->groupBy('material_id')
            ->map(fn ($items) => $items->sum(fn ($item) => max(0, (float) $item->allocated_qty - (float) $item->returned_qty))));
        $out = [];

        foreach ($workOrders as $workOrder) {
            $bom = $boms[$workOrder->id] ?? null;
            if (! $bom) {
                continue;
            }

            $short = [];
            $takes = [];
            $remainingCovered = ($covered->get($workOrder->id) ?? collect())->all();

            $requirements = [];
            foreach ($bom as $bomItem) {
                $material = ! empty($bomItem['material_id'])
                    ? $byId->get($bomItem['material_id'])
                    : null;
                if (! $material && ! empty($bomItem['material_code'])) {
                    $material = $byCode->get($bomItem['material_code']);
                }

                $key = $material ? 'id:'.$material->id : 'missing:'.($bomItem['material_id'] ?? $bomItem['material_code'] ?? json_encode($bomItem));
                $requirements[$key] ??= ['material' => $material, 'item' => $bomItem, 'quantity' => 0.0];
                $requirements[$key]['quantity'] += $this->calculateRequiredQty($bomItem, (float) $workOrder->planned_qty);
            }

            foreach ($requirements as $requirement) {
                $material = $requirement['material'];
                $bomItem = $requirement['item'];
                $required = max(0, $requirement['quantity'] - ($remainingCovered[$material?->id] ?? 0));
                if ($required <= 0) {
                    continue;
                }
                // Keep the signed accounting balance; operators need usable stock.
                $available = max(0, (float) ($material?->available_quantity ?? 0) - ($material ? ($consumed[$material->id] ?? 0) : 0));

                if ($material && $available >= $required) {
                    $takes[$material->id] = ($takes[$material->id] ?? 0) + $required;

                    continue;
                }

                $short[] = [
                    'material_code' => $bomItem['material_code'] ?? $material?->code,
                    'material_name' => $bomItem['material_name'] ?? $material?->name,
                    'unit_of_measure' => $bomItem['unit_of_measure'] ?? $material?->unit_of_measure,
                    'required_qty' => round($required, 4),
                    'available_qty' => round($available, 4),
                    'missing_qty' => round(max(0, $required - $available), 4),
                    'material_exists' => $material !== null,
                    // Available is on-hand minus what other batches have already
                    // reserved, so a full store can still read as zero available.
                    // Carry both halves: without them the shortage looks like the
                    // system losing stock rather than stock being spoken for.
                    'on_hand_qty' => round((float) ($material?->stock_quantity ?? 0), 4),
                    'reserved_qty' => round((float) ($material?->reserved_quantity ?? 0), 4),
                ];
            }

            if ($short !== []) {
                $out[$workOrder->id] = $short;
            } elseif ($netted) {
                foreach ($takes as $materialId => $qty) {
                    $consumed[$materialId] = ($consumed[$materialId] ?? 0) + $qty;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bom
     */
    private function previewBom(array $bom, float $quantity): array
    {
        $preview = [];

        // Bulk load materials referenced by the BOM to avoid N+1. The
        // preview is read-only, so we deliberately skip lockForUpdate
        // (also: locking outside a transaction is a no-op on Postgres
        // and only adds noise/contention on SQLite).
        $materialIds = [];
        $materialCodes = [];
        foreach ($bom as $bomItem) {
            if (! empty($bomItem['material_id'])) {
                $materialIds[] = $bomItem['material_id'];
            } elseif (! empty($bomItem['material_code'])) {
                $materialCodes[] = $bomItem['material_code'];
            }
        }

        $materialsById = ! empty($materialIds)
            ? Material::whereIn('id', array_unique($materialIds))->get()->keyBy('id')
            : collect();
        $materialsByCode = ! empty($materialCodes)
            ? Material::whereIn('code', array_unique($materialCodes))->get()->keyBy('code')
            : collect();

        foreach ($bom as $bomItem) {
            $material = null;
            if (! empty($bomItem['material_id'])) {
                $material = $materialsById->get($bomItem['material_id']);
            }
            if (! $material && ! empty($bomItem['material_code'])) {
                $material = $materialsByCode->get($bomItem['material_code']);
            }
            $requiredQty = $this->calculateRequiredQty($bomItem, $quantity);
            $available = $material?->available_quantity ?? 0;

            $preview[] = [
                'material_name' => $bomItem['material_name'] ?? $material?->name,
                'material_code' => $bomItem['material_code'] ?? $material?->code,
                'unit_of_measure' => $bomItem['unit_of_measure'] ?? $material?->unit_of_measure,
                'required_qty' => $requiredQty,
                // Available = on-hand minus what is already reserved by other batches.
                'available_qty' => $available,
                'on_hand_qty' => (float) ($material?->stock_quantity ?? 0),
                'reserved_qty' => (float) ($material?->reserved_quantity ?? 0),
                'sufficient' => $material ? $available >= $requiredQty : false,
                'material_exists' => $material !== null,
                'consumed_at' => $bomItem['consumed_at'] ?? 'start',
                'step_number' => $bomItem['step_number'] ?? null,
                'estimated_cost' => $material?->unit_price
                    ? round((float) $material->unit_price * $requiredQty, 2)
                    : null,
                'currency' => $material?->price_currency,
            ];
        }

        return $preview;
    }

    /**
     * Build the WO-time lot-picking proposal for starting a given step: per
     * lot-tracked material that this step start would allocate, the required
     * quantity, the system's proposed lot split, and the candidate lots. Returns
     * an empty array when lot tracking is off or nothing needs picking - the UI
     * then skips the modal and starts the step directly. Read-only.
     *
     * @return array<int, array{material_id: int, material_name: ?string, material_code: ?string, unit_of_measure: ?string, required_qty: float, strategy: string, proposed: array, candidates: array}>
     */
    public function pickPreviewForStep(BatchStep $step): array
    {
        if (! $this->lotPicking->isLotTrackingEnabled()) {
            return [];
        }

        $batch = $step->batch;
        $bom = $batch->workOrder->process_snapshot['bom'] ?? [];
        if (empty($bom)) {
            return [];
        }

        // Start-items only allocate on the first start (batch still PENDING),
        // mirroring BatchService::startStep's $wasPending gate.
        $batchPending = $batch->status === Batch::STATUS_PENDING;
        $stepNumber = $step->step_number;

        $out = [];
        foreach ($bom as $bomItem) {
            $isStart = $this->isStartItem($bomItem) && $batchPending;
            $isDuringThisStep = $this->isDuringItem($bomItem)
                && (int) ($bomItem['step_number'] ?? 0) === $stepNumber;

            if (! $isStart && ! $isDuringThisStep) {
                continue;
            }

            $material = $this->resolveMaterialReadonly($bomItem);
            if (! $material) {
                continue;
            }

            // Phase 1: lots/batches only - serial-tracked materials are handled elsewhere.
            if ($material->tracking_type === 'serial') {
                continue;
            }

            // Skip materials already allocated for this batch (mirror allocateMatching's guard).
            $alreadyAllocated = MaterialAllocation::where('batch_id', $batch->id)
                ->where('material_id', $material->id)
                ->exists();
            if ($alreadyAllocated) {
                continue;
            }

            $requiredQty = $this->calculateRequiredQty($bomItem, (float) $batch->target_qty);
            $proposal = $this->lotPicking->proposePicks($material, $requiredQty);

            $out[] = [
                'material_id' => $material->id,
                'material_name' => $bomItem['material_name'] ?? $material->name,
                'material_code' => $bomItem['material_code'] ?? $material->code,
                'unit_of_measure' => $bomItem['unit_of_measure'] ?? $material->unit_of_measure,
                'required_qty' => $requiredQty,
                'strategy' => $proposal['strategy'],
                'proposed' => $proposal['proposed'],
                'candidates' => $proposal['candidates'],
            ];
        }

        return $out;
    }

    /**
     * Mark allocations as consumed when batch is completed. Reads each
     * allocation's consumed_qty (set by recordConsumption) and falls back
     * to allocated_qty for any rows where the operator did not record an
     * explicit number. Also releases the reservation and applies any
     * leftover difference (returned + scrap) back to stock.
     *
     * Allocations in the pilot settlement scope that still owe a confirmation
     * are left completely alone — no stock movement, no reservation release,
     * no location deduction, no genealogy, no status change — and come back in
     * `pending` with the reason. This is the COGI / Pending Transactions rule:
     * a missing retrieval is never stood in for by the plan, and the rest of
     * the batch settles normally.
     *
     * A declared retrieval above the allocation is unplanned consumption: that
     * material physically left the store beyond what was withdrawn, so the excess
     * is booked as a consumption of its own. Without it the plant-wide balance
     * would keep claiming stock that no longer exists. Only the pilot scope does
     * this — the legacy scope keeps its pre-existing behaviour untouched.
     *
     * @return array{settled: int, pending: array<int, array{allocation_id: int, material_id: int, reason: string}>, unplanned: array<int, array{allocation_id: int, material_id: int, quantity: float}>}
     */
    public function consumeForBatch(Batch $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $allocations = MaterialAllocation::where('batch_id', $batch->id)
                ->where('status', MaterialAllocation::STATUS_ALLOCATED)
                ->lockForUpdate()
                ->with(['material', 'lotPicks', 'workOrder'])
                ->get();

            $policy = app(MaterialSettlementPolicy::class);
            $settled = 0;
            $pending = [];
            $unplanned = [];

            foreach ($allocations as $allocation) {
                // Waiting for settlement: nothing about it may move.
                $reason = $policy->pendingReason($allocation);
                if ($reason !== null) {
                    $pending[] = [
                        'allocation_id' => (int) $allocation->id,
                        'material_id' => (int) $allocation->material_id,
                        'reason' => $reason,
                    ];

                    continue;
                }
                // Bridge the picked lots into the ISA-95 genealogy table so
                // forward/backward traceability reflects what was actually
                // consumed. consumeForBatch runs once per batch (allocations
                // flip to CONSUMED), so this never double-writes.
                $this->writeGenealogy($allocation);

                // Use the operator-declared quantity when consumption was recorded —
                // including an explicit zero (nothing used, return everything). Only
                // fall back to the planned quantity when nothing was ever declared.
                $actualConsumed = $allocation->consumption_recorded
                    ? (float) $allocation->consumed_qty
                    : (float) $allocation->allocated_qty;

                $declared = $actualConsumed + (float) $allocation->scrap_qty;

                // Pilot only: what the operation retrieved above what it withdrew
                // came off the store unannounced, and has to be booked as such.
                $excessOfAllocation = 0.0;
                if ($allocation->consumption_recorded
                    && $declared > (float) $allocation->allocated_qty
                    && $policy->modeFor($allocation->workOrder, (int) $allocation->material_id) === MaterialSettlementPolicy::MODE_OPERATION) {
                    $excessOfAllocation = round($declared - (float) $allocation->allocated_qty, 4);
                }

                $leftoverToReturn = max(0, (float) $allocation->allocated_qty - $declared);

                $this->releaseReservation($allocation->material, (float) $allocation->allocated_qty);

                if ($leftoverToReturn > 0 && $allocation->material) {
                    $this->stockMovements->record(
                        $allocation->material,
                        StockMovement::TYPE_RETURN,
                        $leftoverToReturn,
                        sourceType: StockMovement::SOURCE_BATCH,
                        sourceId: $batch->id,
                        reason: StockMovementReason::make('Batch #:batch completed — leftover returned to stock', ['batch' => $batch->id]),
                    );
                }

                if ((float) $allocation->scrap_qty > 0 && $allocation->material) {
                    $this->stockMovements->record(
                        $allocation->material,
                        StockMovement::TYPE_SCRAP,
                        0, // scrap is a status change, not a stock delta — already left stock at allocation time
                        sourceType: StockMovement::SOURCE_BATCH,
                        sourceId: $batch->id,
                        reason: StockMovementReason::make('Batch #:batch scrap qty recorded', ['batch' => $batch->id]),
                    );
                }

                // Unplanned consumption above the allocation: booked before the
                // location is finalised, and without an actor — the movement says
                // what left the plant, not who declared it (that is on the
                // confirmation itself). No reservation is released for it: only
                // what was allocated was ever reserved.
                if ($excessOfAllocation > 0 && $allocation->material) {
                    $this->stockMovements->record(
                        $allocation->material,
                        StockMovement::TYPE_CONSUME,
                        -$excessOfAllocation,
                        sourceType: StockMovement::SOURCE_BATCH,
                        sourceId: $batch->id,
                        reason: StockMovementReason::make('Unplanned consumption above the allocation — batch #:batch', ['batch' => $batch->id]),
                    );

                    $unplanned[] = [
                        'allocation_id' => (int) $allocation->id,
                        'material_id' => (int) $allocation->material_id,
                        'quantity' => $excessOfAllocation,
                    ];
                }

                // Finalise the location balance against the quantity that actually
                // stands. Deducts only the part not already booked by the operator's
                // own entries, so a batch whose consumption was recorded step by step
                // is not deducted twice. Scrap counts: it physically left the store
                // too — only the leftover being returned above stayed behind.
                $this->consumptionLocation->deduct($allocation, $declared);

                $allocation->update([
                    'status' => MaterialAllocation::STATUS_CONSUMED,
                    'consumed_qty' => $actualConsumed,
                    'consumed_at' => now(),
                    // Snapshot the price so historical cost reports stay stable.
                    'unit_price_snapshot' => $actualConsumed > 0 ? $allocation->material?->unit_price : null,
                    'price_currency_snapshot' => $actualConsumed > 0 ? $allocation->material?->price_currency : null,
                ]);

                $settled++;
            }

            return ['settled' => $settled, 'pending' => $pending, 'unplanned' => $unplanned];
        });
    }

    public function returnForBatch(Batch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $allocations = MaterialAllocation::where('batch_id', $batch->id)
                ->where('status', MaterialAllocation::STATUS_ALLOCATED)
                ->lockForUpdate()
                ->with('material')
                ->get();

            foreach ($allocations as $allocation) {
                if ($allocation->material) {
                    $this->stockMovements->record(
                        $allocation->material,
                        StockMovement::TYPE_RETURN,
                        (float) $allocation->allocated_qty,
                        sourceType: StockMovement::SOURCE_BATCH,
                        sourceId: $allocation->batch_id,
                        reason: StockMovementReason::make('Batch #:batch cancelled — return to stock', ['batch' => $allocation->batch_id]),
                    );
                    $this->releaseReservation($allocation->material, (float) $allocation->allocated_qty);
                }

                // Give back anything already taken off the location: a cancelled batch
                // consumed nothing, so a location that was debited by an operator's
                // entry must be made whole again.
                $this->consumptionLocation->reverse($allocation);

                // Lot tracking: return picked qty back to each lot.
                $this->lotPicking->returnPicksForAllocation($allocation);

                $allocation->update([
                    'status' => MaterialAllocation::STATUS_RETURNED,
                    'returned_qty' => $allocation->allocated_qty,
                ]);
            }
        });
    }

    /**
     * Record actual consumed quantity for a single allocation. Operator calls
     * this from the post-step UI. Optionally records scrap.
     *
     * In the pilot scope (the operation owns the withdrawal) this is not a
     * quantity write but a confirmation document: it names the person, the time,
     * the source and, when one is supplied, an idempotency key. A pilot
     * confirmation without a person is refused — a number nobody signed cannot
     * settle an operation's retrieval.
     *
     * A key already on record makes the call a replay: the same row with the same
     * quantities is answered with what the first call left behind (no second
     * deduction, no re-stamp), while the same key against other quantities or
     * another allocation is a conflict.
     */
    public function recordConsumption(
        MaterialAllocation $allocation,
        float $actualConsumed,
        float $scrap = 0,
        ?string $notes = null,
        ?User $user = null,
        ?string $source = null,
        ?string $key = null,
    ): MaterialAllocation {
        if ($actualConsumed < 0 || $scrap < 0) {
            throw new \InvalidArgumentException('Consumed and scrap quantities must be non-negative.');
        }

        // Checked before the status guard on purpose: a retry that arrives after
        // the batch already settled must still be answered as the same document,
        // not refused because the row has moved on.
        if ($key !== null) {
            $replay = $this->replayForKey($key, $allocation, $actualConsumed, $scrap);
            if ($replay !== null) {
                return $replay;
            }
        }

        if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
            throw new \DomainException('Allocation must be in `allocated` status to record consumption.');
        }

        $workOrder = $allocation->workOrder;

        // The terms of this confirmation, read once from the scope the order
        // carries right now and frozen on the allocation (RQ-PA-265 A7). A
        // correction later reads them from here, so switching the pilot off
        // after the withdrawal settled never rewrites what was signed. An
        // allocation without an order has no pilot scope, so it settles the
        // legacy way — that is the declared policy, not a fallback.
        $settlementMode = $workOrder !== null
            ? app(MaterialSettlementPolicy::class)->modeFor($workOrder, (int) $allocation->material_id)
            : MaterialSettlementPolicy::MODE_LEGACY;

        $pilot = $settlementMode === MaterialSettlementPolicy::MODE_OPERATION;

        if ($pilot && $user === null) {
            throw new \DomainException(__('A pilot consumption needs the operator who declared it.'));
        }

        // Evidence is written whenever the call carries any: always in the pilot
        // scope, and elsewhere as soon as someone (or something) is named.
        $recordEvidence = $pilot || $user !== null || $source !== null;
        $source ??= $recordEvidence ? self::SOURCE_OPERATOR : null;

        try {
            return DB::transaction(function () use ($allocation, $actualConsumed, $scrap, $user, $source, $key, $recordEvidence, $settlementMode) {
                $attributes = [
                    'consumed_qty' => $actualConsumed,
                    'consumption_recorded' => true,
                    'scrap_qty' => $scrap,
                    // Frozen with the confirmation, whatever scope the call
                    // came from and whether or not anyone signed it.
                    'settlement_mode' => $settlementMode,
                    // Snapshot the price so historical cost reports stay stable.
                    'unit_price_snapshot' => $actualConsumed > 0 ? $allocation->material?->unit_price : null,
                    'price_currency_snapshot' => $actualConsumed > 0 ? $allocation->material?->price_currency : null,
                ];

                if ($recordEvidence) {
                    $attributes['consumption_recorded_by_id'] = $user?->id;
                    $attributes['consumption_recorded_at'] = now();
                    $attributes['consumption_source'] = $source;
                }

                if ($key !== null) {
                    $attributes['consumption_key'] = $key;
                }

                $allocation->update($attributes);

                // Take it off the location it came from — consumed plus scrap, since both
                // physically left the store. Same transaction as the quantity itself, so a
                // refused deduction (location short, plant blocks negatives) leaves no
                // consumed_qty claiming material that never moved.
                $this->consumptionLocation->deduct($allocation->fresh(), $actualConsumed + $scrap, $user);

                return $allocation->fresh();
            });
        } catch (QueryException $e) {
            // Two requests carrying the same key raced past the lookup above; the
            // unique index is the real arbiter (Postgres and SQLite both raise a
            // constraint violation, which Laravel surfaces as QueryException).
            // Re-read and answer as a replay or a conflict, never as a DB error.
            if ($key === null) {
                throw $e;
            }

            $replay = $this->replayForKey($key, $allocation, $actualConsumed, $scrap);
            if ($replay !== null) {
                return $replay;
            }

            throw $e;
        }
    }

    /**
     * The allocation already recorded under this key, if the call is a replay of
     * that confirmation.
     *
     * Returns null when the key is unused; the same row with the same quantities
     * is returned unchanged; anything else throws, because a key that would name
     * two different documents cannot be resolved silently.
     */
    private function replayForKey(
        string $key,
        MaterialAllocation $allocation,
        float $actualConsumed,
        float $scrap,
    ): ?MaterialAllocation {
        $existing = MaterialAllocation::query()->where('consumption_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        $sameRow = (int) $existing->getKey() === (int) $allocation->getKey();
        $sameValues = abs((float) $existing->consumed_qty - $actualConsumed) < 1e-9
            && abs((float) $existing->scrap_qty - $scrap) < 1e-9;

        if ($sameRow && $sameValues) {
            return $existing;
        }

        throw new \DomainException(__(
            'Material consumption confirmation conflict (:code): the key :key already names another confirmation.',
            ['code' => 'material_consumption_key_conflict', 'key' => $key],
        ));
    }

    /**
     * Correct a settled pilot consumption (RQ-PA-265 A5).
     *
     * The confirmation stays as it was declared — who, when, from where — and the
     * correction is recorded beside it as a new document: a reviewer, a time, a
     * reason and a reference to what is being corrected. Only the difference is
     * booked, as a compensating movement, so the ledger keeps both halves of what
     * happened instead of losing the first.
     *
     * Four eyes (maker-checker): the person who declared the retrieval cannot
     * approve its correction, whatever their role. Whether the approver may review
     * at all is the `approve work order changes` right, enforced on the route.
     *
     * Only a line settled by its operation has a correction path: the mode frozen
     * on the allocation when the retrieval was confirmed decides it, so switching
     * the pilot off afterwards changes nothing (RQ-PA-265 A7). A legacy line
     * settled with the plan and has no operation confirmation to correct; a line
     * whose terms were never frozen (confirmed before the mode was recorded) has
     * none either. A line still waiting for settlement closes through
     * `consume`/`settle` first — correcting it here would post a compensation
     * against a number that was never confirmed.
     *
     * A key already on record makes the call a replay (same row, same quantities,
     * nothing booked again); the same key against other quantities or another
     * allocation is a conflict, because one key cannot name two documents.
     *
     * @throws MaterialCorrectionException when the line is out of scope, not settled,
     *                                     approved by its own declarant, or the key conflicts
     * @throws \InvalidArgumentException when a quantity is negative or the reason/reference is blank
     */
    public function correctConsumption(
        MaterialAllocation $allocation,
        float $consumed,
        float $scrap,
        string $reason,
        string $reference,
        User $approver,
        ?string $key = null,
    ): MaterialAllocation {
        if ($consumed < 0 || $scrap < 0) {
            throw new \InvalidArgumentException('Corrected consumed and scrap quantities must be non-negative.');
        }

        if (trim($reason) === '' || trim($reference) === '') {
            throw new \InvalidArgumentException('A correction needs a reason and a reference to the original confirmation.');
        }

        $this->assertCorrectable($allocation, $approver);

        // A retry that arrives with the key of a correction already posted is
        // answered with what that call left behind. Same row and same quantities
        // are the same document; anything else is unresolvable in silence.
        if ($key !== null) {
            $replay = $this->replayCorrectionForKey($key, $allocation, $consumed, $scrap);
            if ($replay !== null) {
                return $replay;
            }
        }

        try {
            return DB::transaction(function () use ($allocation, $consumed, $scrap, $reason, $reference, $approver, $key) {
                // Re-read under a lock: two reviewers correcting the same line at
                // once must each measure their difference against the latest
                // committed quantities, not against what the request started with.
                $locked = MaterialAllocation::query()->lockForUpdate()->findOrFail($allocation->getKey());
                $this->assertCorrectable($locked, $approver);

                $previousTotal = round((float) $locked->consumed_qty + (float) $locked->scrap_qty, 4);
                $newTotal = round($consumed + $scrap, 4);
                $delta = round($newTotal - $previousTotal, 4);

                if (abs($delta) >= 0.0001) {
                    if (! $locked->material) {
                        throw new \DomainException('Allocation has no associated material.');
                    }

                    // The correction is a movement of its own: positive quantity
                    // when the store gets material back, negative when more left.
                    $this->stockMovements->record(
                        $locked->material,
                        StockMovement::TYPE_ADJUSTMENT,
                        -$delta,
                        user: $approver,
                        sourceType: StockMovement::SOURCE_BATCH,
                        sourceId: (int) $locked->batch_id,
                        reason: StockMovementReason::make(
                            'Correction of material consumption (:reference): :reason',
                            ['reference' => $reference, 'reason' => $reason],
                        ),
                    );
                }

                // The location is credited/debited by the same difference only —
                // `deduct` compares the total against what it already took, so a
                // downward correction gives the store back what it gave up.
                $this->consumptionLocation->deduct($locked, $newTotal, $approver);

                $attributes = [
                    'consumed_qty' => $consumed,
                    'scrap_qty' => $scrap,
                    // The correction document. The confirmation it corrects
                    // (consumption_recorded_*, consumed_at, status) is not touched:
                    // history is append-only.
                    'correction_recorded_by_id' => $approver->id,
                    'correction_recorded_at' => now(),
                    'correction_reason' => $reason,
                    'correction_reference' => $reference,
                ];

                // A keyless correction never erases the key of an earlier one — the
                // key has to keep pointing at a document, or a late retry of it would
                // look unused and compensate again.
                if ($key !== null) {
                    $attributes['correction_key'] = $key;
                }

                $locked->update($attributes);

                return $locked->fresh();
            });
        } catch (QueryException $e) {
            // Two requests carrying the same key raced past the lookup above; the
            // unique index is the real arbiter. Re-read and answer as a replay or
            // a conflict, never as a DB error.
            if ($key === null) {
                throw $e;
            }

            $replay = $this->replayCorrectionForKey($key, $allocation, $consumed, $scrap);
            if ($replay !== null) {
                return $replay;
            }

            throw $e;
        }
    }

    /**
     * Refuse a correction this line or this approver cannot give.
     *
     * @throws MaterialCorrectionException
     */
    private function assertCorrectable(MaterialAllocation $allocation, User $approver): void
    {
        // The terms are read from the allocation, where the confirmation froze
        // them (RQ-PA-265 A7) — never from the work order. Asking the order
        // would refuse a correction the moment the pilot is switched off, which
        // the rule allows as soon as the withdrawal has settled.
        $settlementMode = $allocation->settlement_mode;

        // A legacy line never has a correction path, whatever its stage: the
        // batch settled with the plan, so there is no operation confirmation to
        // correct (the legacy scope keeps its own tools).
        if ($settlementMode === MaterialSettlementPolicy::MODE_LEGACY) {
            throw new MaterialCorrectionException(
                MaterialCorrectionException::NOT_APPLICABLE,
                __('This allocation is not settled by its operation — there is nothing to correct.'),
            );
        }

        // Still open — nothing was settled, or nothing was frozen yet: the line
        // closes through `consume`/`settle` first. Correcting it here would post
        // a compensation against a number that was never confirmed.
        if ($allocation->status !== MaterialAllocation::STATUS_CONSUMED) {
            throw new MaterialCorrectionException(
                MaterialCorrectionException::REQUIRES_SETTLED,
                __('The settlement has not been posted yet: settle it first, then correct it.'),
            );
        }

        // Settled with no frozen terms: a confirmation from before the mode was
        // recorded. There is no declared scope to hold the correction to.
        if ($settlementMode === null) {
            throw new MaterialCorrectionException(
                MaterialCorrectionException::NOT_APPLICABLE,
                __('This allocation carries no frozen pilot settlement mode (it was confirmed before the mode was recorded) — there is nothing to correct.'),
            );
        }

        if ($approver->id !== null && (int) $allocation->consumption_recorded_by_id === (int) $approver->id) {
            throw new MaterialCorrectionException(
                MaterialCorrectionException::REQUIRES_OTHER_APPROVER,
                __('The person who declared the consumption cannot approve its correction — four eyes are required.'),
            );
        }
    }

    /**
     * The correction already recorded under this key, if the call is a replay of
     * that correction.
     *
     * Returns null when the key is unused; the same row with the same quantities is
     * returned unchanged; anything else throws, because a key that would name two
     * different documents cannot be resolved silently.
     *
     * @throws MaterialCorrectionException
     */
    private function replayCorrectionForKey(
        string $key,
        MaterialAllocation $allocation,
        float $consumed,
        float $scrap,
    ): ?MaterialAllocation {
        $existing = MaterialAllocation::query()->where('correction_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        $sameRow = (int) $existing->getKey() === (int) $allocation->getKey();
        $sameValues = abs((float) $existing->consumed_qty - $consumed) < 1e-9
            && abs((float) $existing->scrap_qty - $scrap) < 1e-9;

        if ($sameRow && $sameValues) {
            return $existing;
        }

        throw new MaterialCorrectionException(
            MaterialCorrectionException::KEY_CONFLICT,
            __('Material correction conflict (:code): the key :key already names another correction.', [
                'code' => MaterialCorrectionException::KEY_CONFLICT,
                'key' => $key,
            ]),
        );
    }

    /**
     * Mid-batch material adjustment (e.g. "operator added 5kg extra").
     * Decrements stock + reserved by the delta and bumps allocated_qty.
     */
    public function adjustAllocation(
        MaterialAllocation $allocation,
        float $deltaQty,
        User $user,
        ?string $reason = null,
    ): MaterialAllocation {
        if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
            throw new \DomainException('Can only adjust allocations in `allocated` status.');
        }
        if ($deltaQty === 0.0) {
            return $allocation;
        }

        $newAllocated = (float) $allocation->allocated_qty + $deltaQty;
        if ($newAllocated < 0) {
            throw new \InvalidArgumentException('Adjustment would make allocated_qty negative.');
        }

        return DB::transaction(function () use ($allocation, $deltaQty, $user, $reason) {
            $material = $allocation->material;

            if (! $material) {
                throw new \DomainException('Allocation has no associated material.');
            }

            $this->stockMovements->record(
                $material,
                StockMovement::TYPE_ADJUSTMENT,
                -$deltaQty,
                user: $user,
                sourceType: StockMovement::SOURCE_BATCH,
                sourceId: $allocation->batch_id,
                reason: $reason ?? StockMovementReason::make('Adjustment on batch #:batch', ['batch' => $allocation->batch_id]),
            );

            if ($deltaQty > 0) {
                $material->increment('reserved_quantity', $deltaQty);
            } else {
                $material->decrement('reserved_quantity', abs($deltaQty));
            }
            \App\Sync\CollectionBroadcaster::flush($material); // increment/decrement bypass model events

            $allocation->update([
                'allocated_qty' => (float) $allocation->allocated_qty + $deltaQty,
                'adjustment_qty' => (float) $allocation->adjustment_qty + $deltaQty,
            ]);

            return $allocation->fresh();
        });
    }

    /**
     * Return a leftover quantity from an in-flight allocation to stock (#99) —
     * e.g. the operator over-issued and hands the surplus back before the batch
     * completes. Books TYPE_RETURN, releases the reservation and restores the
     * picked lots for the returned quantity.
     *
     * Crucially it DECREMENTS allocated_qty by the returned amount so the
     * completion reconciler (consumeForBatch: leftover = allocated − consumed −
     * scrap) never returns the same quantity a second time.
     *
     * @throws \DomainException|\InvalidArgumentException
     */
    public function returnQuantity(
        MaterialAllocation $allocation,
        float $qty,
        User $user,
        ?string $reason = null,
    ): MaterialAllocation {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Return quantity must be positive.');
        }

        return DB::transaction(function () use ($allocation, $qty, $user, $reason) {
            // Lock and re-read inside the transaction so two concurrent returns can't
            // both validate the same returnable quantity and over-return.
            $allocation = MaterialAllocation::query()->lockForUpdate()->findOrFail($allocation->getKey());

            if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
                throw new \DomainException('Can only return material from an `allocated` allocation.');
            }

            $returnable = (float) $allocation->allocated_qty
                - (float) $allocation->consumed_qty
                - (float) $allocation->scrap_qty;
            if ($qty > $returnable + 1e-9) {
                throw new \InvalidArgumentException('Return quantity exceeds the unconsumed allocated quantity.');
            }

            $material = $allocation->material;

            if (! $material) {
                throw new \DomainException('Allocation has no associated material.');
            }

            $this->stockMovements->record(
                $material,
                StockMovement::TYPE_RETURN,
                $qty,
                user: $user,
                sourceType: StockMovement::SOURCE_BATCH,
                sourceId: $allocation->batch_id,
                reason: $reason ?? StockMovementReason::make('Batch #:batch — unused material returned to stock', ['batch' => $allocation->batch_id]),
            );

            $this->releaseReservation($material, $qty);

            // Lot tracking: hand the returned quantity back to the picked lots.
            $this->lotPicking->returnPartialForAllocation($allocation, $qty);

            $allocation->update([
                // Shrink the allocation so consumeForBatch's leftover calc excludes what we just returned.
                'allocated_qty' => (float) $allocation->allocated_qty - $qty,
                'returned_qty' => (float) $allocation->returned_qty + $qty,
            ]);

            return $allocation->fresh();
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     * @param  int|null  $stepId  Drives the stock-movement source (during-items) and batch_step_id.
     * @param  int|null  $attributeStepId  Sets batch_step_id only (start/end-items), leaving the
     *                                     stock-movement source as the batch - keeps genealogy attributable without changing accounting.
     */
    private function allocateMatching(
        Batch $batch,
        User $user,
        \Closure $filter,
        ?int $stepId = null,
        array $picksByMaterial = [],
        ?int $attributeStepId = null,
    ): Collection {
        $bom = $batch->workOrder->process_snapshot['bom'] ?? [];

        if (empty($bom)) {
            return collect();
        }

        $blockNegative = $this->blockNegativeStockEnabled();
        $genealogyStepId = $stepId ?? $attributeStepId;

        return DB::transaction(function () use ($batch, $user, $bom, $filter, $stepId, $genealogyStepId, $picksByMaterial, $blockNegative) {
            $allocations = collect();

            foreach ($bom as $bomItem) {
                if (! $filter($bomItem)) {
                    continue;
                }

                $existing = MaterialAllocation::where('batch_id', $batch->id)
                    ->where('material_id', $bomItem['material_id'] ?? null)
                    ->first();
                if ($existing) {
                    $allocations->push($existing);

                    continue;
                }

                $material = $this->resolveMaterial($bomItem);
                if (! $material) {
                    continue;
                }

                $requiredQty = $this->calculateRequiredQty($bomItem, (float) $batch->target_qty);

                if ($blockNegative && $material->available_quantity < $requiredQty) {
                    throw new InsufficientStockException(
                        $material,
                        $requiredQty,
                        $material->available_quantity,
                    );
                }

                // Stock leaves the warehouse + reservation increases.
                $this->stockMovements->record(
                    $material,
                    StockMovement::TYPE_ALLOCATION,
                    -$requiredQty,
                    user: $user,
                    sourceType: $stepId ? StockMovement::SOURCE_BATCH_STEP : StockMovement::SOURCE_BATCH,
                    sourceId: $stepId ?: $batch->id,
                    reason: $stepId
                        ? StockMovementReason::make('Allocated to batch #:batch (step :step)', ['batch' => $batch->id, 'step' => $stepId])
                        : StockMovementReason::make('Allocated to batch #:batch', ['batch' => $batch->id]),
                );
                $material->increment('reserved_quantity', $requiredQty);
                \App\Sync\CollectionBroadcaster::flush($material); // increment bypasses model events

                $newAllocation = MaterialAllocation::create([
                    'batch_id' => $batch->id,
                    'batch_step_id' => $genealogyStepId,
                    'material_id' => $material->id,
                    'work_order_id' => $batch->work_order_id,
                    'allocated_qty' => $requiredQty,
                    'expected_qty' => $requiredQty,
                    'status' => MaterialAllocation::STATUS_ALLOCATED,
                    'allocated_by' => $user->id,
                    'allocated_at' => now(),
                ]);

                // Lot picking (opt-in via setting). Errors here roll back
                // the surrounding transaction so stock/reserved stay consistent.
                // When the operator supplied an explicit pick for this material
                // (WO-time "suggest + override"), honour it; otherwise auto-pick.
                if ($this->lotPicking->isLotTrackingEnabled()) {
                    $chosen = $picksByMaterial[$material->id] ?? null;
                    if (! empty($chosen)) {
                        $this->lotPicking->pickManualForAllocation($newAllocation, $material, $requiredQty, $chosen);
                    } else {
                        $this->lotPicking->pickForAllocation($newAllocation, $material, $requiredQty);
                    }
                }

                $allocations->push($newAllocation);
            }

            return $allocations;
        });
    }

    /**
     * Record one BatchStepLotConsumption row per picked lot for this allocation.
     * Requires a step to attribute to (batch_step_id is NOT NULL); allocations
     * without a step or without picks are skipped - genealogy stays optional.
     */
    private function writeGenealogy(MaterialAllocation $allocation): void
    {
        if (! $allocation->batch_step_id || $allocation->lotPicks->isEmpty()) {
            return;
        }

        foreach ($allocation->lotPicks as $pick) {
            BatchStepLotConsumption::create([
                'batch_step_id' => $allocation->batch_step_id,
                'material_lot_id' => $pick->material_lot_id,
                'sublot_id' => null, // sublots are phase 2
                'quantity_consumed' => $pick->picked_qty,
                'consumed_at' => now(),
                'recorded_by_id' => null, // system-recorded at batch completion
            ]);
        }
    }

    private function releaseReservation(?Material $material, float $qty): void
    {
        if (! $material || $qty <= 0) {
            return;
        }
        $material->decrement('reserved_quantity', $qty);
        \App\Sync\CollectionBroadcaster::flush($material); // decrement bypasses model events
    }

    private function isStartItem(array $bomItem): bool
    {
        $at = $bomItem['consumed_at'] ?? 'start';

        return $at === 'start';
    }

    private function isDuringItem(array $bomItem): bool
    {
        return ($bomItem['consumed_at'] ?? null) === 'during';
    }

    private function isEndItem(array $bomItem): bool
    {
        return ($bomItem['consumed_at'] ?? null) === 'end';
    }

    private function resolveMaterial(array $bomItem): ?Material
    {
        $query = Material::query()->lockForUpdate();

        if (! empty($bomItem['material_id'])) {
            return $query->find($bomItem['material_id']);
        }

        if (! empty($bomItem['material_code'])) {
            return $query->where('code', $bomItem['material_code'])->first();
        }

        return null;
    }

    /** Resolve a BOM item's material without locking (for read-only previews). */
    private function resolveMaterialReadonly(array $bomItem): ?Material
    {
        if (! empty($bomItem['material_id'])) {
            return Material::find($bomItem['material_id']);
        }
        if (! empty($bomItem['material_code'])) {
            return Material::where('code', $bomItem['material_code'])->first();
        }

        return null;
    }

    private function calculateRequiredQty(array $bomItem, float $targetQty): float
    {
        $baseQty = ($bomItem['quantity_per_unit'] ?? 0) * $targetQty;
        $scrapQty = $baseQty * (($bomItem['scrap_percentage'] ?? 0) / 100);

        return round($baseQty + $scrapQty, 4);
    }

    private function blockNegativeStockEnabled(): bool
    {
        try {
            $row = DB::table('system_settings')->where('key', 'block_negative_stock')->value('value');

            return (bool) json_decode($row ?? 'false', true);
        } catch (\Throwable) {
            return false;
        }
    }
}
