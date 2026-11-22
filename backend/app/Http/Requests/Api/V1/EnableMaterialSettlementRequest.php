<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The components an administrator wants settled by their operation (RQ-PA-265).
 *
 * Only the shape is checked here — a non-empty list of existing materials. What
 * makes a component settleable (its step, its unit, its tracking, the order
 * being still untouched) is the routing's answer, given by
 * {@see \App\Services\Material\MaterialSettlementScope::candidates()}.
 */
class EnableMaterialSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route requires the Admin role and the controller checks that the
        // caller may view this order; nothing else to decide here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'materials' => ['required', 'array', 'min:1'],
            'materials.*' => ['integer', 'exists:materials,id'],
        ];
    }
}
