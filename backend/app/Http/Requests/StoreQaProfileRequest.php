<?php

namespace App\Http\Requests;

use App\Models\ProductType;
use App\Models\QaProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQaProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:Admin
    }

    public function rules(): array
    {
        return [
            'product_type_id' => [
                'required',
                'integer',
                Rule::exists('product_types', 'id')->whereNull('deleted_at'),
                Rule::unique('qa_profiles', 'product_type_id'),
            ],
            'product_type_code' => ['nullable', 'string', 'max:50', Rule::unique('qa_profiles', 'product_type_code')],
            'required' => ['boolean'],
            'active' => ['boolean'],
            'checklist_json' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'required' => $this->has('required') ? $this->boolean('required') : true,
            'active' => $this->has('active') ? $this->boolean('active') : true,
        ]);
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('product_type_id')) {
                    return;
                }

                $productType = ProductType::find($this->integer('product_type_id'));

                if ($productType === null) {
                    return;
                }

                if ($this->filled('product_type_code') && $this->input('product_type_code') !== $productType->code) {
                    $validator->errors()->add('product_type_code', __('The code must match the selected product type.'));
                }

                $checklist = $this->input('checklist_json');

                if ($validator->errors()->has('checklist_json') || ($checklist !== null && ! is_array($checklist))) {
                    return;
                }

                foreach (QaProfile::checklistErrors($checklist, $this->boolean('required')) as $message) {
                    $validator->errors()->add('checklist_json', $message);
                }
            },
        ];
    }
}
