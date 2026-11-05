<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPackagingBoxPalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin.
    }

    public function rules(): array
    {
        return [
            'pallet_id' => ['required', 'integer', 'exists:pallets,id'],
        ];
    }
}
