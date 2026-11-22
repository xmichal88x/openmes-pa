<?php

namespace App\Services\Material;

use App\Models\Material;
use App\Models\MaterialType;
use Illuminate\Support\Str;

/**
 * Pulling materials (and their balances) from another system.
 *
 * A balance that is still awaiting settlement by its production operation is
 * not the ERP's to overwrite: one item, one author. Such a row is reported and
 * skipped whole — never half-applied — so the plant keeps the quantity the
 * operation withdrew and the discrepancy stays visible to the person syncing.
 */
class MaterialSyncService
{
    /**
     * Import materials from an external system.
     * Matches by external_code + source_system. Creates or updates.
     *
     * @return array{created: int, updated: int, errors: array}
     */
    public function importFromExternalSystem(string $sourceSystem, array $materials): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        $typeCache = MaterialType::pluck('id', 'code')->toArray();
        $defaultTypeId = $typeCache['raw_material'] ?? null;

        foreach ($materials as $index => $row) {
            try {
                $typeId = $defaultTypeId;
                if (isset($row['type']) && isset($typeCache[$row['type']])) {
                    $typeId = $typeCache[$row['type']];
                }

                $existing = Material::where('external_code', $row['external_code'])
                    ->where('external_system', $sourceSystem)
                    ->first();

                if ($existing) {
                    // The row would rewrite a balance the operation still owes:
                    // report it and leave every column of this row untouched.
                    if (isset($row['stock_quantity']) && app(MaterialSettlementPolicy::class)->hasPendingSettlement($existing)) {
                        $result['errors'][] = [
                            'index' => $index,
                            'external_code' => $row['external_code'] ?? null,
                            'error' => __('Material :code is settled by its production operation; its stock cannot be overwritten while the settlement is pending.', [
                                'code' => $existing->code,
                            ]),
                        ];

                        continue;
                    }

                    $updateData = [
                        'name' => $row['name'],
                        'description' => $row['description'] ?? $existing->description,
                        'material_type_id' => $typeId,
                        'unit_of_measure' => $row['unit'] ?? $existing->unit_of_measure,
                        'extra_data' => $row['extra_data'] ?? $existing->extra_data,
                        'last_stock_sync_at' => now(),
                    ];
                    if (isset($row['stock_quantity'])) {
                        $updateData['stock_quantity'] = $row['stock_quantity'];
                    }
                    if (isset($row['min_stock_level'])) {
                        $updateData['min_stock_level'] = $row['min_stock_level'];
                    }
                    if (isset($row['unit_price'])) {
                        $updateData['unit_price'] = $row['unit_price'];
                    }
                    if (isset($row['price_currency'])) {
                        $updateData['price_currency'] = $row['price_currency'];
                    }
                    if (isset($row['ean'])) {
                        $updateData['ean'] = $row['ean'];
                    }
                    if (isset($row['supplier_name'])) {
                        $updateData['supplier_name'] = $row['supplier_name'];
                    }
                    if (isset($row['supplier_code'])) {
                        $updateData['supplier_code'] = $row['supplier_code'];
                    }
                    $existing->update($updateData);
                    $result['updated']++;
                } else {
                    $code = $this->generateUniqueCode($row['external_code']);

                    Material::create([
                        'code' => $code,
                        'name' => $row['name'],
                        'description' => $row['description'] ?? null,
                        'material_type_id' => $typeId,
                        'unit_of_measure' => $row['unit'] ?? 'pcs',
                        'external_code' => $row['external_code'],
                        'external_system' => $sourceSystem,
                        'extra_data' => $row['extra_data'] ?? null,
                        'stock_quantity' => $row['stock_quantity'] ?? 0,
                        'min_stock_level' => $row['min_stock_level'] ?? null,
                        'unit_price' => $row['unit_price'] ?? null,
                        'price_currency' => $row['price_currency'] ?? \App\Support\CurrencyRegistry::current(),
                        'ean' => $row['ean'] ?? null,
                        'supplier_name' => $row['supplier_name'] ?? null,
                        'supplier_code' => $row['supplier_code'] ?? null,
                        'last_stock_sync_at' => now(),
                    ]);
                    $result['created']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'index' => $index,
                    'external_code' => $row['external_code'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    private function generateUniqueCode(string $externalCode): string
    {
        $code = Str::upper(Str::slug($externalCode, '-'));
        $code = Str::limit($code, 47, '');

        if (! Material::where('code', $code)->exists()) {
            return $code;
        }

        $i = 1;
        while (Material::where('code', "{$code}-{$i}")->exists()) {
            $i++;
        }

        return "{$code}-{$i}";
    }
}
