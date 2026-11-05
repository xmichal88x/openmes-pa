<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BatchStep;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a batch step via the API. Authorization mirrors the view
 * ability on the parent work order; the service enforces the
 * system fan-out permission beyond this baseline access check.
 */
class StartBatchStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        $step = $this->route('batchStep');

        if (! $step instanceof BatchStep) {
            return false;
        }

        $workOrder = $step->batch?->workOrder;

        return $workOrder !== null
            && (bool) $this->user()?->can('view', $workOrder);
    }

    public function rules(): array
    {
        return [
            'confirm_shared_sheet' => ['sometimes', 'boolean'],
            'system_fanout' => ['sometimes', 'boolean'],
        ];
    }
}
