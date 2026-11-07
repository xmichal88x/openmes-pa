<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Models\Concerns\SoftDeletesWithAudit;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class QaProfile extends Model
{
    use Auditable, HasFactory, HasTenant;
    use SoftDeletesWithAudit;

    public const CHECKLIST_TYPES = ['bool', 'number', 'text'];

    public const CHECKLIST_KEYS = ['id', 'label', 'type', 'required', 'min', 'max'];

    protected $fillable = [
        'tenant_id',
        'product_type_id',
        'product_type_code',
        'required',
        'checklist_json',
        'active',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'checklist_json' => 'array',
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function hasValidChecklist(): bool
    {
        return static::checklistErrors($this->checklist_json, $this->required) === [];
    }

    /**
     * @return list<string>
     */
    public static function checklistErrors(?array $items, bool $required = true): array
    {
        $items ??= [];

        if (! array_is_list($items)) {
            return ['Checklista musi być listą elementów.'];
        }

        if ($items === []) {
            return $required ? ['Checklista jest wymagana.'] : [];
        }

        $errors = [];
        $seenIds = [];

        foreach ($items as $index => $item) {
            $position = $index + 1;

            if (! is_array($item)) {
                $errors[] = "Element #{$position} musi być tablicą.";

                continue;
            }

            $id = $item['id'] ?? null;

            if (! is_string($id) || trim($id) === '') {
                $errors[] = "Element #{$position} musi mieć niepuste pole id.";
            } elseif (isset($seenIds[$id])) {
                $errors[] = "Zduplikowane id \"{$id}\".";
            } else {
                $seenIds[$id] = true;
            }

            $label = $item['label'] ?? null;

            if (! is_string($label) || trim($label) === '') {
                $errors[] = "Element #{$position} musi mieć niepuste pole label.";
            }

            $type = $item['type'] ?? null;

            if (! in_array($type, self::CHECKLIST_TYPES, true)) {
                $errors[] = "Element #{$position} ma nieprawidłowy typ (dozwolone: bool, number, text).";

                continue;
            }

            if (array_key_exists('required', $item) && ! is_bool($item['required'])) {
                $errors[] = "Pole required elementu #{$position} musi być typu bool.";
            }

            if ($type !== 'number') {
                continue;
            }

            $min = $item['min'] ?? null;
            $max = $item['max'] ?? null;

            if ($min !== null && ! is_numeric($min)) {
                $errors[] = "Pole min elementu #{$position} musi być liczbą.";
            }

            if ($max !== null && ! is_numeric($max)) {
                $errors[] = "Pole max elementu #{$position} musi być liczbą.";
            }

            if (is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                $errors[] = "Pole min elementu #{$position} nie może być większe od max.";
            }
        }

        return $errors;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function normaliseChecklist(?array $items, bool $required = true): array
    {
        $errors = static::checklistErrors($items, $required);

        if ($errors !== []) {
            throw new InvalidArgumentException('Nieprawidłowa checklista QA: '.implode(' ', $errors));
        }

        $items ??= [];

        return array_map(function (array $item): array {
            $normalised = [
                'id' => $item['id'],
                'label' => $item['label'],
                'type' => $item['type'],
                'required' => $item['required'] ?? true,
            ];

            if ($item['type'] === 'number') {
                foreach (['min', 'max'] as $key) {
                    if (array_key_exists($key, $item) && $item[$key] !== null) {
                        $normalised[$key] = $item[$key] + 0;
                    }
                }
            }

            return $normalised;
        }, $items);
    }
}
