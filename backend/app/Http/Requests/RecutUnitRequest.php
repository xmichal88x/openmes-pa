<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Re-cutting a serialised unit after a failed rework - final, so the reason is mandatory. */
class RecutUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin middleware.
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
