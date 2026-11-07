<?php

namespace App\Http\Requests;

use App\Models\ProductType;
use App\Models\QaProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQaProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:Admin
    }

    public function rules(): array
    {
        $profile = $this->route('qaProfile');
        $profileId = $profile instanceof QaProfile ? $profile->id : $profile;

        return [
            'product_type_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('product_types', 'id')->whereNull('deleted_at'),
                Rule::unique('qa_profiles', 'product_type_id')->ignore($profileId),
            ],
            'product_type_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('qa_profiles', 'product_type_code')->ignore($profileId),
            ],
            'required' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'checklist_json' => ['sometimes', 'nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['required', 'active'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var QaProfile|null $profile */
                $profile = $this->route('qaProfile');

                $productTypeId = $this->input('product_type_id', $profile?->product_type_id);

                if (! $validator->errors()->has('product_type_id') && $productTypeId !== null) {
                    $productType = ProductType::find($productTypeId);

                    if ($productType !== null && $this->filled('product_type_code') && $this->input('product_type_code') !== $productType->code) {
                        $validator->errors()->add('product_type_code', __('The code must match the selected product type.'));
                    }
                }

                if ($validator->errors()->has('checklist_json')) {
                    return;
                }

                $checklist = $this->has('checklist_json')
                    ? $this->input('checklist_json')
                    : ($profile?->checklist_json ?? []);

                if ($checklist !== null && ! is_array($checklist)) {
                    return;
                }

                $required = $this->has('required')
                    ? $this->boolean('required')
                    : (bool) ($profile?->required ?? true);

                foreach (QaProfile::checklistErrors($checklist, $required) as $message) {
                    $validator->errors()->add('checklist_json', $message);
                }
            },
        ];
    }
}
