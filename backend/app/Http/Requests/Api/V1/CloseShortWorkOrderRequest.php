<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CloseShortWorkOrderRequest extends FormRequest
{
    /**
     * The gate is on the route (`role:Supervisor|Admin`) and in the controller's
     * `authorize('update')`, so a caller without either gets 403 rather than a
     * field-level 422.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Closing an order short of its plan is a documented decision: the reason is
     * the only explanation the shortage will ever carry, so it is mandatory and
     * must say something.
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
