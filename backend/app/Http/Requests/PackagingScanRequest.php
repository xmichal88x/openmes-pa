<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackagingScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin middleware.
    }

    public function rules(): array
    {
        return [
            'ean' => 'required|string|max:100',
            'work_order_id' => 'nullable|integer|exists:work_orders,id',
            'pallet_id' => 'nullable|integer|exists:pallets,id',
            'box_id' => 'nullable',
        ];
    }
}
