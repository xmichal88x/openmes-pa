<?php

namespace App\Http\Requests;

use App\Models\Pallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPalletQualityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + permission:dispatch packing.
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Pallet::QUALITY_PASS, Pallet::QUALITY_FAIL])],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
