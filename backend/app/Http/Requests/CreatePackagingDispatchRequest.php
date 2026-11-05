<?php

namespace App\Http\Requests;

use App\Models\PackagingBox;
use App\Models\WorkOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreatePackagingDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin.
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'idempotency_key' => ['required', 'uuid', 'max:64'],
            'scanned_boxes' => ['nullable', 'array'],
            'scanned_boxes.*' => ['required'],
            'scanned_pallets' => ['nullable', 'array'],
            'scanned_pallets.*' => ['required'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'qc_override' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $boxes = array_filter((array) $this->input('scanned_boxes', []), fn ($v) => $v !== null && $v !== '');
            $pallets = array_filter((array) $this->input('scanned_pallets', []), fn ($v) => $v !== null && $v !== '');

            if (($boxes === []) === ($pallets === []) && $boxes === []) {
                // A legacy DONE order without cartons gets an actionable 409
                // from the service instead of a validation error.
                $workOrder = WorkOrder::find($this->integer('work_order_id'));
                $legacyWithoutBoxes = $workOrder
                    && $workOrder->status === WorkOrder::STATUS_DONE
                    && ! PackagingBox::where('work_order_id', $workOrder->id)->exists();

                if (! $legacyWithoutBoxes) {
                    $validator->errors()->add(
                        'scanned_boxes',
                        __('Send either scanned boxes or scanned pallets - exactly one.'),
                    );
                }
            } elseif (($boxes === []) === ($pallets === [])) {
                $validator->errors()->add(
                    'scanned_boxes',
                    __('Send either scanned boxes or scanned pallets - exactly one.'),
                );
            }

            if ($pallets !== [] && $this->boolean('qc_override')) {
                $validator->errors()->add(
                    'qc_override',
                    __('qc_override applies only to direct box dispatch.'),
                );
            }
        });
    }
}
