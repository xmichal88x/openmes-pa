<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQaProfileRequest;
use App\Http\Requests\UpdateQaProfileRequest;
use App\Models\ProductType;
use App\Models\QaProfile;
use Illuminate\Http\JsonResponse;

/**
 * QA checklist profiles — one per MES product type (not per SKU). The
 * checklist shape and its validation live on the model, so this controller
 * only normalises before persisting and bumps the version when the checklist
 * actually changes.
 */
class QaProfileController extends Controller
{
    private function present(QaProfile $profile): array
    {
        return [
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
        ];
    }

    public function index(): JsonResponse
    {
        $profiles = QaProfile::with('productType:id,code,name')
            ->orderBy('product_type_code')
            ->get()
            ->map(fn (QaProfile $profile) => $this->present($profile));

        return response()->json(['data' => $profiles]);
    }

    public function store(StoreQaProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $productType = ProductType::findOrFail($data['product_type_id']);

        $data['product_type_code'] = $data['product_type_code'] ?? $productType->code;
        $data['checklist_json'] = QaProfile::normaliseChecklist(
            $data['checklist_json'] ?? null,
            (bool) ($data['required'] ?? true),
        );
        $data['version'] = 1;

        $profile = QaProfile::create($data);

        return response()->json(['data' => $this->present($profile->load('productType:id,code,name'))], 201);
    }

    public function show(string $productType): JsonResponse
    {
        $profile = QaProfile::with('productType:id,code,name')
            ->where(fn ($query) => $query
                ->where('product_type_id', $productType)
                ->orWhere('product_type_code', $productType))
            ->firstOrFail();

        return response()->json(['data' => $this->present($profile)]);
    }

    public function update(UpdateQaProfileRequest $request, QaProfile $qaProfile): JsonResponse
    {
        $data = $request->validated();
        $productType = ProductType::findOrFail($data['product_type_id'] ?? $qaProfile->product_type_id);

        $data['product_type_code'] = $data['product_type_code'] ?? $productType->code;

        $checklist = QaProfile::normaliseChecklist(
            array_key_exists('checklist_json', $data) ? $data['checklist_json'] : $qaProfile->checklist_json,
            (bool) ($data['required'] ?? $qaProfile->required),
        );
        $data['checklist_json'] = $checklist;

        if (($qaProfile->checklist_json ?? []) != $checklist) {
            $data['version'] = $qaProfile->version + 1;
        }

        $qaProfile->update($data);

        return response()->json(['data' => $this->present($qaProfile->fresh(['productType:id,code,name']))]);
    }

    public function destroy(QaProfile $qaProfile): JsonResponse
    {
        $qaProfile->delete();

        return response()->json(null, 204);
    }
}
