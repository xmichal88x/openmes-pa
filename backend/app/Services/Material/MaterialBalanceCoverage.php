<?php

namespace App\Services\Material;

use App\Models\BomItem;
use App\Models\Material;

/**
 * Whether every material an active recipe consumes has a balance anyone can
 * stand behind (F1-2).
 *
 * The production hold (F1-3) refuses to start a batch on an undeclared balance,
 * so switching it on while the recipes of the plant still reference SKUs nobody
 * declared would only park production behind a wall of unknowns. The switch is a
 * promise that the level of every consumed material is comparable with a demand
 * — a promise that can only be made when the balance exists.
 *
 * "Active recipes" are the live BOM lines of active process templates: what the
 * plant may actually run today. A template that is switched off does not gate
 * the switch — its lines can be corrected before it is activated.
 */
class MaterialBalanceCoverage
{
    /**
     * Materials consumed by active recipes whose balance is not declared.
     *
     * Keyed by material code so a message can name the SKU a person knows, with
     * the state of the balance (`unknown`, `stale`, `demo_seed`) as the value:
     * the fix differs per state and "not declared" alone would not say which.
     *
     * @return array<string, string> code => balance state
     */
    public function undeclaredInActiveRecipes(): array
    {
        $materialIds = BomItem::query()
            ->whereNotNull('material_id')
            // A product-type line names a sub-assembly, not a material: what
            // needs a balance is the material consumed by that sub-assembly's
            // own recipe, checked when that recipe is active.
            ->whereNull('product_type_id')
            // The relation excludes soft-deleted templates, so switching a
            // template off takes its BOM out of the check with it.
            ->whereHas('processTemplate', fn ($query) => $query->where('is_active', true))
            ->distinct()
            ->pluck('material_id')
            ->all();

        if ($materialIds === []) {
            return [];
        }

        // One query for all of them and the verdict in PHP: hasDeclaredBalance()
        // encodes the ownership rule, and restating it as SQL would let the two
        // drift apart.
        $undeclared = [];

        foreach (Material::query()->whereIn('id', $materialIds)->get() as $material) {
            if ($material->hasDeclaredBalance()) {
                continue;
            }

            $undeclared[$material->code] = $material->balanceState();
        }

        // A BOM line pointing at a material id that no longer resolves (hard
        // delete, or a row soft-deleted while still referenced) is left out on
        // purpose: nothing can be declared for a record that is not there, and
        // keeping it would block the switch forever. Repairing such a line is a
        // BOM-integrity task of its own, not a balance declaration.
        ksort($undeclared);

        return $undeclared;
    }

    /**
     * The refusal's item list: "MAT-1 (unknown), MAT-2 (demo_seed)".
     *
     * @param  array<string, string>  $undeclared  code => balance state
     */
    public function describe(array $undeclared): string
    {
        return implode(', ', array_map(
            fn (string $code, string $state): string => $code.' ('.$state.')',
            array_keys($undeclared),
            array_values($undeclared),
        ));
    }
}
