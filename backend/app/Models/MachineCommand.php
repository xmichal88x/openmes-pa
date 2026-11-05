<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Machine command outbox entry (P1a).
 *
 * MES writes the intent; the machine gateway claims pending rows, executes
 * them against the machine and reports the result back with the lease token.
 * Append-only: cancellation changes status/timestamps instead of deleting.
 */
class MachineCommand extends Model
{
    use HasTenant;

    public const TYPE_LOAD_NC = 'load_nc';

    public const TYPE_MANUAL_M30 = 'manual_m30';

    public const TYPE_GENERATE_LABELS = 'generate_labels';

    public const TYPES = [
        self::TYPE_LOAD_NC,
        self::TYPE_MANUAL_M30,
        self::TYPE_GENERATE_LABELS,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Covered by the partial unique indexes. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
    ];

    /** Immutable once reached. */
    public const TERMINAL_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const OUTCOME_OK = 'ok';

    public const OUTCOME_ALREADY_LOADED = 'already_loaded';

    public const OUTCOME_ALREADY_EXISTS = 'already_exists';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOMES = [
        self::OUTCOME_OK,
        self::OUTCOME_ALREADY_LOADED,
        self::OUTCOME_ALREADY_EXISTS,
        self::OUTCOME_SKIPPED,
        self::OUTCOME_DUPLICATE,
    ];

    public const ERROR_TRANSIENT = 'transient';

    public const ERROR_PERMANENT = 'permanent';

    public const ERROR_CONFLICT = 'conflict';

    public const ERROR_GUARD = 'guard';

    public const ERROR_POST_EFFECT = 'post_effect';

    public const ERROR_CLASSES = [
        self::ERROR_TRANSIENT,
        self::ERROR_PERMANENT,
        self::ERROR_CONFLICT,
        self::ERROR_GUARD,
        self::ERROR_POST_EFFECT,
    ];

    protected $table = 'machine_commands';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempt_count' => 0,
    ];

    /**
     * Only the intent is mass assignable. Execution state (status, outcome,
     * result, lease, delivery id, cancellation stamps) is written by the
     * service through forceFill() so a stray request payload cannot forge it.
     */
    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'batch_step_id',
        'line_id',
        'workstation_id',
        'workstation_code',
        'step_name',
        'step_kind',
        'type',
        'idempotency_key',
        'requested_by_id',
        'reason',
        'payload',
    ];

    /** The lease token is only returned once, to the worker that claimed it. */
    protected $hidden = [
        'lock_token',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'attempt_count' => 'integer',
            'locked_until' => 'datetime',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function batchStep(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
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

    /**
     * @param  string|array<int, string>  $code
     */
    public function scopeForWorkstation(Builder $query, string|array $code): Builder
    {
        return $query->whereIn('workstation_code', (array) $code);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
