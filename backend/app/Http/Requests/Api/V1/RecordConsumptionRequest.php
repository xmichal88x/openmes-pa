<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MaterialAllocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Declare the actual (partial) consumed quantity for a work-order material
 * allocation (#99). Authorized against the allocation's work order, matching the
 * batch-step completion request.
 */
class RecordConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $allocation = $this->route('allocation');

        // A soft-deleted batch hides the relationship, so guard it before deref.
        return $allocation instanceof MaterialAllocation
            && $allocation->batch?->workOrder !== null
            && (bool) $this->user()?->can('view', $allocation->batch->workOrder);
    }

    public function rules(): array
    {
        return [
            'consumed_qty' => ['required', 'numeric', 'min:0'],
            'scrap_qty' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Optional: a retried request carrying the same key is the same
            // confirmation, not a second booking (RQ-PA-265 A3).
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
