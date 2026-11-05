<?php

namespace Modules\PipelineDashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Modules\PipelineDashboard\Models\NestingStatus;
use Modules\PipelineDashboard\Models\PipelineLog;
use Modules\PipelineDashboard\Services\PipelineAggregator;

class OrderDetailController extends Controller
{
    public function show(Request $request, string $orderNo)
    {
        $wooId = PipelineAggregator::wooId($orderNo);

        $workOrders = WorkOrder::where('order_no', 'like', "WC-{$wooId}-%")
            ->with(['batches.steps'])
            ->get();

        $logs = PipelineLog::where('order_no', $wooId)
            ->orderBy('logged_at', 'desc')
            ->get();

        $nesting = NestingStatus::where('order_no', $wooId)->first();

        return view('pipeline-dashboard::order-detail', compact('workOrders', 'orderNo', 'logs', 'nesting'));
    }
}
