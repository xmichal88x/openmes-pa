<?php

namespace App\Http\Requests;

use App\Models\MachineCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteMachineCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('process', MachineCommand::class) runs in the controller.
    }

    public function rules(): array
    {
        return [
            'lock_token' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', Rule::in([
                MachineCommand::STATUS_DONE,
                MachineCommand::STATUS_FAILED,
                MachineCommand::STATUS_PENDING,
            ])],
            'outcome' => [
                'nullable', 'string', Rule::in(MachineCommand::OUTCOMES),
                'required_if:status,'.MachineCommand::STATUS_DONE,
            ],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:2000', 'required_if:status,'.MachineCommand::STATUS_FAILED],
            'error_class' => [
                'nullable', 'string', Rule::in(MachineCommand::ERROR_CLASSES),
                // A requeue is only ever a transient fault; mirrors the service guard.
                Rule::when(
                    $this->input('status') === MachineCommand::STATUS_PENDING,
                    ['required', Rule::in([MachineCommand::ERROR_TRANSIENT])],
                ),
            ],
            'delivery_id' => ['nullable', 'uuid'],
        ];
    }
}
