<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MaterialAllocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An explicit request to settle a pilot allocation (RQ-PA-265 A3).
 *
 * It carries no input: the quantities were declared by the consuming operation,
 * and this request only asks for them to be posted. Whether there is anything to
 * settle is answered by MaterialSettlementPolicy, not by the caller.
 */
class SettleMaterialAllocationRequest extends FormRequest
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
        return [];
    }
}
