<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\MaterialSettlementOwnershipException;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\Material\MaterialSyncService;
use App\Services\Settings\ConfigurationImporter;
use App\Services\Warehouse\StockDocumentService;
use App\Services\Warehouse\WorkOrderStockDocumentService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-265 A2b — one item, one author of the withdrawal (SAP: consumption
 * cannot be posted for a product marked for backflush).
 *
 * A component settled by its production operation owes its whole withdrawal to
 * that operation: the work-order release does not list it, a material issue
 * posted by hand is refused, an ERP stock overwrite is refused while the
 * settlement is pending, and a configuration import that would rewrite the
 * material is refused whole.
 *
 * The exclusivity is a policy decision read before anything is booked, so a
 * second author can never slip through between a check and a write: there is no
 * interleaving window to guard against, and no lock is needed for it. The
 * balance locks in the posting path stay what they always were — they protect
 * the arithmetic, not the ownership.
 */
class OperationMaterialOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Material $pilot;

    private Material $legacy;

    private ProductType $product;

    private ProcessTemplate $template;

    private User $operator;

    private Warehouse $raw;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Operator', 'web');

        $this->raw = Warehouse::factory()->rawMaterial()->isDefault()->create(['code' => 'RAW-1']);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->pilot = Material::factory()->create([
            'code' => 'FORMATKA-18',
            'name' => 'Formatka 18 mm',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_code' => 'ERP-F18',
            'external_system' => 'erp',
            'stock_quantity' => 100,
        ]);

        $this->legacy = Material::factory()->create([
            'code' => 'KLEJ',
            'name' => 'Klej',
            'unit_of_measure' => 'kg',
            'tracking_type' => 'none',
            'external_code' => 'ERP-KLEJ',
            'external_system' => 'erp',
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-A2B',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'A2b pilot routing',
            'is_active' => true,
        ]);

        $cutStep = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);

        foreach ([$this->pilot, $this->legacy] as $material) {
            BomItem::create([
                'process_template_id' => $this->template->id,
                'template_step_id' => $cutStep->id,
                'material_id' => $material->id,
                'quantity_per_unit' => 1,
                'scrap_percentage' => 0,
                'consumed_at' => 'start',
            ]);
        }
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** The opt-in: only the formwork is settled by the operation that cuts it. */
    private function pilotExtraData(): array
    {
        return [
            MaterialSettlementPolicy::EXTRA_DATA_KEY => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => [$this->pilot->id],
            ],
        ];
    }

    /**
     * An order whose cutting step was started: both components were withdrawn by
     * the operation, the pilot one still owing its settlement.
     */
    private function startedOrder(?float $produced = 10): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create([
            'order_no' => 'WO-A2B-OWNERSHIP',
            'product_type_id' => $this->product->id,
            'process_snapshot' => $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => 10,
            'produced_qty' => $produced,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => $this->pilotExtraData(),
        ]);

        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        return $workOrder;
    }

    private function stock(Material $material): float
    {
        return (float) $material->fresh()->stock_quantity;
    }

    // ── 1. the release does not list what the operation already settles ──────

    public function test_the_generated_material_issue_excludes_the_operation_settled_component(): void
    {
        $workOrder = $this->startedOrder();

        $issue = app(WorkOrderStockDocumentService::class)->generateMaterialIssue($workOrder);

        $this->assertNotNull($issue);
        $this->assertTrue($issue->isDraft());
        $issue->load('lines');

        // Only the legacy component: the pilot one is answered for by the operation.
        $this->assertCount(1, $issue->lines);
        $this->assertSame($this->legacy->id, (int) $issue->lines->first()->material_id);
        $this->assertEqualsWithDelta(10.0, (float) $issue->lines->first()->quantity, 0.0001);
        $this->assertSame(0, $issue->lines->where('material_id', $this->pilot->id)->count());

        // And the keeper is told why, not left guessing about a missing line.
        $this->assertStringContainsString('FORMATKA-18', (string) $issue->notes);
    }

    // ── 2. a hand-posted issue for the pilot component is refused ────────────

    public function test_posting_a_material_issue_for_the_operation_settled_component_is_refused(): void
    {
        $workOrder = $this->startedOrder();
        $before = $this->stock($this->pilot);

        $document = app(StockDocumentService::class)->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $this->raw->id,
            'work_order_id' => $workOrder->id,
            'lines' => [['material_id' => $this->pilot->id, 'quantity' => 1, 'unit_of_measure' => 'szt']],
        ]);

        try {
            app(StockDocumentService::class)->post($document);
            $this->fail('Posting a withdrawal owned by the operation should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FORMATKA-18', collect($e->errors())->flatten()->implode(' '));
        }

        // Nothing moved: the balance stands and the document is still a draft.
        $this->assertEqualsWithDelta($before, $this->stock($this->pilot), 0.0001);
        $this->assertTrue($document->fresh()->isDraft());
        $this->assertSame(0, StockMovement::where('source_id', $document->id)->count());
    }

    // ── 3./4. the legacy component keeps its own author ──────────────────────

    public function test_a_legacy_component_can_still_be_posted(): void
    {
        $workOrder = $this->startedOrder();
        $before = $this->stock($this->legacy);

        $document = app(StockDocumentService::class)->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $this->raw->id,
            'work_order_id' => $workOrder->id,
            'lines' => [['material_id' => $this->legacy->id, 'quantity' => 5, 'unit_of_measure' => 'kg']],
        ]);

        app(StockDocumentService::class)->post($document);

        $this->assertTrue($document->fresh()->isPosted());
        $this->assertEqualsWithDelta($before - 5, $this->stock($this->legacy), 0.0001);
    }

    public function test_cancelling_a_posted_legacy_document_still_reverses(): void
    {
        $workOrder = $this->startedOrder();
        $before = $this->stock($this->legacy);

        $document = app(StockDocumentService::class)->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $this->raw->id,
            'work_order_id' => $workOrder->id,
            'lines' => [['material_id' => $this->legacy->id, 'quantity' => 5, 'unit_of_measure' => 'kg']],
        ]);

        app(StockDocumentService::class)->post($document);
        app(StockDocumentService::class)->cancel($document->fresh(), reason: 'wrong line');

        // Reversal is not blocked by the ownership guard: it undoes a booking
        // that was made before the settlement became owed, or a wrong one.
        $this->assertSame(StockDocument::STATUS_CANCELLED, $document->fresh()->status);
        $this->assertEqualsWithDelta($before, $this->stock($this->legacy), 0.0001);
    }

    // ── 5. the ERP cannot overwrite a pending pilot balance ──────────────────

    public function test_stock_sync_refuses_to_overwrite_a_material_with_a_pending_settlement(): void
    {
        $this->startedOrder();

        $allocation = MaterialAllocation::where('material_id', $this->pilot->id)->firstOrFail();
        $this->assertSame(MaterialSettlementPolicy::STATE_PENDING, $allocation->settlement_state);

        $before = $this->stock($this->pilot);

        $result = app(MaterialSyncService::class)->importFromExternalSystem('erp', [[
            'external_code' => 'ERP-F18',
            'name' => 'Formatka 18 mm (ERP)',
            'stock_quantity' => 55,
        ]]);

        $this->assertSame(0, $result['updated']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('FORMATKA-18', (string) $result['errors'][0]['error']);
        $this->assertSame('ERP-F18', $result['errors'][0]['external_code']);

        // Not a single column of that row was written.
        $this->assertEqualsWithDelta($before, $this->stock($this->pilot), 0.0001);
        $this->assertSame('Formatka 18 mm', $this->pilot->fresh()->name);
    }

    public function test_stock_sync_still_updates_a_material_without_a_pending_settlement(): void
    {
        $this->startedOrder();

        $result = app(MaterialSyncService::class)->importFromExternalSystem('erp', [[
            'external_code' => 'ERP-KLEJ',
            'name' => 'Klej (ERP)',
            'stock_quantity' => 55,
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertEqualsWithDelta(55.0, $this->stock($this->legacy), 0.0001);
    }

    // ── 6. a configuration file cannot overwrite the pending balance ─────────

    public function test_configuration_import_is_refused_when_it_overwrites_a_pending_material(): void
    {
        $this->startedOrder();

        $before = $this->stock($this->pilot);

        try {
            app(ConfigurationImporter::class)->import([
                'materials' => [
                    ['code' => 'FORMATKA-18', 'name' => 'ZMIENIONA', 'stock_quantity' => 1],
                    ['code' => 'KLEJ', 'name' => 'TEZ-ZMIENIONY', 'stock_quantity' => 1],
                ],
            ]);
            $this->fail('Importing a file that overwrites a pending balance should have been refused.');
        } catch (MaterialSettlementOwnershipException $e) {
            $this->assertStringContainsString('FORMATKA-18', $e->getMessage());
        }

        // The whole transaction is rolled back — not just the pilot row.
        $this->assertEqualsWithDelta($before, $this->stock($this->pilot), 0.0001);
        $this->assertSame('Formatka 18 mm', $this->pilot->fresh()->name);
        $this->assertSame('Klej', $this->legacy->fresh()->name);
        $this->assertEqualsWithDelta(90.0, $this->stock($this->legacy), 0.0001);
    }

    /** A balance can also be reserved-only: it is still a balance the file writes. */
    public function test_configuration_import_is_refused_when_it_overwrites_only_the_reserved_balance(): void
    {
        $this->startedOrder();

        $this->expectException(MaterialSettlementOwnershipException::class);

        app(ConfigurationImporter::class)->import([
            'materials' => [
                ['code' => 'FORMATKA-18', 'name' => 'ZMIENIONA', 'reserved_quantity' => 5],
            ],
        ]);
    }

    // ── 7. a file that carries no balance is safe ────────────────────────────

    public function test_configuration_import_renames_a_pending_material_without_touching_its_stock(): void
    {
        $this->startedOrder();

        $before = $this->stock($this->pilot);

        $written = app(ConfigurationImporter::class)->import([
            'materials' => [
                ['code' => 'FORMATKA-18', 'name' => 'Formatka 18 mm BIS'],
            ],
        ]);

        // A name is not a balance: the row imports, the withdrawal still stands.
        $this->assertSame(1, $written);
        $this->assertSame('Formatka 18 mm BIS', $this->pilot->fresh()->name);
        $this->assertEqualsWithDelta($before, $this->stock($this->pilot), 0.0001);
        $this->assertEqualsWithDelta(90.0, $this->stock($this->pilot), 0.0001);
    }

    public function test_configuration_import_still_overwrites_a_legacy_material_balance(): void
    {
        $this->startedOrder();

        $written = app(ConfigurationImporter::class)->import([
            'materials' => [
                ['code' => 'FORMATKA-18', 'name' => 'Formatka 18 mm BIS'],
                ['code' => 'KLEJ', 'name' => 'Klej BIS', 'stock_quantity' => 42],
            ],
        ]);

        // Control: only the pilot material is protected; the legacy row books.
        $this->assertSame(2, $written);
        $this->assertSame('Formatka 18 mm BIS', $this->pilot->fresh()->name);
        $this->assertEqualsWithDelta(90.0, $this->stock($this->pilot), 0.0001);
        $this->assertSame('Klej BIS', $this->legacy->fresh()->name);
        $this->assertEqualsWithDelta(42.0, $this->stock($this->legacy), 0.0001);
    }
}
