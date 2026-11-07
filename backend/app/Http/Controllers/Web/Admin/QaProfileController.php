<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductType;
use App\Models\QaProfile;
use Inertia\Inertia;

class QaProfileController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/qa-profiles/Index', [
            'profiles' => QaProfile::with('productType:id,code,name')
                ->orderBy('product_type_code')
                ->get()
                ->map(fn (QaProfile $profile) => [
                    'id' => $profile->id,
                    'product_type_id' => $profile->product_type_id,
                    'product_type_code' => $profile->product_type_code,
                    'product_type' => $profile->productType ? [
                        'id' => $profile->productType->id,
                        'code' => $profile->productType->code,
                        'name' => $profile->productType->name,
                    ] : null,
                    'required' => $profile->required,
                    'checklist_json' => $profile->checklist_json,
                    'active' => $profile->active,
                    'version' => $profile->version,
                ]),
            'productTypes' => ProductType::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }
}
