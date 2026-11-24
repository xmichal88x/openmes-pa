<?php

namespace Tests\Unit\Models;

use App\Models\Material;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_material_defaults_to_unknown_ownership(): void
    {
        $unsaved = new Material;
        $this->assertSame(Material::STOCK_OWNER_UNKNOWN, $unsaved->stock_owner);
        $this->assertSame(Material::BALANCE_SOURCE_UNKNOWN, $unsaved->balance_source);
        $this->assertFalse($unsaved->hasDeclaredBalance());

        $material = Material::factory()->create();
        $this->assertSame('unknown', $material->stock_owner);
        $this->assertSame('unknown', $material->balance_source);
        $this->assertNull($material->balance_as_of);
        $this->assertFalse($material->hasDeclaredBalance());
        $this->assertFalse($material->isErpOwned());
        $this->assertFalse($material->isMesOwned());
    }

    public function test_erp_opening_declared_is_a_declared_balance(): void
    {
        $material = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_OPENING_DECLARED,
            'balance_as_of' => now(),
        ]);

        $this->assertSame('erp', $material->fresh()->stock_owner);
        $this->assertSame('opening_declared', $material->fresh()->balance_source);
        $this->assertTrue($material->hasDeclaredBalance());
        $this->assertTrue($material->isErpOwned());
    }

    public function test_erp_projection_is_a_declared_balance_for_erp_owner(): void
    {
        $material = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_ERP_PROJECTION,
        ]);

        $this->assertTrue($material->hasDeclaredBalance());
    }

    public function test_mes_ledger_is_a_declared_balance_for_mes_owner(): void
    {
        $material = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_MES,
            'balance_source' => Material::BALANCE_SOURCE_MES_LEDGER,
        ]);

        $this->assertTrue($material->hasDeclaredBalance());
        $this->assertTrue($material->isMesOwned());
    }

    public function test_non_declared_sources_are_not_a_declared_balance(): void
    {
        foreach ([
            Material::BALANCE_SOURCE_DEMO_SEED,
            Material::BALANCE_SOURCE_STALE,
            Material::BALANCE_SOURCE_UNKNOWN,
        ] as $source) {
            $material = Material::factory()->create([
                'stock_owner' => Material::STOCK_OWNER_ERP,
                'balance_source' => $source,
            ]);

            $this->assertFalse(
                $material->hasDeclaredBalance(),
                "ERP-owned material with source {$source} must not count as declared.",
            );
        }
    }

    public function test_source_must_match_the_owner(): void
    {
        $mesWithErpSource = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_MES,
            'balance_source' => Material::BALANCE_SOURCE_ERP_PROJECTION,
        ]);
        $this->assertFalse($mesWithErpSource->hasDeclaredBalance());

        $erpWithMesSource = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_MES_LEDGER,
        ]);
        $this->assertFalse($erpWithMesSource->hasDeclaredBalance());
    }

    public function test_balance_as_of_is_cast_to_datetime(): void
    {
        $material = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_ERP_PROJECTION,
            'balance_as_of' => '2026-11-24 10:30:00',
        ]);

        $fresh = $material->fresh();
        $this->assertInstanceOf(Carbon::class, $fresh->balance_as_of);
        $this->assertSame('2026-11-24 10:30:00', $fresh->balance_as_of->format('Y-m-d H:i:s'));
    }

    public function test_scope_with_undeclared_balance_returns_only_undeclared_rows(): void
    {
        $declaredErp = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_OPENING_DECLARED,
        ]);
        $declaredMes = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_MES,
            'balance_source' => Material::BALANCE_SOURCE_MES_LEDGER,
        ]);
        $demo = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_DEMO_SEED,
        ]);
        $wrongOwnerSource = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_MES,
            'balance_source' => Material::BALANCE_SOURCE_ERP_PROJECTION,
        ]);
        $unknown = Material::factory()->create([
            'stock_owner' => Material::STOCK_OWNER_UNKNOWN,
            'balance_source' => Material::BALANCE_SOURCE_UNKNOWN,
        ]);

        $ids = Material::query()->withUndeclaredBalance()->pluck('id')->all();

        $this->assertCount(3, $ids);
        $this->assertEqualsCanonicalizing(
            [$demo->id, $wrongOwnerSource->id, $unknown->id],
            $ids,
        );
        $this->assertNotContains($declaredErp->id, $ids);
        $this->assertNotContains($declaredMes->id, $ids);
    }
}
