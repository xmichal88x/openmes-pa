<?php

namespace App\Services\Packaging;

use App\Enums\PalletStatus;
use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operator intent to ship a work order (R07 §4.3).
 *
 * The service only writes the MES outbox row: it snapshots the work order and
 * the boxes/pallets, validates that nothing is left behind, and never talks to
 * Dolibarr. PA consumes pending rows later and reports the result through
 * PATCH (lease/token guarded) or external-close (idempotent).
 */
class PackagingDispatchService
{
    /** How far the weighed cartons may drift from the sum of their weights. */
    private function weightTolerancePct(): float
    {
        return (float) config('packaging.dispatch_weight_tolerance_pct', 2.0);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(WorkOrder $workOrder, array $data, ?User $user = null): PackagingDispatch
    {
        return DB::transaction(function () use ($workOrder, $data, $user) {
            $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            $key = (string) $data['idempotency_key'];
            $existing = PackagingDispatch::where('idempotency_key', $key)->first();

            if ($existing) {
                if ((int) $existing->work_order_id !== (int) $workOrder->id) {
                    abort(409, __('This idempotency key belongs to another work order.'));
                }

                return $existing;
            }

            $this->assertNoActiveDispatch($workOrder);

            $boxRefs = array_values(array_filter((array) ($data['scanned_boxes'] ?? []), fn ($v) => $v !== null && $v !== ''));
            $palletRefs = array_values(array_filter((array) ($data['scanned_pallets'] ?? []), fn ($v) => $v !== null && $v !== ''));

            if (
                $boxRefs === []
                && $palletRefs === []
                && $workOrder->status === WorkOrder::STATUS_DONE
                && ! PackagingBox::where('work_order_id', $workOrder->id)->exists()
            ) {
                abort(409, __('This work order has no cartons - create cartons in the packing station before dispatch.'));
            }

            if (($boxRefs === []) === ($palletRefs === [])) {
                abort(422, __('Send either scanned boxes or scanned pallets - exactly one.'));
            }

            $reason = trim((string) ($data['reason'] ?? ''));
            $reason = $reason !== '' ? $reason : null;

            $boxes = $boxRefs !== []
                ? $this->resolveBoxes($workOrder, $boxRefs)
                : $this->resolvePallets($workOrder, $palletRefs);

            $pallets = collect();

            if ($boxRefs !== []) {
                $this->assertBoxesCoverTheWorkOrder($workOrder, $boxes);

                // The pallet is the quality-control unit (R07-16/R11). Shipping
                // cartons without a pallet bypasses the pallet QC gate, so it
                // needs an explicit override and an auditable reason. The reason
                // is stored in packaging_dispatches.reason; no schema change.
                $override = filter_var($data['qc_override'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! $override || $reason === null) {
                    abort(409, __('Direct box dispatch bypasses pallet quality control - ship quality-passed pallets, or set qc_override with a reason.'));
                }
            } else {
                $pallets = $boxes;
                $boxes = $this->assertPalletsCoverTheWorkOrder($workOrder, $pallets);
            }

            [$expectedWeight, $weight, $deviation] = $this->weigh($boxes, $data['weight_kg'] ?? null);

            $boxManifest = $this->boxManifest($boxes);
            $palletManifest = $this->palletManifest($pallets, $boxes);

            $dispatch = PackagingDispatch::create([
                'work_order_id' => $workOrder->id,
                'order_no' => $workOrder->order_no,
                'customer_order_no' => $workOrder->customer_order_no,
                'status' => PackagingDispatch::STATUS_PENDING,
                'source' => PackagingDispatch::SOURCE_OPERATOR,
                'idempotency_key' => $key,
                'scanned_boxes' => $boxRefs !== [] ? $boxes->pluck('box_id')->values()->all() : [],
                'scanned_pallets' => $palletRefs !== [] ? $pallets->pluck('pallet_no')->values()->all() : [],
                'box_manifest' => $boxManifest,
                'pallet_manifest' => $palletManifest,
                'weight_kg' => $weight,
                'expected_weight_kg' => $expectedWeight,
                'weight_deviation_pct' => $deviation,
                'carrier' => $data['carrier'] ?? null,
                'reason' => $reason,
                'requested_by_id' => $user?->id,
                'docs_stale' => true,
            ]);

            return $dispatch->fresh();
        });
    }

    public function cancel(PackagingDispatch $dispatch, ?User $user = null, ?string $reason = null): PackagingDispatch
    {
        return DB::transaction(function () use ($dispatch, $user, $reason) {
            $dispatch = PackagingDispatch::whereKey($dispatch->getKey())->lockForUpdate()->firstOrFail();

            if ($dispatch->status !== PackagingDispatch::STATUS_PENDING) {
                abort(409, __('Only a pending dispatch can be cancelled.'));
            }

            $dispatch->forceFill([
                'status' => PackagingDispatch::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_id' => $user?->id,
                'cancel_reason' => $reason,
                'resolved_at' => now(),
                'lock_token' => null,
                'locked_until' => null,
            ])->save();

            return $dispatch->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        DB::transaction(fn () => $this->reclaimStale());

        $query = PackagingDispatch::with('workOrder:id,order_no,line_id');

        if (! empty($filters['work_order_id'])) {
            $query->where('work_order_id', (int) $filters['work_order_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['order_no'])) {
            $query->where('order_no', 'like', '%'.$filters['order_no'].'%');
        }
        if (! empty($filters['line_ids'])) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('line_id', (array) $filters['line_ids']));
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 50), 100));

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Lease-guarded transition used by the PA consumer.
     *
     * @param  array<string, mixed>  $data
     */
    public function patch(PackagingDispatch $dispatch, array $data, ?User $user = null): PackagingDispatch
    {
        return DB::transaction(function () use ($dispatch, $data) {
            $dispatch = PackagingDispatch::whereKey($dispatch->getKey())->lockForUpdate()->firstOrFail();

            $target = (string) $data['status'];
            $token = isset($data['lock_token']) ? (string) $data['lock_token'] : null;
            $lease = max(30, min((int) ($data['lease_seconds'] ?? 300), 3600));
            $now = now();

            if ($dispatch->status === PackagingDispatch::STATUS_CANCELLED) {
                abort(409, __('A cancelled dispatch cannot change status.'));
            }

            if ($target === PackagingDispatch::STATUS_PROCESSING) {
                if (! $token) {
                    abort(422, __('lock_token is required to claim a dispatch.'));
                }

                if ($dispatch->status === PackagingDispatch::STATUS_PROCESSING
                    && ! $this->leaseAllows($dispatch, $token, $now)) {
                    abort(409, __('The dispatch is leased by another worker.'));
                }

                if (! in_array($dispatch->status, PackagingDispatch::ACTIVE_STATUSES, true)) {
                    abort(409, __('Only a pending dispatch can be claimed.'));
                }

                $dispatch->forceFill([
                    'status' => PackagingDispatch::STATUS_PROCESSING,
                    'lock_token' => $token,
                    'locked_until' => $now->copy()->addSeconds($lease),
                    'attempt_count' => (int) $dispatch->attempt_count + ($dispatch->status === PackagingDispatch::STATUS_PENDING ? 1 : 0),
                    'next_attempt_at' => null,
                ])->save();

                return $dispatch->fresh();
            }

            if ($target === PackagingDispatch::STATUS_PENDING) {
                if (! $this->leaseAllows($dispatch, $token, $now)) {
                    abort(409, __('The dispatch is leased by another worker.'));
                }

                $dispatch->forceFill([
                    'status' => PackagingDispatch::STATUS_PENDING,
                    'lock_token' => null,
                    'locked_until' => null,
                    'next_attempt_at' => $data['next_attempt_at'] ?? null,
                ])->save();

                return $dispatch->fresh();
            }

            if (! in_array($target, [
                PackagingDispatch::STATUS_DONE,
                PackagingDispatch::STATUS_FAILED,
                PackagingDispatch::STATUS_SKIPPED,
            ], true)) {
                abort(422, __('Unsupported dispatch transition to :status.', ['status' => $target]));
            }

            if ($dispatch->status !== PackagingDispatch::STATUS_PROCESSING) {
                abort(409, __('Only a processing dispatch can be resolved.'));
            }

            if (! $token || ! $dispatch->lock_token || ! hash_equals($dispatch->lock_token, $token)) {
                abort(409, __('The lease token does not match.'));
            }

            if ($dispatch->locked_until && $dispatch->locked_until->isPast()) {
                abort(409, __('The lease has expired.'));
            }

            $fill = [
                'status' => $target,
                'lock_token' => null,
                'locked_until' => null,
            ];

            foreach (['shipment_id', 'shipment_status', 'last_error', 'packing_list_path', 'boxes_hash'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fill[$field] = $data[$field];
                }
            }

            if (array_key_exists('docs_generated_at', $data)) {
                $fill['docs_generated_at'] = $data['docs_generated_at'];
            }

            if (array_key_exists('shipment_id', $data)
                || array_key_exists('packing_list_path', $data)
                || array_key_exists('boxes_hash', $data)
                || array_key_exists('docs_generated_at', $data)) {
                $fill['docs_stale'] = false;
            }

            if (in_array($target, [PackagingDispatch::STATUS_DONE, PackagingDispatch::STATUS_SKIPPED], true)) {
                $fill['resolved_at'] = now();
            }

            $dispatch->forceFill($fill)->save();

            if (in_array($target, [PackagingDispatch::STATUS_DONE, PackagingDispatch::STATUS_SKIPPED], true)) {
                $this->markPalletsShipped($dispatch, array_values((array) $dispatch->scanned_pallets));
            }

            return $dispatch->fresh();
        });
    }

    /**
     * A shipment closed outside MES (PA/ERP) is recorded once per idempotency
     * key. Repeated calls return the same row; no Dolibarr call is made.
     *
     * @param  array<string, mixed>  $data
     */
    public function externalClose(array $data, ?User $user = null): PackagingDispatch
    {
        return DB::transaction(function () use ($data, $user) {
            $workOrder = WorkOrder::whereKey((int) $data['work_order_id'])->lockForUpdate()->firstOrFail();
            $key = (string) $data['idempotency_key'];

            $existing = PackagingDispatch::where('idempotency_key', $key)->first();

            if ($existing) {
                if ((int) $existing->work_order_id !== (int) $workOrder->id) {
                    abort(409, __('This idempotency key belongs to another work order.'));
                }

                return $existing;
            }

            $this->assertNoActiveDispatch($workOrder);

            $dispatch = PackagingDispatch::create([
                'work_order_id' => $workOrder->id,
                'order_no' => $workOrder->order_no,
                'customer_order_no' => $workOrder->customer_order_no,
                'status' => PackagingDispatch::STATUS_DONE,
                'source' => PackagingDispatch::SOURCE_EXTERNAL,
                'idempotency_key' => $key,
                'scanned_boxes' => [],
                'scanned_pallets' => [],
                'box_manifest' => [],
                'pallet_manifest' => [],
                'shipment_id' => $data['shipment_id'] ?? null,
                'shipment_status' => $data['shipment_status'] ?? null,
                'reason' => $data['reason'] ?? null,
                'requested_by_id' => $user?->id,
                'docs_stale' => false,
                'docs_generated_at' => null,
                'resolved_at' => now(),
            ]);

            $this->markPalletsShipped($dispatch);

            return $dispatch->fresh();
        });
    }

    // ── Validation helpers ──────────────────────────────────────────────────

    /**
     * Pallets leave with the shipment (R07-15): once a dispatch is resolved as
     * done/skipped, the pallets it covered become shipped. An external close
     * carries no pallet list, so it ships every closed, quality-passed pallet
     * of the work order. Already shipped pallets are left alone (no shipped_at
     * rewrite) and pallets without passed quality are skipped - the model hook
     * is the final gate.
     *
     * @param  array<int, string>|null  $palletNos  null = every closed pallet of the order
     */
    private function markPalletsShipped(PackagingDispatch $dispatch, ?array $palletNos = null): void
    {
        if ($palletNos === []) {
            return;
        }

        $pallets = Pallet::where('work_order_id', $dispatch->work_order_id)
            ->when(
                $palletNos === null,
                fn ($query) => $query->where('status', PalletStatus::Closed),
                fn ($query) => $query->whereIn('pallet_no', $palletNos),
            )
            ->lockForUpdate()
            ->get();

        foreach ($pallets as $pallet) {
            if ($pallet->status === PalletStatus::Shipped) {
                continue;
            }

            if ($pallet->quality_status !== Pallet::QUALITY_PASS) {
                continue;
            }

            $pallet->forceFill(['status' => PalletStatus::Shipped])->save();
        }
    }

    private function assertNoActiveDispatch(WorkOrder $workOrder): void
    {
        $active = PackagingDispatch::where('work_order_id', $workOrder->id)
            ->whereIn('status', PackagingDispatch::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->exists();

        if ($active) {
            abort(409, __('An active packaging dispatch already exists for this work order.'));
        }
    }

    /**
     * @param  array<int, mixed>  $refs
     * @return Collection<int, PackagingBox>
     */
    private function resolveBoxes(WorkOrder $workOrder, array $refs): Collection
    {
        $all = PackagingBox::where('work_order_id', $workOrder->id)->lockForUpdate()->get();

        $boxes = $this->matchRefs($all, $refs, fn (PackagingBox $box) => [(string) $box->id, $box->box_id], 'box');
        $this->refreshPartCounts($boxes);

        return $boxes;
    }

    /**
     * @param  array<int, mixed>  $refs
     * @return Collection<int, Pallet>
     */
    private function resolvePallets(WorkOrder $workOrder, array $refs): Collection
    {
        $all = Pallet::where('work_order_id', $workOrder->id)->lockForUpdate()->get();

        return $this->matchRefs($all, $refs, fn (Pallet $pallet) => [(string) $pallet->id, $pallet->pallet_no], 'pallet');
    }

    /**
     * @param  Collection<int, mixed>  $candidates
     * @param  array<int, mixed>  $refs
     * @param  callable(mixed): array<int, string>  $keys
     * @return Collection<int, mixed>
     */
    private function matchRefs(Collection $candidates, array $refs, callable $keys, string $kind): Collection
    {
        $wanted = collect($refs)->map(fn ($ref) => (string) $ref)->unique()->values();

        $matched = $candidates->filter(function ($candidate) use ($wanted, $keys) {
            foreach ($keys($candidate) as $key) {
                if ($wanted->contains($key)) {
                    return true;
                }
            }

            return false;
        })->unique(fn ($candidate) => $candidate->getKey())->values();

        if ($matched->count() !== $wanted->count()) {
            abort(422, __('Unknown :kind in the dispatch payload, or it belongs to another work order.', ['kind' => $kind]));
        }

        return $matched;
    }

    /**
     * @param  Collection<int, PackagingBox>  $boxes
     */
    private function assertBoxesCoverTheWorkOrder(WorkOrder $workOrder, Collection $boxes): void
    {
        $selectedIds = $boxes->pluck('id')->all();

        foreach ($boxes as $box) {
            $this->assertBoxShippable($box);

            if ($box->pallet_id !== null) {
                abort(422, __('Box :id is on a pallet - send the pallets instead.', ['id' => $box->box_id]));
            }
        }

        $missing = PackagingBox::where('work_order_id', $workOrder->id)
            ->where('status', PackagingBox::STATUS_CLOSED)
            ->whereNotIn('id', $selectedIds)
            ->pluck('box_id');

        if ($missing->isNotEmpty()) {
            abort(422, __('Every closed box must be in the dispatch: :boxes', ['boxes' => $missing->implode(', ')]));
        }

        $openWithParts = PackagingBox::where('work_order_id', $workOrder->id)
            ->where('status', PackagingBox::STATUS_OPEN)
            ->whereHas('eans')
            ->value('box_id');

        if ($openWithParts) {
            abort(422, __('Box :id is still open - close it before dispatch.', ['id' => $openWithParts]));
        }

        $this->assertAllPartsBoxed($workOrder, $selectedIds);
    }

    /**
     * Pallet dispatch has two disjoint shapes (R07-15): boxes-mode pallets are
     * expanded to their closed boxes, loose pallets carry the scanned parts
     * directly. One dispatch never mixes the two.
     *
     * @param  Collection<int, Pallet>  $pallets
     * @return Collection<int, PackagingBox>
     */
    private function assertPalletsCoverTheWorkOrder(WorkOrder $workOrder, Collection $pallets): Collection
    {
        if ($pallets->pluck('kind')->unique()->count() > 1) {
            abort(422, __('Do not mix boxes and loose pallets in one dispatch.'));
        }

        return $pallets->every(fn (Pallet $pallet) => $pallet->isBoxesMode())
            ? $this->assertBoxPalletsCoverTheWorkOrder($workOrder, $pallets)
            : $this->assertLoosePalletsCoverTheWorkOrder($workOrder, $pallets);
    }

    /**
     * @param  Collection<int, Pallet>  $pallets
     * @return Collection<int, PackagingBox>
     */
    private function assertBoxPalletsCoverTheWorkOrder(WorkOrder $workOrder, Collection $pallets): Collection
    {
        $palletIds = $pallets->pluck('id')->all();

        foreach ($pallets as $pallet) {
            if (! $pallet->isBoxesMode()) {
                abort(422, __('Pallet :no is loose mode - send boxes instead.', ['no' => $pallet->pallet_no]));
            }

            if ($pallet->status !== PalletStatus::Closed) {
                abort(422, __('Pallet :no must be closed before dispatch.', ['no' => $pallet->pallet_no]));
            }

            if ($pallet->quality_status !== Pallet::QUALITY_PASS) {
                abort(422, __('Pallet :no has not passed quality control (current: :status).', [
                    'no' => $pallet->pallet_no,
                    'status' => $pallet->quality_status,
                ]));
            }

            if ($pallet->scanLogs()->whereNull('packaging_box_id')->exists()) {
                abort(422, __('Pallet :no holds loose parts and cannot be mixed with boxes.', ['no' => $pallet->pallet_no]));
            }
        }

        $allBoxes = PackagingBox::where('work_order_id', $workOrder->id)->get();
        $this->refreshPartCounts($allBoxes);

        foreach ($allBoxes as $box) {
            $this->assertBoxShippable($box);

            if ($box->pallet_id === null) {
                abort(422, __('Box :id is not on a dispatched pallet.', ['id' => $box->box_id]));
            }

            if (! in_array($box->pallet_id, $palletIds, true)) {
                abort(422, __('Box :id is on a pallet that is not in the dispatch.', ['id' => $box->box_id]));
            }
        }

        $openWithParts = $allBoxes->first(fn (PackagingBox $box) => $box->isOpen() && $box->eans()->exists());

        if ($openWithParts) {
            abort(422, __('Box :id is still open - close it before dispatch.', ['id' => $openWithParts->box_id]));
        }

        $this->assertAllPartsBoxed($workOrder, $allBoxes->pluck('id')->all());

        $boxService = app(PackagingBoxService::class);
        foreach ($pallets as $pallet) {
            $boxService->syncPalletQty($pallet);
        }

        return $allBoxes->sortBy('box_no')->values();
    }

    /**
     * A loose pallet carries every scanned part of the order directly on it
     * (packaging_scan_logs.pallet_id, no packaging_box_id). It must be closed,
     * quality-passed and its qty must match the scan logs - the qty is counted
     * per scan, never re-derived from boxes.
     *
     * @param  Collection<int, Pallet>  $pallets
     * @return Collection<int, PackagingBox>
     */
    private function assertLoosePalletsCoverTheWorkOrder(WorkOrder $workOrder, Collection $pallets): Collection
    {
        $palletIds = $pallets->pluck('id')->all();

        foreach ($pallets as $pallet) {
            if ($pallet->isBoxesMode()) {
                abort(422, __('Pallet :no is boxes mode - send its closed boxes instead.', ['no' => $pallet->pallet_no]));
            }

            if ($pallet->status !== PalletStatus::Closed) {
                abort(422, __('Pallet :no must be closed before dispatch.', ['no' => $pallet->pallet_no]));
            }

            if ($pallet->quality_status !== Pallet::QUALITY_PASS) {
                abort(422, __('Pallet :no has not passed quality control (current: :status).', [
                    'no' => $pallet->pallet_no,
                    'status' => $pallet->quality_status,
                ]));
            }

            if ((int) $pallet->qty < 1) {
                abort(422, __('Pallet :no is empty - add parts before dispatch.', ['no' => $pallet->pallet_no]));
            }

            if (PackagingBox::where('pallet_id', $pallet->id)->exists()) {
                abort(422, __('Pallet :no holds boxes - send its closed boxes instead.', ['no' => $pallet->pallet_no]));
            }

            $logged = PackagingScanLog::where('pallet_id', $pallet->id)
                ->whereNull('packaging_box_id')
                ->count();

            if ($logged !== (int) $pallet->qty) {
                abort(422, __('Pallet :no qty (:qty) does not match its scan logs (:logged).', [
                    'no' => $pallet->pallet_no,
                    'qty' => (int) $pallet->qty,
                    'logged' => $logged,
                ]));
            }
        }

        $scanned = WorkOrderEan::where('work_order_id', $workOrder->id)
            ->where('state', WorkOrderEan::STATE_SCANNED)
            ->pluck('ean');

        $covered = PackagingScanLog::where('work_order_id', $workOrder->id)
            ->whereIn('pallet_id', $palletIds)
            ->whereNull('packaging_box_id')
            ->pluck('ean')
            ->unique();

        $missing = $scanned->diff($covered);
        if ($missing->isNotEmpty()) {
            abort(422, __('Every scanned part must be on the dispatched loose pallet (:count outside).', ['count' => $missing->count()]));
        }

        // A stale loose scan log must not ship a part that already sits in a box.
        if (WorkOrderEan::where('work_order_id', $workOrder->id)
            ->whereIn('ean', $covered)
            ->whereNotNull('packaging_box_id')
            ->exists()) {
            abort(422, __('This work order has boxed parts - dispatch the boxes instead.'));
        }

        if ($scanned->count() !== $covered->count() || (int) $pallets->sum('qty') !== $scanned->count()) {
            abort(422, __('The loose pallet qty does not match the scanned parts of this work order.'));
        }

        return collect();
    }

    private function assertBoxShippable(PackagingBox $box): void
    {
        if ($box->status !== PackagingBox::STATUS_CLOSED) {
            abort(422, __('Box :id is not closed.', ['id' => $box->box_id]));
        }

        if ((int) $box->part_count < 1) {
            abort(422, __('Box :id is empty.', ['id' => $box->box_id]));
        }

        if ($box->isVoid()) {
            abort(422, __('Box :id is void.', ['id' => $box->box_id]));
        }
    }

    /**
     * Every non-void part label of the order must sit in one of the boxes being
     * dispatched. An expected (not yet scanned) label therefore blocks dispatch.
     *
     * @param  array<int, int>  $boxIds
     */
    private function assertAllPartsBoxed(WorkOrder $workOrder, array $boxIds): void
    {
        $outside = WorkOrderEan::where('work_order_id', $workOrder->id)
            ->where('state', '!=', WorkOrderEan::STATE_VOID)
            ->where(function ($query) use ($boxIds) {
                $query->whereNull('packaging_box_id')
                    ->orWhereNotIn('packaging_box_id', $boxIds);
            })
            ->count();

        if ($outside > 0) {
            abort(422, __('Every part must be in a closed box of this dispatch (:count outside).', ['count' => $outside]));
        }
    }

    /**
     * @param  Collection<int, PackagingBox>  $boxes
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    private function weigh(Collection $boxes, mixed $weighed): array
    {
        $expected = $boxes->isNotEmpty() && $boxes->every(fn (PackagingBox $box) => $box->weight_kg !== null)
            ? round($boxes->sum(fn (PackagingBox $box) => (float) $box->weight_kg), 3)
            : null;

        $weight = $weighed === null || $weighed === '' ? null : round((float) $weighed, 3);

        if ($expected === null) {
            return [null, $weight, null];
        }

        if ($weight === null) {
            abort(422, __('Weigh the dispatch: expected :expected kg.', ['expected' => $expected]));
        }

        $deviation = $expected > 0 ? round((($weight - $expected) / $expected) * 100, 3) : null;

        if ($deviation !== null && abs($deviation) > $this->weightTolerancePct()) {
            abort(422, __('Dispatch weight :weight kg is outside :expected kg ± :tolerance%.', [
                'weight' => $weight,
                'expected' => $expected,
                'tolerance' => $this->weightTolerancePct(),
            ]));
        }

        return [$expected, $weight, $deviation];
    }

    /**
     * @param  Collection<int, PackagingBox>  $boxes
     * @return array<int, array<string, mixed>>
     */
    private function boxManifest(Collection $boxes): array
    {
        return $boxes->map(fn (PackagingBox $box) => [
            'id' => $box->id,
            'box_id' => $box->box_id,
            'box_no' => $box->box_no,
            'part_count' => (int) $box->part_count,
            'weight_kg' => $box->weight_kg !== null ? (string) $box->weight_kg : null,
            'pallet_id' => $box->pallet_id,
            'pallet_no' => $box->pallet?->pallet_no,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Pallet>  $pallets
     * @param  Collection<int, PackagingBox>  $boxes
     * @return array<int, array<string, mixed>>
     */
    private function palletManifest(Collection $pallets, Collection $boxes): array
    {
        return $pallets->map(fn (Pallet $pallet) => [
            'id' => $pallet->id,
            'pallet_no' => $pallet->pallet_no,
            'kind' => $pallet->kind,
            'qty' => (int) $pallet->qty,
            'quality_status' => $pallet->quality_status,
            'boxes' => $boxes->where('pallet_id', $pallet->id)
                ->map(fn (PackagingBox $box) => [
                    'box_id' => $box->box_id,
                    'part_count' => (int) $box->part_count,
                    'weight_kg' => $box->weight_kg !== null ? (string) $box->weight_kg : null,
                ])->values()->all(),
        ])->values()->all();
    }

    /**
     * The cached part_count is a convenience, not the source of truth: restore
     * it from the label rows before deciding whether a box may ship.
     *
     * @param  Collection<int, PackagingBox>  $boxes
     */
    private function refreshPartCounts(Collection $boxes): void
    {
        foreach ($boxes as $box) {
            $count = (int) $box->eans()->count();

            if ((int) $box->part_count !== $count) {
                $box->forceFill(['part_count' => $count])->saveQuietly();
                $box->part_count = $count;
            }
        }
    }

    private function reclaimStale(): void
    {
        PackagingDispatch::where('status', PackagingDispatch::STATUS_PROCESSING)
            ->whereNotNull('locked_until')
            ->where('locked_until', '<', now())
            ->update([
                'status' => PackagingDispatch::STATUS_PENDING,
                'lock_token' => null,
                'locked_until' => null,
            ]);
    }

    private function leaseAllows(PackagingDispatch $dispatch, ?string $token, \DateTimeInterface $now): bool
    {
        if ($dispatch->status !== PackagingDispatch::STATUS_PROCESSING) {
            return false;
        }

        if ($dispatch->locked_until && $dispatch->locked_until->lt($now)) {
            return true;
        }

        return $token !== null && $dispatch->lock_token !== null && hash_equals($dispatch->lock_token, $token);
    }
}
