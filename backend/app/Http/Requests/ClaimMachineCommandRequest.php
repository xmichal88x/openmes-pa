<?php

namespace App\Http\Requests;

use App\Models\MachineCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClaimMachineCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('process', MachineCommand::class) runs in the controller.
    }

    public function rules(): array
    {
        return [
            'workstation_codes' => ['required', 'array', 'min:1', 'max:50'],
            'workstation_codes.*' => ['required', 'string', 'max:64', 'distinct'],
            'lease_seconds' => ['nullable', 'integer', 'between:30,3600'],
            'types' => ['nullable', 'array'],
            'types.*' => ['required', 'string', 'distinct', Rule::in(MachineCommand::TYPES)],
        ];
    }

    /**
     * Workstation codes are case-sensitive, so only surrounding whitespace is
     * trimmed (the service dedupes again before querying).
     */
    protected function prepareForValidation(): void
    {
        $codes = $this->input('workstation_codes');

        if (! is_array($codes)) {
            return;
        }

        $this->merge([
            'workstation_codes' => array_map(
                fn ($code) => is_string($code) ? trim($code) : $code,
                $codes,
            ),
        ]);
    }
}
