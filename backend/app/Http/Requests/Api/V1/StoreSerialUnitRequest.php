<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Batch;
use App\Models\SerialUnit;
use App\Support\UnitSerialisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Registering a serialised unit through the API; identifier rules come from the plant's settings. */
class StoreSerialUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth:sanctum + role middleware.
    }

    protected function prepareForValidation(): void
    {
        $settings = app(UnitSerialisation::class);
        $this->merge([
            'serial_no' => $settings->normalize($this->input('serial_no')),
            'psn' => $settings->normalize($this->input('psn')),
        ]);
    }

    public function rules(): array
    {
        $settings = app(UnitSerialisation::class);

        return [
            // A unit may be registered on its process serial alone; the SN follows.
            'serial_no' => ['required_without:psn', 'nullable', ...array_slice($settings->serialRules(), 1)],
            'psn' => array_merge($settings->psnRules(), $settings->get('unit_psn_unique') ? [Rule::unique('serial_units', 'psn')] : []),
            'work_order_id' => ['nullable', 'integer', Rule::exists('work_orders', 'id')->whereNull('deleted_at')],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')->whereNull('deleted_at')],
            // A unit registered through the API is only ever born in production;
            // completion and shipping are outcomes of work, not input.
            'status' => ['nullable', Rule::in([SerialUnit::STATUS_IN_PRODUCTION])],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $batchId = $this->input('batch_id');
                $workOrderId = $this->input('work_order_id');

                if ($batchId === null || $workOrderId === null) {
                    return;
                }

                if ($validator->errors()->has('batch_id') || $validator->errors()->has('work_order_id')) {
                    return;
                }

                if (! Batch::query()->whereKey($batchId)->where('work_order_id', $workOrderId)->exists()) {
                    $validator->errors()->add('batch_id', __('Selected batch does not belong to this work order.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => __('New units may only start in production.'),
        ];
    }
}
