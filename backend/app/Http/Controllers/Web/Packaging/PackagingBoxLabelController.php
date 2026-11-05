<?php

namespace App\Http\Controllers\Web\Packaging;

use App\Http\Controllers\Concerns\PackagingLineAccess;
use App\Http\Controllers\Controller;
use App\Models\LabelTemplate;
use App\Models\PackagingBox;
use App\Services\Packaging\LabelGenerator;
use App\Support\DownloadName;
use Illuminate\Http\Request;

class PackagingBoxLabelController extends Controller
{
    use PackagingLineAccess;

    public function __construct(private readonly LabelGenerator $generator) {}

    public function pdf(Request $request, PackagingBox $box)
    {
        $this->authorizeBox($request, $box);

        return $this->generator
            ->pdfForBoxes(collect([$box]), $this->template($request))
            ->stream('label-box-'.DownloadName::safe($box->box_id).'.pdf');
    }

    public function zpl(Request $request, PackagingBox $box)
    {
        $this->authorizeBox($request, $box);

        return response(
            $this->generator->zplForBoxes(collect([$box]), $this->template($request)),
            200,
            [
                'Content-Type' => 'application/zpl',
                'Content-Disposition' => 'attachment; filename=label-box-'.DownloadName::safe($box->box_id).'.zpl',
            ],
        );
    }

    private function authorizeBox(Request $request, PackagingBox $box): void
    {
        $box->loadMissing('workOrder:id,order_no,line_id');

        if (! $box->workOrder) {
            abort(404, __('Work order not found'));
        }

        $this->assertWorkOrderLineAccess($request, $box->workOrder);
    }

    private function template(Request $request): LabelTemplate
    {
        $chosen = $request->integer('template') ? LabelTemplate::find($request->integer('template')) : null;

        if ($chosen && $chosen->type === LabelTemplate::TYPE_BOX) {
            return $chosen;
        }

        return LabelTemplate::defaultFor(LabelTemplate::TYPE_BOX) ?? new LabelTemplate([
            'name' => 'Packing box',
            'type' => LabelTemplate::TYPE_BOX,
            'size' => '100x50',
            'fields_config' => LabelTemplate::defaultFieldsFor(LabelTemplate::TYPE_BOX),
            'barcode_format' => 'code128',
            'is_active' => true,
        ]);
    }
}
