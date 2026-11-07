<?php

namespace Tests\Feature\Api\V1\Erp;

use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionExportPackingTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    private Line $line;

    private ProductType $product;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Tenant::create(['name' => 'Primary'])->id;

        app(TenantContext::class)->set($this->tenantId);

        $this->line = Line::factory()->create(['code' => 'LX']);
        $this->product = ProductType::factory()->create(['code' => 'PX']);

        [, $plaintext] = ApiKey::issue([
            'name' => 'ERP packing export key',
            'scopes' => [ApiScope::ProductionRead->value],
            'tenant_id' => $this->tenantId,
        ]);

        $this->apiKey = $plaintext;
    }

    private function workOrder(array $overrides = []): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'tenant_id' => $this->tenantId,
            'line_id' => $this->line->id,
            'product_type_id' => $this->product->id,
        ], $overrides));
    }

    private function batch(WorkOrder $workOrder): Batch
    {
        return Batch::factory()->create(['work_order_id' => $workOrder->id]);
    }

    private function packingStep(Batch $batch, int $number, string $status, array $overrides = []): BatchStep
    {
        return BatchStep::factory()->create(array_merge([
            'batch_id' => $batch->id,
            'step_number' => $number,
            'kind' => TemplateStep::KIND_PACKING,
            'status' => $status,
        ], $overrides));
    }

    private function completionsRow(string $orderNo): array
    {
        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson('/api/v1/erp/production/completions');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('order_no', $orderNo);

        $this->assertNotNull($row, "Order {$orderNo} is missing from the completions export.");

        return $row;
    }

    private function showRow(WorkOrder $workOrder): array
    {
        return $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson("/api/v1/erp/work-orders/{$workOrder->id}")
            ->assertOk()
            ->json('data');
    }

    public function test_an_open_packing_step_is_reported(): void
    {
        $workOrder = $this->workOrder([
            'order_no' => 'WO-PACK-OPEN',
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);
        $step = $this->packingStep($this->batch($workOrder), 1, BatchStep::STATUS_PENDING);

        $row = $this->completionsRow('WO-PACK-OPEN');

        $this->assertTrue($row['has_packing_step']);
        $this->assertSame($step->id, $row['packing_step_id']);
        $this->assertNull($row['packing_completed_at']);
        $this->assertGreaterThanOrEqual(1, $row['packing_steps_open']);
    }

    public function test_a_done_packing_step_reports_completion(): void
    {
        $workOrder = $this->workOrder([
            'order_no' => 'WO-PACK-DONE',
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);
        $step = $this->packingStep($this->batch($workOrder), 1, BatchStep::STATUS_PENDING);

        $step->update([
            'status' => BatchStep::STATUS_DONE,
            'completed_at' => now()->subMinutes(5),
        ]);

        $row = $this->completionsRow('WO-PACK-DONE');

        $this->assertNotNull($row['packing_completed_at']);
        $this->assertSame(0, $row['packing_steps_open']);
        $this->assertSame($step->fresh()->completed_at->toIso8601String(), $row['packing_completed_at']);
    }

    public function test_an_order_without_a_packing_step_reports_empty_packing_fields(): void
    {
        $this->workOrder([
            'order_no' => 'WO-NO-PACK',
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);

        $row = $this->completionsRow('WO-NO-PACK');

        $this->assertFalse($row['has_packing_step']);
        $this->assertNull($row['packing_step_id']);
        $this->assertNull($row['packing_completed_at']);
        $this->assertSame(0, $row['packing_steps_open']);
    }

    public function test_a_skipped_packing_step_counts_as_closed(): void
    {
        $workOrder = $this->workOrder([
            'order_no' => 'WO-PACK-SKIP',
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);
        $batch = $this->batch($workOrder);

        $this->packingStep($batch, 1, BatchStep::STATUS_DONE, ['completed_at' => now()->subHour()]);
        $skipped = $this->packingStep($batch, 2, BatchStep::STATUS_SKIPPED, ['completed_at' => now()->subMinute()]);

        $row = $this->completionsRow('WO-PACK-SKIP');

        $this->assertTrue($row['has_packing_step']);
        $this->assertSame(0, $row['packing_steps_open']);
        $this->assertSame($skipped->completed_at->toIso8601String(), $row['packing_completed_at']);
    }

    public function test_wc_order_number_is_exported_without_touching_customer_order_no(): void
    {
        $workOrder = $this->workOrder([
            'order_no' => 'WO-WC',
            'customer_order_no' => 'CO-777',
            'extra_data' => ['wc_order_number' => '847'],
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);

        $row = $this->showRow($workOrder);

        $this->assertSame('847', $row['wc_order_number']);
        $this->assertSame('CO-777', $row['customer_order_no']);
    }

    public function test_show_returns_the_same_packing_fields_as_completions(): void
    {
        $workOrder = $this->workOrder([
            'order_no' => 'WO-PACK-SHOW',
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
            'extra_data' => ['wc_order_number' => '9001'],
        ]);
        $this->packingStep($this->batch($workOrder), 1, BatchStep::STATUS_PENDING);

        $completions = $this->completionsRow('WO-PACK-SHOW');
        $show = $this->showRow($workOrder);

        foreach (['has_packing_step', 'packing_step_id', 'packing_completed_at', 'packing_steps_open', 'wc_order_number'] as $key) {
            $this->assertSame($completions[$key], $show[$key], "Field {$key} differs between completions and show.");
        }
    }

    public function test_multiple_packing_steps_pick_the_current_one_and_complete_only_when_all_are_closed(): void
    {
        $workOrder = $this->workOrder([
            'order_no' => 'WO-PACK-MULTI',
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);
        $batch = $this->batch($workOrder);

        $this->packingStep($batch, 1, BatchStep::STATUS_DONE, ['completed_at' => now()->subHour()]);
        $current = $this->packingStep($batch, 2, BatchStep::STATUS_IN_PROGRESS);
        $last = $this->packingStep($batch, 3, BatchStep::STATUS_PENDING);

        $row = $this->completionsRow('WO-PACK-MULTI');

        $this->assertSame($current->id, $row['packing_step_id']);
        $this->assertNull($row['packing_completed_at']);
        $this->assertSame(2, $row['packing_steps_open']);

        $current->update(['status' => BatchStep::STATUS_DONE, 'completed_at' => now()->subMinute()]);
        $last->update(['status' => BatchStep::STATUS_DONE, 'completed_at' => now()]);

        $row = $this->completionsRow('WO-PACK-MULTI');

        $this->assertSame($last->id, $row['packing_step_id']);
        $this->assertSame(0, $row['packing_steps_open']);
        $this->assertSame($last->fresh()->completed_at->toIso8601String(), $row['packing_completed_at']);
    }
}
