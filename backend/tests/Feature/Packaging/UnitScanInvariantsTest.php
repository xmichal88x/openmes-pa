<?php

namespace Tests\Feature\Packaging;

use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\UnitCarton;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Traceability\SerialTraceService;
use App\Services\Traceability\UnitForeignBatchException;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two invariants of the packing bench (P2.13): a packed label refuses further
 * scans with where it already is, and a unit never lands on a batch of another
 * work order. The legal carton -> pallet move keeps working.
 */
class UnitScanInvariantsTest extends TestCase
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
    }

    /** An order with a packing step, its batch, and units to pack. */
    private function packableOrder(array $config = ['unit' => 'carton', 'carton_capacity' => 5], int $units = 2): WorkOrder
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create(['process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack', 'kind' => TemplateStep::KIND_PACKING, 'config' => $config]);
        $wo = WorkOrder::factory()->create(['product_type_id' => $product->id, 'process_snapshot' => $template->fresh()->toSnapshot(), 'planned_qty' => 10, 'status' => WorkOrder::STATUS_IN_PROGRESS]);
        app(WorkOrderService::class)->createBatch($wo, 10);
        foreach (range(1, $units) as $i) {
            SerialUnit::create(['serial_no' => "SN-{$wo->id}-{$i}", 'psn' => "P-{$wo->id}-{$i}", 'work_order_id' => $wo->id]);
        }

        return $wo;
    }

    /** The order's open carton, as the bench opens one. */
    private function openCarton(WorkOrder $wo): array
    {
        return $this->actingAs($this->operator)->postJson(route('packaging.cartons.store'), ['work_order_id' => $wo->id])
            ->assertCreated()->json('carton');
    }

    public function test_the_same_label_in_the_same_carton_is_refused_with_where_it_is(): void
    {
        $wo = $this->packableOrder();
        $carton = $this->openCarton($wo);
        $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1", 'carton_id' => $carton['id']])->assertOk();

        $refused = $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1", 'carton_id' => $carton['id']])
            ->assertStatus(422)
            ->assertJsonPath('already_packed', true)
            ->assertJsonPath('carton', $carton['carton_no'])
            ->assertJsonPath('pallet', null)
            ->json();

        $this->assertStringContainsString($carton['carton_no'], $refused['message']);
        $this->assertStringContainsString('rescan refused', $refused['message']);
        $this->assertNotEmpty($refused['packed_at']);
        $this->assertSame(1, (int) UnitCarton::findOrFail($carton['id'])->qty);
        $this->assertSame(1, SerialUnit::where('psn', "P-{$wo->id}-1")->firstOrFail()->history()->where('parameters->event', 'packed')->count());
    }

    public function test_the_same_label_aimed_at_another_carton_is_refused_and_changes_nothing(): void
    {
        $wo = $this->packableOrder();
        $first = $this->openCarton($wo);
        $second = $this->openCarton($wo);
        $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1", 'carton_id' => $first['id']])->assertOk();

        $refused = $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1", 'carton_id' => $second['id']])
            ->assertStatus(422)->json();

        $this->assertStringContainsString($first['carton_no'], $refused['message']);
        $this->assertSame($first['id'], SerialUnit::where('psn', "P-{$wo->id}-1")->value('carton_id'));
        $this->assertSame(1, (int) UnitCarton::findOrFail($first['id'])->qty);
        $this->assertSame(0, (int) UnitCarton::findOrFail($second['id'])->qty);
    }

    public function test_a_packed_loose_unit_refuses_a_rescan(): void
    {
        $wo = $this->packableOrder(['unit' => 'unit']);
        $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1"])->assertOk();

        $refused = $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1"])
            ->assertStatus(422)
            ->assertJsonPath('already_packed', true)
            ->assertJsonPath('carton', null)
            ->assertJsonPath('pallet', null)
            ->json();

        $this->assertStringContainsString('already packed', $refused['message']);
        $this->assertStringContainsString('rescan refused', $refused['message']);
        $this->assertNotNull(SerialUnit::where('psn', "P-{$wo->id}-1")->value('packed_at'));
    }

    public function test_a_packed_carton_is_still_scanned_onto_a_pallet(): void
    {
        $wo = $this->packableOrder();
        $carton = $this->openCarton($wo);
        $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1", 'carton_id' => $carton['id']])->assertOk();
        $pallet = $this->actingAs($this->operator)->postJson(route('packaging.pallets.create'), ['work_order_id' => $wo->id])
            ->assertCreated()->json('pallet');

        $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => "P-{$wo->id}-1", 'pallet_id' => $pallet['id']])
            ->assertOk()
            ->assertJsonPath('unit.pallet_no', $pallet['pallet_no']);

        $unit = SerialUnit::where('psn', "P-{$wo->id}-1")->firstOrFail();
        $this->assertSame($pallet['id'], $unit->pallet_id);
        $this->assertSame($carton['id'], $unit->carton_id);
        $this->assertTrue($unit->history()->where('parameters->pallet_no', $pallet['pallet_no'])->exists());
    }

    public function test_a_unit_whose_batch_belongs_to_another_order_cannot_be_packed(): void
    {
        $own = $this->packableOrder();
        $other = $this->packableOrder(['unit' => 'carton', 'carton_capacity' => 5], 1);
        $foreignBatch = $other->batches()->firstOrFail();
        $unit = SerialUnit::where('psn', "P-{$own->id}-1")->firstOrFail();
        $unit->update(['batch_id' => $foreignBatch->id]);

        $this->actingAs($this->operator)->postJson(route('packaging.scan-unit'), ['psn' => $unit->psn])
            ->assertStatus(422)
            ->assertJsonPath('unit_foreign_batch', true)
            ->assertJsonPath('batch', (string) $foreignBatch->batch_number)
            ->assertJsonPath('batch_work_order', $other->order_no)
            ->assertJsonPath('unit_work_order', $own->order_no);

        $this->assertNull($unit->fresh()->packed_at);
    }

    public function test_the_api_registers_a_unit_on_the_batchs_work_order(): void
    {
        $wo = $this->packableOrder();
        $batch = $wo->batches()->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/v1/serial-units', ['serial_no' => 'SN-BATCH-1', 'batch_id' => $batch->id])
            ->assertCreated()
            ->assertJsonPath('data.work_order_id', $wo->id)
            ->assertJsonPath('data.batch_id', $batch->id);

        $this->assertDatabaseHas('serial_units', ['serial_no' => 'SN-BATCH-1', 'work_order_id' => $wo->id, 'batch_id' => $batch->id]);
    }

    public function test_the_api_refuses_a_batch_of_another_order_and_creates_no_unit(): void
    {
        $own = $this->packableOrder();
        $other = $this->packableOrder(['unit' => 'carton'], 1);
        $foreignBatch = $other->batches()->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/v1/serial-units', [
            'serial_no' => 'SN-CONFLICT-1',
            'work_order_id' => $own->id,
            'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['batch_id']);

        $this->assertDatabaseMissing('serial_units', ['serial_no' => 'SN-CONFLICT-1']);
    }

    public function test_registering_a_unit_on_a_foreign_batch_is_refused_by_the_service(): void
    {
        $own = $this->packableOrder();
        $other = $this->packableOrder(['unit' => 'carton'], 1);
        $foreignBatch = $other->batches()->firstOrFail();

        try {
            app(SerialTraceService::class)->registerUnit('SN-FOREIGN-1', ['work_order_id' => $own->id, 'batch_id' => $foreignBatch->id]);
            $this->fail('A batch of another order must be refused.');
        } catch (UnitForeignBatchException $e) {
            $this->assertSame('unit_foreign_batch', UnitForeignBatchException::CODE);
            $this->assertStringContainsString((string) $foreignBatch->batch_number, $e->getMessage());
            $this->assertStringContainsString((string) $other->order_no, $e->getMessage());
            $this->assertStringContainsString((string) $own->order_no, $e->getMessage());
        }

        $this->assertDatabaseMissing('serial_units', ['serial_no' => 'SN-FOREIGN-1']);
    }
}
