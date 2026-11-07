<?php

namespace App\Services\Quality;

use App\Models\BatchStep;
use App\Models\QaProfile;
use App\Models\SerialUnit;
use App\Models\User;
use App\Services\Traceability\SerialTraceService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pre-scan quality gate for packing. A unit is checked against its product
 * type's active QA profile before anything irreversible (packUnit) happens.
 * The gate is fail-open where the plant has no profile and shadow-only until
 * QA_ENFORCEMENT is switched on.
 */
class QualityGateService
{
    public function __construct(private readonly SerialTraceService $serials) {}

    /**
     * @param  array<string, mixed>|null  $qa
     * @return array<string, mixed>
     */
    public function evaluate(SerialUnit $unit, ?array $qa): array
    {
        $productType = $unit->workOrder?->productType;

        if (! $productType || trim((string) $productType->code) === '') {
            return ['status' => 'fail_open', 'reason' => 'qa_product_type_missing'];
        }

        $profile = QaProfile::query()->active()->where('product_type_id', $productType->id)->first();

        if (! $profile) {
            return [
                'status' => 'fail_open',
                'reason' => 'qa_profile_missing',
                'product_type_code' => $productType->code,
            ];
        }

        if (! $profile->hasValidChecklist()) {
            return ['status' => 'corrupt', 'profile' => $profile];
        }

        if (! $profile->required) {
            return ['status' => 'not_required', 'profile' => $profile];
        }

        $items = $qa['items'] ?? null;
        $eval = $this->evaluateItems($profile, is_array($items) ? $items : null);

        if (! (bool) config('openmmes.qa_enforcement', false)) {
            return [
                'status' => 'shadow',
                'profile' => $profile,
                'passed' => $eval['passed'],
                'errors' => $eval['errors'],
            ];
        }

        if ($eval['missing']) {
            return ['status' => 'needs_qa', 'profile' => $profile, 'errors' => $eval['errors']];
        }

        if (! $eval['passed']) {
            return [
                'status' => 'failed',
                'profile' => $profile,
                'errors' => $eval['errors'],
                'decision' => $qa['decision'] ?? null,
            ];
        }

        return ['status' => 'passed', 'profile' => $profile, 'items' => $eval['items']];
    }

    /**
     * Server-side checklist validation; never trust the station's values.
     *
     * @param  array<int, mixed>|null  $items
     * @return array{passed: bool, missing: bool, errors: list<string>, items: list<array{id: string, value: mixed}>}
     */
    public function evaluateItems(QaProfile $profile, ?array $items): array
    {
        $errors = [];
        $missing = false;
        $provided = [];

        if ($items === null || ! array_is_list($items)) {
            return [
                'passed' => false,
                'missing' => true,
                'errors' => [__('QA checklist was not sent.')],
                'items' => [],
            ];
        }

        $checklist = $profile->checklist_json ?? [];
        $known = [];

        foreach ($checklist as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $known[$item['id']] = $item;
            }
        }

        foreach ($items as $entry) {
            if (! is_array($entry) || ! isset($entry['id']) || ! is_string($entry['id'])) {
                $errors[] = __('Malformed QA item.');

                continue;
            }

            if (! array_key_exists($entry['id'], $known)) {
                $errors[] = __('Unknown QA item ":id".', ['id' => $entry['id']]);

                continue;
            }

            if (! array_key_exists('value', $entry)) {
                $errors[] = __('QA item ":id" has no value.', ['id' => $entry['id']]);

                continue;
            }

            $provided[$entry['id']] = $entry['value'];
        }

        $accepted = [];

