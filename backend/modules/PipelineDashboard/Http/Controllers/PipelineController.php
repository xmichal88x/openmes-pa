<?php

namespace Modules\PipelineDashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\PipelineDashboard\Services\PipelineAggregator;

class PipelineController extends Controller
{
    public function index(PipelineAggregator $aggregator)
    {
        $orders = $aggregator->getActiveOrders();

        return view('pipeline-dashboard::pipeline', compact('orders'));
    }
}
