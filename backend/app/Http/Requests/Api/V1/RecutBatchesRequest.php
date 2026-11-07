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
                'nullable',
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
}
