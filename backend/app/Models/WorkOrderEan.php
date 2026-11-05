<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderEan extends Model
{
    use SoftDeletesWithAudit;

    public const CODE_TYPE_EAN = 'ean';

    public const CODE_TYPE_PART_LABEL = 'part_label';

    public const STATE_EXPECTED = 'expected';

    public const STATE_SCANNED = 'scanned';

    public const STATE_VOID = 'void';

    public const CODE_TYPES = [
        self::CODE_TYPE_EAN,
        self::CODE_TYPE_PART_LABEL,
    ];

    public const STATES = [
        self::STATE_EXPECTED,
        self::STATE_SCANNED,
        self::STATE_VOID,
    ];

    protected $table = 'work_order_eans';

    protected $fillable = [
        'work_order_id',
        'ean',
        'code_type',
        'label_batch_id',
        'part_seq',
        'state',
        'packaging_box_id',
        'part_name',
        'material',
        'width_mm',
        'height_mm',
        'thickness_mm',
        'sheet_name',
    ];

    protected $casts = [
        'part_seq' => 'integer',
        'packaging_box_id' => 'integer',
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'thickness_mm' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function packagingBox(): BelongsTo
    {
        return $this->belongsTo(PackagingBox::class, 'packaging_box_id');
    }

    public function scopeExpected(Builder $query): Builder
    {
        return $query->where('state', self::STATE_EXPECTED);
    }

    public function scopeScanned(Builder $query): Builder
    {
        return $query->where('state', self::STATE_SCANNED);
    }

    public function scopePartLabels(Builder $query): Builder
    {
        return $query->where('code_type', self::CODE_TYPE_PART_LABEL);
    }
}
