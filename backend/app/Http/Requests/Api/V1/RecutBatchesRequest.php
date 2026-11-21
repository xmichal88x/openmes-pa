<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecutBatchesRequest extends FormRequest
{
    /**
     * Authorize before validation so a caller without `createBatch` gets 403
     * rather than a field-level 422.
     */
    public function authorize(): bool
    {
        $workOrder = $this->route('workOrder');

        return $workOrder instanceof WorkOrder
            && $this->user()?->can('createBatch', $workOrder) === true;
    }

    public function rules(): array
    {
        $workOrder = $this->route('workOrder');

        return [
            'target_qty' => ['required', 'numeric', 'min:0.01'],
            'replaces_batch_id' => [
                'required',
                'integer',
                Rule::exists('batches', 'id')->where(
                    fn ($query) => $query
                        ->where('work_order_id', $workOrder?->id)
                        ->whereNull('deleted_at'),
                ),
            ],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * A replacement without the batch it replaces is the RC-32 defect itself:
     * nothing then links the new batch to a scrapped original.
     */
    public function messages(): array
    {
        return [
            'replaces_batch_id.required' => __('recut_replaces_batch_required: a replacement batch must name the batch it replaces (replaces_batch_id).'),
        ];
    }
}
