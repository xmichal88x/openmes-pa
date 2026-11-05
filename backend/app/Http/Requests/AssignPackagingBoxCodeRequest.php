<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPackagingBoxCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin.
    }

    public function rules(): array
    {
        return [
            'ean' => ['nullable', 'string', 'max:100', 'required_without:work_order_ean_id'],
            'work_order_ean_id' => ['nullable', 'integer', 'exists:work_order_eans,id', 'required_without:ean'],
        ];
    }
}