        foreach ($checklist as $item) {
            $id = $item['id'];
            $label = $item['label'] ?? $id;
            $type = $item['type'] ?? null;
            $required = (bool) ($item['required'] ?? true);

            if (! array_key_exists($id, $provided)) {
                if ($required) {
                    $missing = true;
                    $errors[] = __('QA item ":label" is required.', ['label' => $label]);
                }

                continue;
            }

            $value = $provided[$id];

            switch ($type) {
                case 'bool':
                    if (! is_bool($value)) {
                        $errors[] = __('QA item ":label" must be yes/no.', ['label' => $label]);

                        break;
                    }
                    if ($value !== true) {
                        $errors[] = __('QA item ":label" failed.', ['label' => $label]);
                    }
                    break;

                case 'number':
                    if (! is_numeric($value)) {
                        $errors[] = __('QA item ":label" must be a number.', ['label' => $label]);

                        break;
                    }
                    $number = (float) $value;
                    if (isset($item['min']) && is_numeric($item['min']) && $number < (float) $item['min']) {
                        $errors[] = __('QA item ":label" is below the minimum.', ['label' => $label]);
                    }
                    if (isset($item['max']) && is_numeric($item['max']) && $number > (float) $item['max']) {
                        $errors[] = __('QA item ":label" is above the maximum.', ['label' => $label]);
                    }
                    break;

                case 'text':
                    if (! is_string($value) || trim($value) === '') {
                        $errors[] = __('QA item ":label" must not be empty.', ['label' => $label]);
                    }
                    break;

                default:
                    $errors[] = __('QA item ":label" has an unknown type.', ['label' => $label]);
                    break;
            }

            $accepted[] = ['id' => $id, 'value' => $value];
        }

        return [
            'passed' => ! $missing && $errors === [],
            'missing' => $missing,
            'errors' => $errors,
            'items' => $accepted,
        ];
    }

    /**
     * Record a failed QA verdict and hold/scrap the unit without going through
     * the test fail policy or ScrapReason machinery. History plus status is
     * all that is needed before the unit leaves the bench.
     *
     * @param  list<string>  $errors
     * @return array{decision: string, status: string}
     */
    public function applyFailure(
        SerialUnit $unit,
        User $user,
        string $decision,
        ?BatchStep $step,
        array $errors = [],
        ?int $workstationId = null,
    ): array {
        if (! in_array($decision, ['rework', 'recut', 'scrap'], true)) {
            throw new InvalidArgumentException("Unknown QA decision: {$decision}");
        }

        $productType = $unit->workOrder?->productType;
        $profile = $productType
            ? QaProfile::query()->active()->where('product_type_id', $productType->id)->first()
            : null;

        $note = match ($decision) {
            'rework' => __('QA failed at packing - rework required.'),
            'recut' => __('QA failed at packing - re-cut required.'),
            'scrap' => __('QA failed at packing - unit scrapped.'),
        };

        DB::transaction(function () use ($unit, $user, $step, $decision, $errors, $workstationId, $profile, $note) {
            $this->serials->recordStep($unit, $user, $step, [
                'workstation_id' => $workstationId,
                'exact_workstation' => true,
                'result' => null,
                'parameters' => [
                    'event' => 'quality_defect',
                    'stage' => 'packing',
                    'decision' => $decision,
                    'errors' => $errors,
                    'profile_id' => $profile?->id,
                    'profile_version' => $profile?->version,
                ],
                'notes' => $note,
            ]);

            if ($decision === 'rework') {
                $unit->update([
                    'status' => SerialUnit::STATUS_BLOCKED,
                    'extra_data' => array_merge($unit->extra_data ?? [], [
                        'hold' => [
                            'source' => 'quality',
                            'decision' => 'rework',
                            'by_id' => $user->id,
                            'at' => now()->toIso8601String(),
                        ],
                    ]),
                ]);

                return;
            }

            $unit->update(['status' => SerialUnit::STATUS_SCRAPPED]);
        });

        $unit->refresh();

        return ['decision' => $decision, 'status' => $unit->status];
    }

    /**
     * Record a passing QA check. The caller wraps this in the packing
     * transaction so the pass and the irreversible pack commit together.
     *
     * @param  list<array{id: string, value: mixed}>  $items
     */
    public function recordPass(
        SerialUnit $unit,
        User $user,
        ?BatchStep $step,
        QaProfile $profile,
        array $items = [],
        ?int $workstationId = null,
    ): void {
        $this->serials->recordStep($unit, $user, $step, [
            'workstation_id' => $workstationId,
            'exact_workstation' => true,
            'result' => null,
            'parameters' => [
                'event' => 'quality_pass',
                'stage' => 'packing',
                'items' => $items,
                'profile_id' => $profile->id,
                'profile_version' => $profile->version,
            ],
        ]);
    }
}
