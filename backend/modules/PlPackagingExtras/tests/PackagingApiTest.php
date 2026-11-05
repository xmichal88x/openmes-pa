<?php

namespace Modules\PlPackagingExtras\tests;

use App\Models\Issue;
use App\Models\Line;
use App\Models\PackagingBox;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlPackagingExtras\Providers\PlPackagingExtrasServiceProvider;
use Tests\TestCase;

/**
 * P1/T1.5 — feature coverage for the packaging API.
 *
 * The expected-code / scan / start / complete contract is exercised through
 * HTTP exactly as the station consumes it, including the shared web scan guard
 * (F8) and the packability rules from the packing plan (§7 P1).
 */
class PackagingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $operator;

    private User $noRole;

    private Line $packingLine;

    protected function setUp(): void
    {
        parent::setUp();

        // Module providers boot only when enabled in system_settings; the test
        // database is empty, so register the module's provider explicitly.
        $this->app->register(PlPackagingExtrasServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->noRole = User::factory()->create();

        $this->packingLine = Line::firstOrCreate(
            ['code' => 'PACKING'],
            ['name' => 'Pakowanie', 'is_active' => true]
        );

        $this->operator->lines()->attach($this->packingLine->id);
    }

    private function makeWorkOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'line_id' => $this->packingLine->id,
        ], $attributes));
    }

    private function addCode(WorkOrder $workOrder, string $ean, string $state = WorkOrderEan::STATE_EXPECTED): WorkOrderEan
    {
        return WorkOrderEan::create([
            'work_order_id' => $workOrder->id,
            'ean' => $ean,
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => $state,
        ]);
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, array{code: string, part_seq: int}>
     */
    private function codePayload(array $codes): array
    {
        $payload = [];
        foreach (array_values($codes) as $index => $code) {
            $payload[] = ['code' => $code, 'part_seq' => $index + 1];
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function codePayloadWithMetadata(array $codes): array
    {
        $payload = $this->codePayload($codes);
        $payload[0] = array_merge($payload[0], [
            'part_name' => 'Side panel',
            'material' => 'MDF 18',
            'width_mm' => 720.5,
            'height_mm' => 300,
            'thickness_mm' => 18,
            'sheet_name' => 'SHEET-A1',
        ]);

        return $payload;
    }

    private function box(WorkOrder $workOrder, string $status = PackagingBox::STATUS_OPEN): PackagingBox
    {
        return PackagingBox::create([
            'work_order_id' => $workOrder->id,
            'box_no' => 1,
            'box_id' => 'BOX-'.$workOrder->order_no.'-1',
            'part_count' => 0,
            'status' => $status,
        ]);
    }

    // ── Expected codes ──────────────────────────────────────────────────────

    public function test_put_expected_codes_creates_three_part_labels(): void
    {
        $wo = $this->makeWorkOrder();

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayload(['LBL-1', 'LBL-2', 'LBL-3']),
            ])
            ->assertOk()
            ->assertJsonPath('data.expected', 3)
            ->assertJsonCount(3, 'data.codes');

        $this->assertDatabaseCount('work_order_eans', 3);
        $this->assertDatabaseHas('work_order_eans', [
            'work_order_id' => $wo->id,
            'ean' => 'LBL-1',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_EXPECTED,
        ]);
    }

    public function test_put_expected_codes_stores_part_metadata_from_the_payload(): void
    {
        $wo = $this->makeWorkOrder();

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayloadWithMetadata(['LBL-META-1']),
            ])
            ->assertOk()
            ->assertJsonPath('data.codes.0.part_name', 'Side panel')
            ->assertJsonPath('data.codes.0.material', 'MDF 18')
            ->assertJsonPath('data.codes.0.width_mm', 720.5)
            ->assertJsonPath('data.codes.0.height_mm', 300)
            ->assertJsonPath('data.codes.0.thickness_mm', 18)
            ->assertJsonPath('data.codes.0.sheet_name', 'SHEET-A1');

        $this->assertDatabaseHas('work_order_eans', [
            'work_order_id' => $wo->id,
            'ean' => 'LBL-META-1',
            'part_name' => 'Side panel',
            'material' => 'MDF 18',
            'sheet_name' => 'SHEET-A1',
        ]);
    }

    public function test_put_expected_codes_keeps_metadata_when_an_older_payload_omits_it(): void
    {
        $wo = $this->makeWorkOrder();

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayloadWithMetadata(['LBL-META-2']),
            ])
            ->assertOk();

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayload(['LBL-META-2']),
            ])
            ->assertOk();

        $this->assertDatabaseHas('work_order_eans', [
            'ean' => 'LBL-META-2',
            'part_name' => 'Side panel',
            'sheet_name' => 'SHEET-A1',
        ]);
    }

    public function test_put_expected_codes_is_idempotent_and_voids_removed_codes_only(): void
    {
        $wo = $this->makeWorkOrder();

        $all = ['LBL-1', 'LBL-2', 'LBL-3'];

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", ['codes' => $this->codePayload($all)])
            ->assertOk()
            ->assertJsonPath('data.expected', 3);

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", ['codes' => $this->codePayload($all)])
            ->assertOk()
            ->assertJsonPath('data.expected', 3);

        $this->assertDatabaseCount('work_order_eans', 3);
        $this->assertSame(
            3,
            WorkOrderEan::where('work_order_id', $wo->id)->where('state', WorkOrderEan::STATE_EXPECTED)->count()
        );

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayload(['LBL-1', 'LBL-2']),
            ])
            ->assertOk()
            ->assertJsonPath('data.expected', 2)
            ->assertJsonPath('data.voided', 1);

        $this->assertDatabaseHas('work_order_eans', [
            'work_order_id' => $wo->id,
            'ean' => 'LBL-3',
            'state' => WorkOrderEan::STATE_VOID,
        ]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", ['codes' => $this->codePayload($all)])
            ->assertOk()
            ->assertJsonPath('data.expected', 3);

        $this->assertDatabaseHas('work_order_eans', [
            'work_order_id' => $wo->id,
            'ean' => 'LBL-3',
            'state' => WorkOrderEan::STATE_EXPECTED,
        ]);

        $legacy = WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LEGACY-EAN',
            'code_type' => WorkOrderEan::CODE_TYPE_EAN,
            'state' => WorkOrderEan::STATE_EXPECTED,
        ]);

        // Removing a part label must never void a legacy EAN code.
        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayload(['LBL-1', 'LBL-2']),
            ])
            ->assertOk();

        $this->assertSame(WorkOrderEan::STATE_EXPECTED, $legacy->fresh()->state);
        $this->assertSame(WorkOrderEan::CODE_TYPE_EAN, $legacy->fresh()->code_type);
        $this->assertDatabaseHas('work_order_eans', [
            'work_order_id' => $wo->id,
            'ean' => 'LBL-3',
            'state' => WorkOrderEan::STATE_VOID,
        ]);
    }

    public function test_put_expected_codes_rejects_code_owned_by_another_work_order(): void
    {
        $wo = $this->makeWorkOrder();
        $other = $this->makeWorkOrder();
        $foreign = $this->addCode($other, 'FOREIGN-1');

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayload(['FOREIGN-1']),
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('work_order_eans', 1);
        $this->assertSame(WorkOrderEan::STATE_EXPECTED, $foreign->fresh()->state);
        $this->assertSame($other->id, $foreign->fresh()->work_order_id);
    }

    public function test_put_expected_codes_rejects_duplicate_codes_in_payload(): void
    {
        $wo = $this->makeWorkOrder();

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/v1/packaging/expected-codes/{$wo->id}", [
                'codes' => $this->codePayload(['LBL-DUP', 'LBL-DUP']),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('work_order_eans', 0);
    }

    // ── Scan ────────────────────────────────────────────────────────────────

    public function test_scan_valid_code_marks_it_scanned_and_increments_packed_qty(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $code = $this->addCode($wo, 'LBL-SCAN-1');
        $this->addCode($wo, 'LBL-SCAN-2');
        $this->addCode($wo, 'LBL-SCAN-3');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-SCAN-1'])
            ->assertOk()
            ->assertJsonPath('data.work_order.packed_qty', 1)
            ->assertJsonPath('data.remaining', 2);

        $this->assertSame(1, (int) $wo->fresh()->packed_qty);
        $this->assertSame(WorkOrderEan::STATE_SCANNED, $code->fresh()->state);
        $this->assertDatabaseHas('packaging_scan_logs', [
            'work_order_id' => $wo->id,
            'ean' => 'LBL-SCAN-1',
            'user_id' => $this->operator->id,
        ]);
        $this->assertDatabaseCount('packaging_scan_logs', 1);
    }

    public function test_scan_same_code_twice_returns_conflict_without_double_packing(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $this->addCode($wo, 'LBL-DUP-SCAN');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-DUP-SCAN'])
            ->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-DUP-SCAN'])
            ->assertStatus(409);

        $this->assertSame(1, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 1);
    }

    public function test_scan_code_from_another_work_order_returns_conflict(): void
    {
        $target = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $other = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $this->addCode($other, 'LBL-FOREIGN');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', [
                'ean' => 'LBL-FOREIGN',
                'work_order_id' => $target->id,
            ])
            ->assertStatus(409);

        $this->assertSame(0, (int) $target->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_scan_voided_code_returns_conflict(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $this->addCode($wo, 'LBL-VOID', WorkOrderEan::STATE_VOID);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-VOID'])
            ->assertStatus(409);

        $this->assertSame(0, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_api_scan_assigns_the_code_to_the_requested_box(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $box = $this->box($wo);
        $code = $this->addCode($wo, 'LBL-BOX-1');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-BOX-1', 'box_id' => $box->box_id])
            ->assertOk()
            ->assertJsonPath('data.scan.box_id', $box->box_id);

        $this->assertSame($box->id, $code->fresh()->packaging_box_id);
        $this->assertSame(1, $box->fresh()->part_count);
        $this->assertDatabaseHas('packaging_scan_logs', [
            'ean' => 'LBL-BOX-1',
            'packaging_box_id' => $box->id,
        ]);
    }

    public function test_api_scan_resolves_a_numeric_box_id(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $box = $this->box($wo);
        $code = $this->addCode($wo, 'LBL-BOX-NUM');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-BOX-NUM', 'box_id' => $box->id])
            ->assertOk()
            ->assertJsonPath('data.scan.box_id', $box->box_id);

        $this->assertSame($box->id, $code->fresh()->packaging_box_id);
    }

    public function test_api_scan_refuses_a_closed_box(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $box = $this->box($wo, PackagingBox::STATUS_CLOSED);
        $this->addCode($wo, 'LBL-BOX-CLOSED');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-BOX-CLOSED', 'box_id' => $box->box_id])
            ->assertStatus(409);

        $this->assertSame(0, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_api_scan_refuses_a_box_from_another_work_order(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $other = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $foreignBox = $this->box($other);
        $this->addCode($wo, 'LBL-BOX-FOREIGN');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-BOX-FOREIGN', 'box_id' => $foreignBox->box_id])
            ->assertStatus(409);

        $this->assertSame(0, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_api_scan_refuses_a_box_on_another_line(): void
    {
        $otherLine = Line::factory()->create(['code' => 'OTHER', 'is_active' => true]);
        $wo = $this->makeWorkOrder([
            'line_id' => $otherLine->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 3,
        ]);
        $box = $this->box($wo);
        $this->addCode($wo, 'LBL-BOX-LINE');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-BOX-LINE', 'box_id' => $box->box_id])
            ->assertForbidden();

        $this->assertSame(0, (int) $wo->fresh()->packed_qty);
    }

    // ── Start ───────────────────────────────────────────────────────────────

    public function test_start_moves_pending_to_in_progress_once_and_rejects_done(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_PENDING]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/work-orders/{$wo->id}/start")
            ->assertOk();

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/work-orders/{$wo->id}/start")
            ->assertOk();

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);

        $done = $this->makeWorkOrder(['status' => WorkOrder::STATUS_DONE]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/work-orders/{$done->id}/start")
            ->assertStatus(422);
    }

    // ── Complete ────────────────────────────────────────────────────────────

    public function test_complete_requires_full_pack_then_closes_work_order(): void
    {
        $wo = $this->makeWorkOrder([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 3,
            'packed_qty' => 2,
        ]);
        $this->addCode($wo, 'LBL-C-1');
        $this->addCode($wo, 'LBL-C-2');
        $this->addCode($wo, 'LBL-C-3');

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/complete', ['work_order_id' => $wo->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Work order is not fully packed (1 remaining)');

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->assertNull($wo->fresh()->completed_at);

        $wo->forceFill(['packed_qty' => 0])->save();

        foreach (['LBL-C-1', 'LBL-C-2', 'LBL-C-3'] as $ean) {
            $this->actingAs($this->supervisor, 'sanctum')
                ->postJson('/api/v1/packaging/scan', ['ean' => $ean])
                ->assertOk();
        }

        $this->assertSame(3, (int) $wo->fresh()->packed_qty);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/complete', ['work_order_id' => $wo->id])
            ->assertOk();

        $wo->refresh();
        $this->assertSame(WorkOrder::STATUS_DONE, $wo->status);
        $this->assertNotNull($wo->completed_at);
    }

    public function test_complete_blocks_open_issue_until_resolved(): void
    {
        $wo = $this->makeWorkOrder([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 1,
            'packed_qty' => 1,
        ]);

        $issue = Issue::factory()->create([
            'work_order_id' => $wo->id,
            'status' => Issue::STATUS_OPEN,
        ]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/complete', ['work_order_id' => $wo->id])
            ->assertStatus(422);

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);

        $issue->update(['status' => Issue::STATUS_RESOLVED]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/complete', ['work_order_id' => $wo->id])
            ->assertOk();

        $this->assertSame(WorkOrder::STATUS_DONE, $wo->fresh()->status);
    }

    // ── Authorization + web guard ───────────────────────────────────────────

    public function test_scan_requires_production_role(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $this->addCode($wo, 'LBL-NO-ROLE');

        $this->actingAs($this->noRole, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'LBL-NO-ROLE'])
            ->assertForbidden();

        $this->assertSame(0, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_web_scan_rejects_duplicate_scan_as_conflict(): void
    {
        $wo = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 5]);
        $this->addCode($wo, 'LBL-WEB-1');

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'LBL-WEB-1'])
            ->assertOk();

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'LBL-WEB-1'])
            ->assertStatus(409);

        $this->assertSame(1, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 1);
    }

    public function test_web_scan_rejects_code_from_pinned_work_order_as_conflict(): void
    {
        $target = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $other = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS, 'planned_qty' => 3]);
        $this->addCode($other, 'LBL-WEB-FOREIGN');

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', [
                'ean' => 'LBL-WEB-FOREIGN',
                'work_order_id' => $target->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Code belongs to a different work order');

        $this->assertSame(0, (int) $target->fresh()->packed_qty);
        $this->assertSame(0, (int) $other->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_items_exposes_expected_scanned_and_remaining_codes_without_voided(): void
    {
        $wo = $this->makeWorkOrder([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 4,
            'packed_qty' => 1,
        ]);
        $this->addCode($wo, 'LBL-IT-2');
        $this->addCode($wo, 'LBL-IT-1');
        $this->addCode($wo, 'LBL-IT-SCANNED', WorkOrderEan::STATE_SCANNED);
        $this->addCode($wo, 'LBL-IT-VOID', WorkOrderEan::STATE_VOID);

        $this->actingAs($this->operator)
            ->getJson('/packaging/items')
            ->assertOk()
            ->assertJsonPath('items.0.expected_count', 2)
            ->assertJsonPath('items.0.scanned_count', 1)
            ->assertJsonPath('items.0.voided_count', 1)
            ->assertJsonPath('items.0.remaining_codes', ['LBL-IT-1', 'LBL-IT-2'])
            ->assertJsonPath('items.0.eans', ['LBL-IT-1', 'LBL-IT-2', 'LBL-IT-SCANNED']);
    }

    // ── Atomic PACK work-order creation (P3/T3.2) ───────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function createPackPayload(string $orderNo = 'PACK-WC-EDE-9001', string $customerOrderNo = 'WC-EDE-9001'): array
    {
        return [
            'order_no' => $orderNo,
            'customer_order_no' => $customerOrderNo,
            'codes' => [
                ['code' => 'LBL-PACK-1', 'label_batch_id' => 'BATCH-1', 'part_seq' => 1],
                ['code' => 'LBL-PACK-2', 'label_batch_id' => 'BATCH-1', 'part_seq' => 2],
                ['code' => 'LBL-PACK-3', 'label_batch_id' => 'BATCH-1', 'part_seq' => 3],
            ],
        ];
    }

    public function test_create_work_order_atomically_creates_in_progress_pack_wo_with_codes(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $this->createPackPayload())
            ->assertCreated()
            ->assertJsonPath('data.work_order.order_no', 'PACK-WC-EDE-9001')
            ->assertJsonPath('data.work_order.customer_order_no', 'WC-EDE-9001')
            ->assertJsonPath('data.work_order.status', WorkOrder::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.work_order.line_code', 'PACKING')
            ->assertJsonPath('data.work_order.planned_qty', 3)
            ->assertJsonCount(3, 'data.codes')
            ->assertJsonPath('data.codes.0.state', WorkOrderEan::STATE_EXPECTED);

        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_eans', 3);

        $wo = WorkOrder::where('order_no', 'PACK-WC-EDE-9001')->firstOrFail();
        $this->assertSame($this->packingLine->id, $wo->line_id);
        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $wo->status);
        $this->assertNotSame(WorkOrder::STATUS_PENDING, $wo->status);
        $this->assertSame(3, (int) $wo->planned_qty);
        $this->assertTrue((bool) $wo->extra_data['packing_workflow']);
        $this->assertDatabaseMissing('work_orders', [
            'order_no' => 'PACK-WC-EDE-9001',
            'status' => WorkOrder::STATUS_PENDING,
        ]);
    }

    public function test_create_work_order_stores_part_metadata_from_the_payload(): void
    {
        $payload = $this->createPackPayload();
        $payload['codes'][0] = array_merge($payload['codes'][0], [
            'part_name' => 'Back panel',
            'material' => 'HDF 3',
            'width_mm' => 600,
            'height_mm' => 400,
            'thickness_mm' => 3,
            'sheet_name' => 'SHEET-B2',
        ]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.codes.0.part_name', 'Back panel')
            ->assertJsonPath('data.codes.0.sheet_name', 'SHEET-B2');

        $this->assertDatabaseHas('work_order_eans', [
            'ean' => 'LBL-PACK-1',
            'part_name' => 'Back panel',
            'material' => 'HDF 3',
            'width_mm' => 600,
            'height_mm' => 400,
            'thickness_mm' => 3,
            'sheet_name' => 'SHEET-B2',
        ]);
    }

    public function test_create_work_order_is_idempotent_for_same_payload(): void
    {
        $payload = $this->createPackPayload();

        $first = $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $payload)
            ->assertCreated();

        $workOrderId = $first->json('data.work_order.id');

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.work_order.id', $workOrderId)
            ->assertJsonPath('data.work_order.status', WorkOrder::STATUS_IN_PROGRESS)
            ->assertJsonCount(3, 'data.codes');

        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_eans', 3);
    }

    public function test_create_work_order_rejects_same_order_no_with_different_customer_order_no(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $this->createPackPayload())
            ->assertCreated();

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $this->createPackPayload('PACK-WC-EDE-9001', 'WC-EDE-9999'))
            ->assertStatus(409);

        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseHas('work_orders', ['customer_order_no' => 'WC-EDE-9001']);
    }

    public function test_create_work_order_rolls_back_when_code_belongs_to_another_work_order(): void
    {
        $other = $this->makeWorkOrder(['status' => WorkOrder::STATUS_IN_PROGRESS]);
        $this->addCode($other, 'LBL-FOREIGN-PACK');

        $payload = $this->createPackPayload();
        $payload['codes'][1]['code'] = 'LBL-FOREIGN-PACK';

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $payload)
            ->assertStatus(409);

        $this->assertDatabaseMissing('work_orders', ['order_no' => 'PACK-WC-EDE-9001']);
        $this->assertDatabaseCount('work_order_eans', 1);
        $this->assertDatabaseHas('work_order_eans', [
            'work_order_id' => $other->id,
            'ean' => 'LBL-FOREIGN-PACK',
        ]);
    }

    public function test_create_work_order_rejects_planned_qty_mismatch(): void
    {
        $payload = $this->createPackPayload();
        $payload['planned_qty'] = 2;

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $payload)
            ->assertStatus(422);

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_eans', 0);
    }

    public function test_create_work_order_rejects_duplicate_codes_in_payload(): void
    {
        $payload = $this->createPackPayload();
        $payload['codes'][2]['code'] = 'LBL-PACK-1';

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/packaging/work-orders', $payload)
            ->assertStatus(422);

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_eans', 0);
    }

    public function test_work_order_index_filters_by_customer_order_no(): void
    {
        $this->makeWorkOrder(['customer_order_no' => 'WC-EDE-7001']);
        $this->makeWorkOrder(['customer_order_no' => 'WC-EDE-7002']);

        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson('/api/v1/work-orders?customer_order_no=WC-EDE-7001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_order_no', 'WC-EDE-7001');
    }
}
