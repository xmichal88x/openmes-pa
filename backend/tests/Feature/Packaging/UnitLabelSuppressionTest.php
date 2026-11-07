<?php

namespace Tests\Feature\Packaging;

use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The packing step's "unit_label" flag is overruling: false means no unit label is offered at the scan. */
class UnitLabelSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Operator', 'web');
        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
    }

    /** An order whose reachable packing step carries the given config, with units to pack. */
    private function packableOrder(array $config): WorkOrder
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create(['process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack', 'kind' => TemplateStep::KIND_PACKING, 'config' => $config]);
        $wo = WorkOrder::factory()->create(['product_type_id' => $product->id, 'process_snapshot' => $template->fresh()->toSnapshot(), 'planned_qty' => 10, 'status' => WorkOrder::STATUS_IN_PROGRESS]);
        app(WorkOrderService::class)->createBatch($wo, 10);
        foreach (range(1, 4) as $i) {
            SerialUnit::create(['serial_no' => "SN-{$wo->id}-{$i}", 'psn' => "P-{$wo->id}-{$i}", 'work_order_id' => $wo->id]);
        }

        return $wo;
    }

    private function scan(string $psn)
    {
        return $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => $psn, 'auto_carton' => true]);
    }

    public function test_unit_label_false_offers_no_label_for_an_unlabelled_unit(): void
    {
        $wo = $this->packableOrder(['unit' => 'carton', 'carton_capacity' => 5, 'unit_label' => false]);
        $unit = SerialUnit::where('psn', "P-{$wo->id}-1")->firstOrFail();
        $this->assertArrayNotHasKey('label_applied_at', $unit->extra_data ?? []);

        $this->scan("P-{$wo->id}-1")->assertOk()->assertJsonPath('label_needed', false);
    }

    public function test_unit_label_false_offers_no_label_for_an_already_labelled_unit(): void
    {
        $wo = $this->packableOrder(['unit' => 'carton', 'carton_capacity' => 5, 'unit_label' => false]);
        SerialUnit::where('psn', "P-{$wo->id}-1")->firstOrFail()->update(['extra_data' => ['label_applied_at' => now()->toIso8601String()]]);

        $this->scan("P-{$wo->id}-1")->assertOk()->assertJsonPath('label_needed', false);
    }

    public function test_the_default_still_offers_a_label_for_an_unlabelled_unit(): void
    {
        $wo = $this->packableOrder(['unit' => 'carton', 'carton_capacity' => 5]);
        $unit = SerialUnit::where('psn', "P-{$wo->id}-1")->firstOrFail();
        $this->assertArrayNotHasKey('label_applied_at', $unit->extra_data ?? []);

        $this->scan("P-{$wo->id}-1")->assertOk()->assertJsonPath('label_needed', true);
    }

    public function test_the_default_prints_on_every_scan_even_for_an_already_labelled_unit(): void
    {
        $wo = $this->packableOrder(['unit' => 'carton', 'carton_capacity' => 5]);
        SerialUnit::where('psn', "P-{$wo->id}-1")->firstOrFail()->update(['extra_data' => ['label_applied_at' => now()->toIso8601String()]]);

        $this->scan("P-{$wo->id}-1")->assertOk()->assertJsonPath('label_needed', true);
    }
}
