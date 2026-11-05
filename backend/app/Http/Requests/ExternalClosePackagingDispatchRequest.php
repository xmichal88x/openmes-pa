<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExternalClosePackagingDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin.
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'idempotency_key' => ['required', 'uuid', 'max:64'],
            'shipment_id' => ['nullable', 'integer', 'min:1'],
            'shipment_status' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
