<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CorrectPackingQtyRequest extends FormRequest
{
    /**
     * The gate is on the route (`role:Supervisor|Admin`) and in the controller's
     * `authorize('view')`, so a caller without either gets 403 rather than a
     * field-level 422.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Correcting an already closed packing step rewrites what the order is
     * recorded as having produced, so the reason is mandatory and must say
     * something.
     */
    public function rules(): array
    {
        return [
            'produced_qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
