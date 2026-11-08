<?php

namespace App\Http\Requests\Api\V1\Quality;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Incremental cursor query for the re-cut candidate feed (RQ-PA-214 P2).
 *
 * The endpoint is read-only and lives under `auth:sanctum`; validation is
 * fail-closed so a malformed cursor or page size is a 422, never a silent
 * default that would skip or re-deliver events.
 */
class RecutCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
