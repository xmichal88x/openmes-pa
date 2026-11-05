<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A carton packed on the operator station (R07 §4.2).
 *
 * Boxes are created ad hoc while packing. Parts are linked through
 * work_order_eans.packaging_box_id; part_count only mirrors that count for
 * lists and documents. No soft deletes — removing an empty open box is a hard
 * delete, while the audit trail lives in packaging_scan_logs/dispatches.
 */
class PackagingBox extends Model
{
    use HasTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_VOID,
    ];

    protected $table = 'packaging_boxes';

    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'pallet_id',
        'box_no',
        'box_id',
        'part_count',
        'status',
        'closed_at',
        'label_printed_at',
        'voided_at',
        'weight_kg',
    ];

    protected function casts(): array
    {
        return [
            'box_no' => 'integer',
            'part_count' => 'integer',
            'closed_at' => 'datetime',
            'label_printed_at' => 'datetime',
            'voided_at' => 'datetime',
            'weight_kg' => 'decimal:3',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    public function eans(): HasMany
    {
        return $this->hasMany(WorkOrderEan::class, 'packaging_box_id');
    }

    public function scanLogs(): HasMany
    {
        return $this->hasMany(PackagingScanLog::class, 'packaging_box_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    public function scopeVoid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VOID);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }
}
