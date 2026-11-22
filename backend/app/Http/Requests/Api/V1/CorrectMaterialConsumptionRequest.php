<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MaterialAllocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A correction of a settled pilot consumption (RQ-PA-265 A5).
 *
 * The quantities are the corrected totals, not a delta — the reviewer says what
 * was actually consumed, and the service books the difference. A correction is a
 * document, so it carries the reason and a reference to the confirmation it
 * corrects; an optional key makes a retried request the same document.
 *
 * Whether the caller may review at all is the `approve work order changes` right
 * on the route; this request only checks that they can see the order.
 */
class CorrectMaterialConsumptionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'reference' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
