<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PalletQualityDecision extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id',
        'pallet_id',
        'status',
        'reason',
        'decided_by_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
