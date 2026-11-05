<?php

namespace App\Http\Controllers\Web\Packaging;

use App\Http\Controllers\Concerns\PackagingLineAccess;
use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Services\Packaging\LabelGenerator;
use App\Support\DownloadName;
use App\Support\PalletPackingList;
use App\Support\WorkOrderPackingList;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** The packing list that travels with a pallet: what is in every carton, by serial number. */
class PackingListController extends Controller
{
    use PackagingLineAccess;

    public function __construct(private readonly LabelGenerator $labels) {}

    public function pallet(Pallet $pallet)
    {
        $list = PalletPackingList::data($pallet);

        return Pdf::loadView('packaging.pdf.packing-list', [
            'list' => $list,
            'qrPng' => $this->labels->qrPng($pallet->pallet_no, 200),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4')->stream('packing-list-'.DownloadName::safe($pallet->pallet_no).'.pdf');
    }

    /**
     * R07 packing list for a pallet built from packaging boxes or loose scanned
     * parts (boxes/loose hierarchy). A pallet with nothing on it has no list.
     */
    public function packaging(Request $request, Pallet $pallet)
    {
        $this->assertWorkOrderLineAccess($request, $pallet->workOrder);

        $list = WorkOrderPackingList::palletData($pallet);

        abort_unless($list['has_packing'], 404);

        return Pdf::loadView('packaging.pdf.pallet-packing-list', [
            'list' => $list,
            'qrPng' => $this->labels->qrPng($pallet->pallet_no, 200),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4')->stream('packing-list-'.DownloadName::safe($pallet->pallet_no).'.pdf');
    }
}
