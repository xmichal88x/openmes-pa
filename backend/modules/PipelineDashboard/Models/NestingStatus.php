<?php

namespace Modules\PipelineDashboard\Models;

use Illuminate\Database\Eloquent\Model;

class NestingStatus extends Model
{
    protected $table = 'nesting_statuses';
    public $timestamps = false;

    protected $fillable = [
        'order_no',
        'status',
        'details',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
