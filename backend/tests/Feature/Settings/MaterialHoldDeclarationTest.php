<?php

namespace Tests\Feature\Settings;

use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\ProcessTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F1-2: the shortage hold compares a demand against a balance, so it may only
 * be switched on when every material the active recipes consume has one
 * declared. Switching it on over unknowns would park the first batch on a
 * balance nobody can confirm (F1-3).
 */
class MaterialHoldDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'hold_on_material_shortage';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    /** A material with the given provenance, consumed by a template's live BOM. */
    private function consumedMaterial(
        string $code,
        string $owner = Material::STOCK_OWNER_UNKNOWN,
        string $source = Material::BALANCE_SOURCE_UNKNOWN,
        bool $templateActive = true,
    ): Material {
        $material = Material::factory()->create([
            'code' => $code,
            'unit_of_measure' => 'pcs',
            'material_type_id' => MaterialType::factory()->create()->id,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'stock_owner' => $owner,
            'balance_source' => $source,
        ]);

        BomItem::factory()->create([
            'process_template_id' => ProcessTemplate::factory()->create(['is_active' => $templateActive])->id,
            'material_id' => $material->id,
        ]);

        return $material;
    }

    /** The rest of the settings form, so only the switch is under test. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'production_period' => 'none',
            'workflow_mode' => 'status',
            'schedule_view_mode' => 'weekly',
            'schedule_shifts_per_day' => 1,
            'schedule_horizon_weeks' => 6,
            'realtime_mode' => 'polling',
            'production_tracking_mode' => 'per_operation',
            'production_qty_edit_policy' => 'none',
            'scanner_mode' => 'hid',
        ], $overrides);
    }

    /** The stored switch: true/false once written, null while nothing was. */
    private function stored(): ?bool
    {
        return json_decode(DB::table('system_settings')->where('key', self::KEY)->value('value'), true);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->from('/settings/system')
            ->post('/settings/system', $this->payload($overrides));
    }

    public function test_an_unknown_balance_in_an_active_recipe_blocks_switching_the_hold_on(): void
    {
        $this->consumedMaterial('MAT-UNKNOWN');

        $this->save([self::KEY => '1'])
            ->assertRedirect('/settings/system')
            ->assertSessionHasErrors(self::KEY);

        $this->assertNull($this->stored(), 'a rejected request must not write the switch');
    }

    public function test_the_refusal_names_the_material_and_its_state_in_json_too(): void
    {
        $this->consumedMaterial('MAT-UNKNOWN');

        $response = $this->actingAs($this->admin)
            ->postJson('/settings/system', $this->payload([self::KEY => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(self::KEY);

        $message = $response->json('errors.'.self::KEY.'.0');
        $this->assertStringContainsString('MAT-UNKNOWN (unknown)', $message);
        $this->assertStringContainsString('opening declaration', $message);
        $this->assertNull($this->stored());
    }

    public function test_a_demo_seed_balance_blocks_switching_the_hold_on(): void
    {
        $this->consumedMaterial('MAT-DEMO', Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_DEMO_SEED);

        $this->save([self::KEY => '1'])->assertSessionHasErrors(self::KEY);

        $this->assertNull($this->stored());
    }

    public function test_a_stale_balance_blocks_switching_the_hold_on(): void
    {
        $this->consumedMaterial('MAT-STALE', Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_STALE);

        $this->save([self::KEY => '1'])->assertSessionHasErrors(self::KEY);

        $this->assertNull($this->stored());
    }

    public function test_a_declared_balance_everywhere_lets_the_hold_be_switched_on(): void
    {
        $this->consumedMaterial('MAT-ERP', Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_ERP_PROJECTION);
        $this->consumedMaterial('MAT-WIP', Material::STOCK_OWNER_MES, Material::BALANCE_SOURCE_OPENING_DECLARED);

        $this->save([self::KEY => '1'])->assertSessionHasNoErrors();

        $this->assertTrue($this->stored());
    }

    public function test_the_hold_can_always_be_switched_off(): void
    {
        $this->consumedMaterial('MAT-UNKNOWN');

        $this->save([self::KEY => '0'])->assertSessionHasNoErrors();

        $this->assertFalse($this->stored());
    }

    public function test_a_material_consumed_only_by_an_inactive_template_does_not_block_the_switch(): void
    {
        $this->consumedMaterial('MAT-RETIRED', templateActive: false);

        $this->save([self::KEY => '1'])->assertSessionHasNoErrors();

        $this->assertTrue($this->stored());
    }
}
