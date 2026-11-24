<?php

namespace App\Http\Requests;

use App\Support\CurrencyRegistry;
use App\Support\ModuleRegistry;
use App\Support\TimezoneRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin-only system settings (Settings -> System).
 *
 * One ruleset for a form that spans several tabs: sections the user did not
 * touch simply are not submitted, which is why most fields are `nullable` and
 * why the controller writes each group only when it is present.
 */
class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already behind `auth` + `role:Admin`; repeated here so the
        // ruleset cannot be reused from an ungated route by accident.
        return $this->user()?->hasRole('Admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // "eur" is a typo, not a different currency.
        if (is_string($this->input('default_currency'))) {
            $this->merge(['default_currency' => CurrencyRegistry::normalise($this->input('default_currency'))]);
        }
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                if (! is_string($this->input('production_flow_mode'))) {
                    return;
                }
                $sources = app(\App\Services\Machine\MachineCountingCompatibility::class)->transitionBlockers($this->input('production_flow_mode'));
                if ($sources) {
                    $validator->errors()->add('production_flow_mode', __('Resolve these requirements before changing production flow: :sources', ['sources' => implode(', ', $sources)]));
                }
            },
            // The hold can only be switched on when the level it compares a
            // demand against exists for every material the active recipes
            // consume. Otherwise the first batch parks on an unknown balance and
            // the operator reads a shortage nobody can confirm (F1-2/F1-3).
            function (\Illuminate\Validation\Validator $validator) {
                if (! filter_var($this->input('hold_on_material_shortage'), FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                $coverage = app(\App\Services\Material\MaterialBalanceCoverage::class);
                $undeclared = $coverage->undeclaredInActiveRecipes();

                if ($undeclared === []) {
                    return;
                }

                $validator->errors()->add('hold_on_material_shortage', __(
                    'The shortage hold cannot be switched on: some materials in the active recipes have no declared balance — :items. Declare the balance from the ERP or as an opening declaration first; do not switch the hold on over an unknown balance.',
                    ['items' => $coverage->describe($undeclared)],
                ));
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'production_period' => 'required|in:none,weekly,monthly',
            'allow_overproduction' => 'nullable|boolean',
            'block_negative_stock' => 'sometimes|boolean',
            'telemetry_enabled' => 'sometimes|boolean',
            'force_sequential_steps' => 'nullable|boolean',
            'workstation_routing_enabled' => 'nullable|boolean',
            // Retired switch (RQ-PA-265). Declared so an attempt to switch it
            // on is rejected out loud instead of being silently ignored; the
            // setting no longer has any effect.
            'backflush_on_pallet_creation' => [
                'nullable',
                'boolean',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $fail(__('Automatic material booking from pallets is disabled; set this to false. Raw material is settled once by the operation that consumes it.'));
                    }
                },
            ],
            'hold_on_material_shortage' => 'nullable|boolean',
            'pallet_stock_documents' => ['nullable', Rule::in(\App\Services\Warehouse\PalletStockDocumentService::MODES)],
            'lot_tracking_enabled' => 'nullable|boolean',
            'lot_picking_strategy' => 'nullable|in:fefo,fifo,lifo,manual',
            'workflow_mode' => 'required|in:status,board_status',
            'pin_login_enabled' => 'nullable|boolean',
            // Single source of truth — the language switcher's configured locales.
            'language' => ['nullable', Rule::in(array_keys(config('app.available_locales', [])))],
            // Plant timezone. Persisted by TimezoneRegistry, not through the
            // controller's JSON map — it stores the identifier raw.
            'app_timezone' => ['nullable', 'string', Rule::in(TimezoneRegistry::identifiers())],
            'schedule_view_mode' => 'required|in:weekly,daily,monthly',
            'schedule_shifts_per_day' => 'required|integer|in:1,2,3,4',
            'schedule_horizon_weeks' => 'required|integer|min:1|max:52',
            'schedule_show_weekends' => 'nullable|boolean',
            'realtime_mode' => 'required|in:polling,off',
            'production_tracking_mode' => 'required|in:per_operation,cumulative,hybrid',
            'production_flow_mode' => 'nullable|in:whole_batch,transfer',
            'cors_allowed_origins' => 'nullable|string|max:1000',
            'cors_allowed_methods' => 'nullable|string|max:200',
            'cors_max_age' => 'nullable|integer|min:0|max:86400',
            'production_qty_edit_policy' => 'required|in:none,timed,full',
            'production_qty_edit_window_minutes' => 'required_if:production_qty_edit_policy,timed|integer|min:1|max:60',
            'scanner_mode' => 'required|in:hid,manual',
            // Serialised units (UnitSerialisation) - patterns are bare regexes.
            'unit_identifier_normalize' => 'nullable|boolean',
            'unit_serial_pattern' => ['nullable', 'string', 'max:200', $this->validRegex()],
            'unit_psn_pattern' => ['nullable', 'string', 'max:200', $this->validRegex()],
            'unit_psn_required' => 'nullable|boolean',
            'unit_psn_unique' => 'nullable|boolean',
            'unit_test_fail_policy' => ['nullable', Rule::in(\App\Support\UnitSerialisation::FAIL_POLICIES)],
            'unit_test_max_attempts' => 'nullable|integer|min:1|max:99',
            'unit_test_attempts_scope' => ['nullable', Rule::in(\App\Support\UnitSerialisation::ATTEMPT_SCOPES)],
            'standard_weekly_hours' => 'nullable|numeric|min:1|max:168',
            // One currency per installation, from the shared ISO 4217 allowlist.
            'default_currency' => ['nullable', 'string', Rule::in(CurrencyRegistry::codes())],
            'default_pay_type' => 'nullable|in:hourly,weekly,piece_rate',
            'default_pay_rate' => 'nullable|numeric|min:0',
            // Optional feature modules (#144).
            'enabled_modules' => 'nullable|array',
            'enabled_modules.*' => ['string', Rule::in(ModuleRegistry::optionalKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_currency.in' => __('The selected currency is not a supported ISO 4217 code.'),
        ];
    }

    /** A pattern the settings page accepts is one PCRE can compile. */
    private function validRegex(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! \App\Support\UnitSerialisation::validPattern((string) $value)) {
                $fail(__('The :attribute is not a valid regular expression.', ['attribute' => $attribute]));
            }
        };
    }
}
