<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Services\Material\MaterialSettlementPolicy;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAllocation extends Model
{
    use Auditable;
    use HasFactory;
    use HasTenant;

    const STATUS_ALLOCATED = 'allocated';

    const STATUS_CONSUMED = 'consumed';

    const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'batch_id',
        'batch_step_id',
        'material_id',
        'work_order_id',
        // The location this allocation is consumed from, frozen on first deduction.
        'consumption_warehouse_id',
        'allocated_qty',
        'expected_qty',
        'returned_qty',
        'consumed_qty',
        'consumption_recorded',
        // Evidence of the confirmation: who, when, from where, and the key that
        // makes a retried request the same document (RQ-PA-265 A3).
        'consumption_recorded_by_id',
        'consumption_recorded_at',
        'consumption_source',
        'consumption_key',
        // The settlement terms this confirmation was signed under, frozen at
        // declaration time so a later scope change never rewrites them
        // (RQ-PA-265 A7): `operation` or `legacy`, null when never confirmed.
        'settlement_mode',
        // Correction evidence, written by the correction path (RQ-PA-265 A5).
        'correction_recorded_by_id',
        'correction_recorded_at',
        'correction_reason',
        'correction_reference',
        // Idempotency of the correction itself: a retried request is the same
        // document, never a second compensation.
        'correction_key',
        // How much of it has already been taken off that location's balance.
        'location_deducted_qty',
        'adjustment_qty',
        'scrap_qty',
        'status',
        'allocated_by',
        'allocated_at',
        'consumed_at',
        'unit_price_snapshot',
        'price_currency_snapshot',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'allocated_qty' => 'decimal:4',
            'expected_qty' => 'decimal:4',
            'returned_qty' => 'decimal:4',
            'consumed_qty' => 'decimal:4',
            'consumption_recorded' => 'boolean',
            'consumption_recorded_at' => 'datetime',
            'correction_recorded_at' => 'datetime',
            'location_deducted_qty' => 'decimal:4',
            'adjustment_qty' => 'decimal:4',
            'scrap_qty' => 'decimal:4',
            'allocated_at' => 'datetime',
            'consumed_at' => 'datetime',
            'unit_price_snapshot' => 'decimal:4',
        ];
    }

    /**
     * Difference between what was actually consumed and what was expected.
     * Positive = used more than BOM said. Useful for scrap analysis.
     */
    public function getVarianceQtyAttribute(): float
    {
        return (float) $this->consumed_qty - (float) $this->expected_qty;
    }

    /**
     * Where this allocation stands in the settlement queue: `pending` while a
     * retrieval or an operation is still missing, `settled` once closed.
     */
    public function getSettlementStateAttribute(): string
    {
        return app(MaterialSettlementPolicy::class)->state($this);
    }

    /** Why the allocation waits for settlement, or null when it does not. */
    public function getSettlementPendingReasonAttribute(): ?string
    {
        return app(MaterialSettlementPolicy::class)->pendingReason($this);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** The operation that answers for this allocation's withdrawal. */
    public function batchStep(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /** The person who declared the consumption (pilot: the operation's operator). */
    public function consumptionRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumption_recorded_by_id');
    }

    /** The person who corrected a settled consumption, if anyone did. */
    public function correctionRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'correction_recorded_by_id');
    }

    /** The location this allocation's consumption is booked against. */
    public function consumptionWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'consumption_warehouse_id');
    }

    public function lotPicks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AllocationLotPick::class, 'material_allocation_id');
    }
}
