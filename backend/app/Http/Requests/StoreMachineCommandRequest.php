<?php

namespace App\Http\Requests;

use App\Models\BatchStep;
use App\Models\MachineCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreMachineCommandRequest extends FormRequest
{
    /**
     * Authorize before validation so an unauthorized caller never learns
     * whether the referenced batch step exists. A missing/unknown type is left
     * to the validator so it still answers 422.
     */
    public function authorize(): bool
    {
        $type = $this->input('type');

        if (! is_string($type) || ! in_array($type, MachineCommand::TYPES, true)) {
            return true;
        }

        return Gate::allows('create', [MachineCommand::class, $type]);
    }

    public function rules(): array
    {
        return [
            'batch_step_id' => ['required', 'integer', 'exists:batch_steps,id'],
            'type' => ['required', 'string', Rule::in(MachineCommand::TYPES)],
            'reason' => [
                'nullable', 'string', 'max:1000',
                'required_if:type,'.MachineCommand::TYPE_MANUAL_M30,
                Rule::when(
                    $this->input('type') === MachineCommand::TYPE_MANUAL_M30,
                    ['min:5'],
                ),
            ],
            'payload' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'uuid'],
            'work_order_id' => ['nullable', 'integer', 'exists:work_orders,id'],
            'force' => [
                'nullable', 'boolean',
                // Only a truthy force is destructive; force=false stays valid
                // for load_nc/generate_labels (the gateway simply ignores it).
                Rule::prohibitedIf(
                    fn () => $this->input('type') !== MachineCommand::TYPE_MANUAL_M30
                        && $this->boolean('force'),
                ),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        // The web form posts to /batch-step/{batchStep}/machine-commands and
        // does not need to echo the id in a hidden field. The stepped route is
        // authoritative; an explicit, different id is rejected by the
        // controller so a stale form cannot target another step.
        $routeStep = $this->route('batchStep');

        if ($routeStep instanceof BatchStep && blank($this->input('batch_step_id'))) {
            $this->merge(['batch_step_id' => $routeStep->getKey()]);
        }

        if (blank($this->input('idempotency_key'))) {
            $this->merge(['idempotency_key' => (string) Str::uuid()]);
        }
    }
}
