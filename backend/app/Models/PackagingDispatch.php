<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch outbox entry and result record (R07 §4.3).
 *
 * Append-only: cancellation changes status/timestamps instead of deleting the
 * row. PA consumes pending rows and reports the Dolibarr result back.
 */
class PackagingDispatch extends Model
{
    use HasTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses covered by the PostgreSQL partial unique index. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
    ];

    public const SOURCE_OPERATOR = 'operator';

    public const SOURCE_EXTERNAL = 'external';

    public const SOURCES = [
        self::SOURCE_OPERATOR,
        self::SOURCE_EXTERNAL,
    ];

    protected $table = 'packaging_dispatches';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'source' => self::SOURCE_OPERATOR,
        'attempt_count' => 0,
        'docs_stale' => true,
        'scanned_boxes' => '[]',
        'scanned_pallets' => '[]',
        'box_manifest' => '[]',
        'pallet_manifest' => '[]',
    ];

    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'order_no',
        'customer_order_no',
        'status',
        'source',
        'idempotency_key',
        'scanned_boxes',
        'scanned_pallets',
        'box_manifest',
        'pallet_manifest',
        'weight_kg',
        'expected_weight_kg',
        'weight_deviation_pct',
        'carrier',
        'reason',
        'requested_by_id',
        'attempt_count',
        'lock_token',
        'locked_until',
        'next_attempt_at',
        'shipment_id',
        'shipment_status',
        'last_error',
        'packing_list_path',
        'boxes_hash',
        'docs_generated_at',
        'docs_stale',
        'cancelled_at',
        'cancelled_by_id',
        'cancel_reason',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_boxes' => 'array',
            'scanned_pallets' => 'array',
            'box_manifest' => 'array',
            'pallet_manifest' => 'array',
            'weight_kg' => 'decimal:3',
            'expected_weight_kg' => 'decimal:3',
            'weight_deviation_pct' => 'decimal:3',
            'attempt_count' => 'integer',
            'locked_until' => 'datetime',
            'next_attempt_at' => 'datetime',
            'docs_generated_at' => 'datetime',
            'docs_stale' => 'boolean',
            'cancelled_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // PostgreSQL enforces "one pending/processing dispatch per work order"
        // with a partial unique index. SQLite has no such index in this
        // migration, so mirror the rule here. A concurrent writer still needs
        // the consumer/service to take the work-order lock (R07 §4.3); this is
        // the model-level safety net, not a replacement for that lock.
        static::saving(function (self $dispatch): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                return;
            }

            if (! in_array($dispatch->status, self::ACTIVE_STATUSES, true)) {
                return;
            }

            $active = static::withoutGlobalScopes()
                ->where('work_order_id', $dispatch->work_order_id)
                ->active();

            if ($dispatch->exists) {
                $active->whereKeyNot($dispatch->getKey());
            }

            if ($active->exists()) {
                throw new \DomainException(__(
                    'An active packaging dispatch already exists for this work order.',
                ));
            }
        });
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
