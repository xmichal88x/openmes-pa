<?php

namespace Tests\Feature\Packaging;

use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\QaProfile;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** QA pre-scan gate at packing: nothing irreversible happens before the checklist passes. */
class QaGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Operator', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        config(['openmmes.qa_enforcement' => false]);
    }

    /** An order that packs into cartons, with one unit ready to scan. */
    private function packableOrder(bool $withProfile = true): WorkOrder
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id,
            'step_number' => 1,
            'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING,
            'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);

        $wo = WorkOrder::factory()->create([
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => 4,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
        app(WorkOrderService::class)->createBatch($wo, 4);
        SerialUnit::create(['serial_no' => "SN-QA-{$wo->id}", 'psn' => "P-QA-{$wo->id}", 'work_order_id' => $wo->id]);

        if ($withProfile) {
            QaProfile::factory()->create([
                'product_type_id' => $product->id,
                'product_type_code' => $product->code,
                'required' => true,
                'checklist_json' => [['id' => 'dim', 'label' => 'Wymiar', 'type' => 'bool', 'required' => true]],
                'active' => true,
            ]);
        }

        return $wo;
    }

    private function scan(WorkOrder $wo, array $body = [])
    {
        return $this->actingAs($this->operator)
            ->postJson(route('packaging.scan-unit'), ['psn' => "P-QA-{$wo->id}"] + $body);
    }

    private function unit(WorkOrder $wo): SerialUnit
    {
        return SerialUnit::where('psn', "P-QA-{$wo->id}")->firstOrFail();
    }

    private function historyEvent(SerialUnit $unit, string $event): ?array
    {
        return $unit->history()->get()
            ->map(fn ($entry) => $entry->parameters)
            ->first(fn ($parameters) => ($parameters['event'] ?? null) === $event);
    }

    public function test_a_scan_without_qa_is_blocked_before_packing(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder();

        $this->scan($wo)
            ->assertStatus(422)
            ->assertJsonPath('needs_qa', true)
            ->assertJsonPath('qa_profile.checklist_json.0.id', 'dim');

        $unit = $this->unit($wo);
        $this->assertNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_IN_PRODUCTION, $unit->status);
    }

    public function test_a_passing_checklist_packs_the_unit_and_records_the_pass(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder();

        $this->scan($wo, ['qa' => ['items' => [['id' => 'dim', 'value' => true]]]])
            ->assertOk();

        $unit = $this->unit($wo);
        $this->assertNotNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_COMPLETED, $unit->status);

        $pass = $this->historyEvent($unit, 'quality_pass');
        $this->assertNotNull($pass);
        $this->assertSame('packing', $pass['stage']);
    }

    public function test_a_failed_check_with_rework_blocks_the_unit(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder();

        $this->scan($wo, ['qa' => [
            'items' => [['id' => 'dim', 'value' => false]],
            'decision' => 'rework',
        ]])
            ->assertStatus(422)
            ->assertJsonPath('quality_failed', true)
            ->assertJsonPath('decision', 'rework')
            ->assertJsonPath('unit_status', SerialUnit::STATUS_BLOCKED);

        $unit = $this->unit($wo);
        $this->assertNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_BLOCKED, $unit->status);
        $this->assertSame('quality', $unit->extra_data['hold']['source']);
        $this->assertSame('rework', $unit->extra_data['hold']['decision']);

        $defect = $this->historyEvent($unit, 'quality_defect');
        $this->assertNotNull($defect);
        $this->assertSame('rework', $defect['decision']);
    }

    public function test_a_failed_check_with_recut_scraps_the_unit(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder();

        $this->scan($wo, ['qa' => [
            'items' => [['id' => 'dim', 'value' => false]],
            'decision' => 'recut',
        ]])
            ->assertStatus(422)
            ->assertJsonPath('quality_failed', true)
            ->assertJsonPath('unit_status', SerialUnit::STATUS_SCRAPPED);

        $unit = $this->unit($wo);
        $this->assertNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_SCRAPPED, $unit->status);
        $this->assertSame('recut', $this->historyEvent($unit, 'quality_defect')['decision']);
    }

    public function test_a_failed_check_without_a_decision_leaves_the_unit_untouched(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder();

        $this->scan($wo, ['qa' => ['items' => [['id' => 'dim', 'value' => false]]]])
            ->assertStatus(422)
            ->assertJsonPath('quality_failed', true)
            ->assertJsonPath('requires_decision', true);

        $unit = $this->unit($wo);
        $this->assertNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_IN_PRODUCTION, $unit->status);
        $this->assertNull($this->historyEvent($unit, 'quality_defect'));
    }

    public function test_a_product_type_without_a_profile_packs_fail_open(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder(withProfile: false);

        $this->scan($wo)->assertOk();

        $unit = $this->unit($wo);
        $this->assertNotNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_COMPLETED, $unit->status);
    }

    public function test_a_required_profile_with_an_empty_checklist_refuses_the_scan(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder(withProfile: false);
        QaProfile::factory()->create([
            'product_type_id' => $wo->product_type_id,
            'product_type_code' => $wo->productType->code,
            'required' => true,
            'checklist_json' => [],
            'active' => true,
        ]);

        $this->scan($wo)->assertStatus(422)->assertJsonPath('qa_corrupt', true);

        $unit = $this->unit($wo);
        $this->assertNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_IN_PRODUCTION, $unit->status);
    }

    public function test_without_enforcement_the_gate_is_shadow_only(): void
    {
        config(['openmmes.qa_enforcement' => false]);
        $wo = $this->packableOrder();

        $this->scan($wo)->assertOk();

        $unit = $this->unit($wo);
        $this->assertNotNull($unit->packed_at);
        $this->assertSame(SerialUnit::STATUS_COMPLETED, $unit->status);
        $this->assertNull($this->historyEvent($unit, 'quality_pass'));
    }

    public function test_rescanning_an_already_packed_unit_skips_the_gate(): void
    {
        config(['openmmes.qa_enforcement' => true]);
        $wo = $this->packableOrder();

        $this->scan($wo, ['qa' => ['items' => [['id' => 'dim', 'value' => true]]]])->assertOk();
        $this->scan($wo)
            ->assertOk()
            ->assertJsonPath('already_packed', true);

        $this->assertNotNull($this->unit($wo)->packed_at);
    }

    public function test_the_api_cannot_create_a_unit_that_is_already_completed(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/serial-units', ['serial_no' => 'SN-QA-HARD', 'status' => SerialUnit::STATUS_COMPLETED])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('serial_units', ['serial_no' => 'SN-QA-HARD']);
    }
}
