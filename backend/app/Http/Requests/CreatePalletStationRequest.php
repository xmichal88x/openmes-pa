<?php

namespace App\Http\Requests;

use App\Models\Pallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePalletStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is behind auth + role:Operator|Supervisor|Admin middleware.
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'exists:work_orders,id'],
            // Optional - the controller auto-links when the WO has one batch and
            // re-checks that a chosen batch belongs to the work order.
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'location' => ['nullable', 'string', 'max:100'],
            // loose (default): pieces scanned straight onto the pallet.
            // boxes: closed packaging boxes only (R07-15).
            'kind' => ['nullable', Rule::in(Pallet::KINDS)],
            // Produced quantity this pallet accounts for; drives the milestone
            // backflush when enabled. Falls back to the batch quantity if omitted,
            // so an explicit value must be strictly positive (0 != "omitted").
            'produced_qty' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
