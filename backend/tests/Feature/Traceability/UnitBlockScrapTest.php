<?php

namespace Tests\Feature\Traceability;

use App\Models\Line;
use App\Models\Pallet;
use App\Models\ProductType;
use App\Models\ScrapReason;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Packaging\UnitPackingService;
use App\Services\Traceability\BindingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Block, unblock and scrap keep the same promises: role-gated at the API,
 * recorded in the unit's history, and reflected in the quality of the pallet
 * the unit sits on. A scrapped unit stays on its pallet/carton (the record is
 * not rewritten); only the pallet's quality gate changes.
 */
class UnitBlockScrapTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    private User $packingWorker;

    private ScrapReason $reason;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Operator', 'Supervisor', 'Admin', 'PackingWorker'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
        $this->packingWorker = User::factory()->create();
        $this->packingWorker->assignRole('PackingWorker');

        $this->reason = ScrapReason::factory()->create([
            'code' => 'NC-01',
            'name' => 'Cosmetic defect',
            'is_active' => true,
        ]);

        $this->wo = WorkOrder::factory()->create([
            'line_id' => Line::factory(),
            'product_type_id' => ProductType::factory(),
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
    }

    private function unit(array $attributes = []): SerialUnit
    {
        return SerialUnit::create($attributes + [
            'serial_no' => 'SN-'.uniqid(),
            'psn' => 'P-'.uniqid(),
            'work_order_id' => $this->wo->id,
        ]);
    }

    private function scrap(SerialUnit $unit, ?User $user = null, string $reason = 'Broken beyond repair')
    {
        return $this->actingAs($user ?? $this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/scrap", ['reason' => $reason]);
    }

    private function block(SerialUnit $unit, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/block", [
                'scrap_reason_id' => $this->reason->id,
                'note' => 'Scratch on the cover',
            ]);
    }

    private function unblock(SerialUnit $unit, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->supervisor)
            ->postJson("/api/v1/serial-units/{$unit->id}/unblock", ['note' => 'Rework done']);
    }

    private function expectBindingException(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected a BindingException.');
        } catch (BindingException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_an_operator_scraps_a_unit_and_the_reason_lands_in_history(): void
    {
        $unit = $this->unit();

        $this->scrap($unit, reason: 'Cracked frame')->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_SCRAPPED);

        $unit->refresh();
        $this->assertSame(SerialUnit::STATUS_SCRAPPED, $unit->status);

        $event = $unit->history()->where('parameters->event', 'scrapped')->first();
        $this->assertNotNull($event);
        $this->assertSame('scrapped', $event->parameters['event']);
        $this->assertSame('fail', $event->result);
        $this->assertSame('Cracked frame', $event->notes);
    }

    public function test_a_second_scrap_is_a_no_op_with_no_duplicate_history(): void
    {
        $unit = $this->unit();

        $this->scrap($unit)->assertOk();
        $this->scrap($unit)->assertOk();

        $this->assertSame(1, $unit->history()->where('parameters->event', 'scrapped')->count());
    }

    public function test_a_shipped_unit_cannot_be_scrapped(): void
    {
        $unit = $this->unit(['status' => SerialUnit::STATUS_SHIPPED]);

        $this->scrap($unit)->assertStatus(422);

        $this->assertSame(SerialUnit::STATUS_SHIPPED, $unit->fresh()->status);
        $this->assertSame(0, $unit->history()->where('parameters->event', 'scrapped')->count());
    }

    public function test_a_packing_worker_may_not_scrap(): void
    {
        $unit = $this->unit();

        $this->scrap($unit, $this->packingWorker)->assertForbidden();

        $this->assertSame(SerialUnit::STATUS_IN_PRODUCTION, $unit->fresh()->status);
    }

    public function test_packing_accepts_only_units_neither_blocked_nor_scrapped(): void
    {
        $unit = $this->unit();
        $packing = app(UnitPackingService::class);

        $this->block($unit)->assertOk();
        $this->expectBindingException(fn () => $packing->assertPackable($unit->fresh()));

        $this->unblock($unit)->assertOk();
        $packing->assertPackable($unit->fresh());
        $this->addToAssertionCount(1);

        $this->scrap($unit)->assertOk();
        $this->expectBindingException(fn () => $packing->assertPackable($unit->fresh()));
    }

    public function test_a_unit_on_a_pallet_moves_the_pallet_quality_but_is_not_taken_off_it(): void
    {
        $pallet = Pallet::factory()->create(['work_order_id' => $this->wo->id]);
        $unit = $this->unit(['pallet_id' => $pallet->id]);
        $this->assertNull($unit->carton_id);

        $this->block($unit)->assertOk();
        $this->assertSame(Pallet::QUALITY_FAIL, $pallet->fresh()->quality_status);

        $this->unblock($unit)->assertOk();
        $this->assertSame(Pallet::QUALITY_PENDING, $pallet->fresh()->quality_status);

        $this->scrap($unit)->assertOk();
        $this->assertSame(Pallet::QUALITY_PENDING, $pallet->fresh()->quality_status);

        $unit->refresh();
        $this->assertSame($pallet->id, $unit->pallet_id);
        $this->assertNull($unit->carton_id);
    }

    public function test_a_packing_worker_may_not_block(): void
    {
        $unit = $this->unit();

        $this->block($unit, $this->packingWorker)->assertForbidden();

        $this->assertSame(SerialUnit::STATUS_IN_PRODUCTION, $unit->fresh()->status);
    }
}
