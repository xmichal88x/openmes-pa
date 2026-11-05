<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClosePackagingBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin.
    }

    public function rules(): array
    {
        return [
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ];
    }
}
