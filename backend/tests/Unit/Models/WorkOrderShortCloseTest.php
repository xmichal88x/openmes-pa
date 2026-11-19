<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderShortCloseTest extends TestCase
{
    use RefreshDatabase;

    private function workOrder(float $planned, float $produced, string $status = WorkOrder::STATUS_IN_PROGRESS): WorkOrder
    {
        return WorkOrder::factory()->create([
            'planned_qty' => $planned,
            'produced_qty' => $produced,
            'status' => $status,
        ]);
    }

    // ── shortageQty() ────────────────────────────────────────────────────────

    public function test_shortage_qty_is_planned_minus_produced(): void
    {
        $this->assertSame(3.0, $this->workOrder(5, 2)->shortageQty());
    }

    public function test_shortage_qty_is_zero_when_production_meets_or_exceeds_plan(): void
    {
        $this->assertSame(0.0, $this->workOrder(2, 2)->shortageQty());
        $this->assertSame(0.0, $this->workOrder(2, 3)->shortageQty());
    }

    // ── isShortClosed() ──────────────────────────────────────────────────────

    public function test_is_short_closed_is_false_for_a_fresh_order(): void
    {
        $this->assertFalse($this->workOrder(5, 2)->isShortClosed());
    }

    public function test_is_short_closed_is_true_once_the_timestamp_is_set(): void
    {
        $wo = $this->workOrder(5, 2);
        $wo->update(['short_closed_at' => now()]);

        $this->assertTrue($wo->fresh()->isShortClosed());
    }

    // ── canCloseShort() ──────────────────────────────────────────────────────

    public function test_can_close_short_when_production_fell_short(): void
    {
        $this->assertTrue($this->workOrder(5, 2)->canCloseShort());
    }

    public function test_cannot_close_short_when_plan_is_met(): void
    {
        $this->assertFalse($this->workOrder(2, 2)->canCloseShort());
    }

    public function test_cannot_close_short_for_cancelled_or_rejected_orders(): void
    {
        $this->assertFalse($this->workOrder(5, 2, WorkOrder::STATUS_CANCELLED)->canCloseShort());
        $this->assertFalse($this->workOrder(5, 2, WorkOrder::STATUS_REJECTED)->canCloseShort());
    }

    public function test_cannot_close_short_an_already_short_closed_order(): void
    {
        $wo = $this->workOrder(5, 2);
        $wo->update(['short_closed_at' => now()]);

        $this->assertFalse($wo->fresh()->canCloseShort());
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    public function test_short_close_fields_are_fillable_and_the_user_relation_resolves(): void
    {
        $user = User::factory()->create();
        $wo = $this->workOrder(5, 2);

        $wo->update([
            'short_closed_at' => now(),
            'short_closed_by_id' => $user->id,
            'short_close_reason' => 'test',
        ]);

        $wo->refresh();

        $this->assertNotNull($wo->short_closed_at);
        $this->assertSame('test', $wo->short_close_reason);
        $this->assertTrue($wo->shortClosedBy->is($user));
    }
}
