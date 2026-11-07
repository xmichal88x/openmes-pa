<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Models\SerialUnit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReworkController extends Controller
{
    public function index(Request $request): Response
    {
        $units = SerialUnit::where('status', SerialUnit::STATUS_BLOCKED)
            ->with(['workOrder.productType'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (SerialUnit $unit) => [
                'id' => $unit->id,
                'serial_no' => $unit->serial_no,
                'psn' => $unit->psn,
                'product_type_code' => $unit->workOrder?->productType?->code,
                'order_no' => $unit->workOrder?->order_no,
                'hold_reason' => $unit->extra_data['hold']['reason']
                    ?? $unit->extra_data['hold']['reason_code']
                    ?? null,
                'updated_at' => $unit->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('operator/rework/Index', [
            'units' => $units,
            'canUnblock' => $request->user()->hasAnyRole(['Supervisor', 'Admin']),
        ]);
    }
}
