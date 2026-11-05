<?php

namespace App\Http\Controllers\Web\Packaging;

use App\Http\Controllers\Concerns\PackagingLineAccess;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\Packaging\LabelGenerator;
use App\Support\DownloadName;
use App\Support\WorkOrderPackingList;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class WorkOrderPackingListController extends Controller
{
    use PackagingLineAccess;

    public function __construct(private readonly LabelGenerator $labels) {}

    public function pdf(Request $request, WorkOrder $workOrder)
    {
        $this->assertWorkOrderLineAccess($request, $workOrder);

        abort_unless(
            in_array($workOrder->status, [WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_DONE], true),
            404,
        );

        $list = WorkOrderPackingList::data($workOrder);

        abort_unless($list['has_packing'], 404);

        return Pdf::loadView('packaging.pdf.work-order-packing-list', [
            'list' => $list,
            'qrPng' => $this->labels->qrPng($workOrder->order_no, 200),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4')->stream('packing-list-'.DownloadName::safe($workOrder->order_no).'.pdf');
    }
}
