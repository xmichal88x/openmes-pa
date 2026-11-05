<?php

namespace App\Http\Requests;

use App\Models\PackagingDispatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePackagingDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin.
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                PackagingDispatch::STATUS_PENDING,
                PackagingDispatch::STATUS_PROCESSING,
                PackagingDispatch::STATUS_DONE,
                PackagingDispatch::STATUS_FAILED,
                PackagingDispatch::STATUS_SKIPPED,
            ])],
            'lock_token' => ['required', 'string', 'max:64'],
            'lease_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'shipment_id' => ['nullable', 'integer', 'min:1'],
            'shipment_status' => ['nullable', 'string', 'max:20'],
            'last_error' => ['nullable', 'string', 'max:5000'],
            'packing_list_path' => ['nullable', 'string', 'max:255'],
            'boxes_hash' => ['nullable', 'string', 'max:64'],
            'docs_generated_at' => ['nullable', 'date'],
            'next_attempt_at' => ['nullable', 'date'],
        ];
    }
}
