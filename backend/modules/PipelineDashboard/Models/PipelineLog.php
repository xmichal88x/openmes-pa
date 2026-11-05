<?php

namespace Modules\PipelineDashboard\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineLog extends Model
{
    protected $table = 'pipeline_logs';
    public $timestamps = false;

    protected $fillable = [
        'order_no',
        'stage',
        'details',
        'source',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'logged_at' => 'datetime',
        ];
    }
}
