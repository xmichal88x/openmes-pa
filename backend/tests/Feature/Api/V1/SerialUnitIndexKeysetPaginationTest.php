<?php

namespace Tests\Feature\Api\V1;

use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The unit list is read while units are still being registered, so it is paged
 * by an id cursor and not by an offset: the portions have to tile the result
 * set exactly once even when rows appear between two requests.
 */
class SerialUnitIndexKeysetPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const UNITS_IN_FIRST_WO = 1205;

    private const UNITS_IN_SECOND_WO = 130;

    private User $admin;

    private WorkOrder $wo;

    private WorkOrder $otherWo;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->wo = WorkOrder::factory()->create();
        $this->otherWo = WorkOrder::factory()->create();

        SerialUnit::factory()->count(self::UNITS_IN_FIRST_WO)->create(['work_order_id' => $this->wo->id]);
        SerialUnit::factory()->count(self::UNITS_IN_SECOND_WO)->create(['work_order_id' => $this->otherWo->id]);
    }

    public function test_first_portion_reports_total_and_cursor(): void
    {
        $response = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 1000]);

        $response->assertOk();
        $this->assertCount(1000, $response->json('data'));
        $this->assertSame(1000, $response->json('meta.per_page'));
        $this->assertSame(self::UNITS_IN_FIRST_WO, $response->json('meta.total'));
        $this->assertTrue($response->json('meta.has_more'));

        $this->assertSame(
            $response->json('data.999.id'),
            $response->json('meta.next_before_id'),
            'the cursor is the id of the last row of the portion'
        );
    }

    public function test_second_portion_closes_the_list(): void
    {
        $first = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 1000]);

        $second = $this->getUnits([
            'work_order_id' => $this->wo->id,
            'per_page' => 1000,
            'before_id' => $first->json('meta.next_before_id'),
        ]);

        $second->assertOk();
        $this->assertCount(205, $second->json('data'));
        $this->assertSame(self::UNITS_IN_FIRST_WO, $second->json('meta.total'));
        $this->assertFalse($second->json('meta.has_more'));
        $this->assertNull($second->json('meta.next_before_id'));
    }

    public function test_portions_cover_each_unit_exactly_once(): void
    {
        $first = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 1000]);
        $second = $this->getUnits([
            'work_order_id' => $this->wo->id,
            'per_page' => 1000,
            'before_id' => $first->json('meta.next_before_id'),
        ]);

        $collected = array_merge($this->ids($first), $this->ids($second));
        $expected = SerialUnit::where('work_order_id', $this->wo->id)->pluck('id')->all();

        sort($collected);
        sort($expected);

        $this->assertCount(self::UNITS_IN_FIRST_WO, $collected);
        $this->assertCount(self::UNITS_IN_FIRST_WO, array_unique($collected));
        $this->assertSame($expected, $collected);
    }

    public function test_units_registered_during_the_sweep_do_not_shift_the_cursor(): void
    {
        $woId = $this->wo->id;
        $expected = SerialUnit::where('work_order_id', $woId)->pluck('id')->all();

        $first = $this->getUnits(['work_order_id' => $woId, 'per_page' => 1000]);

        // Operators keep registering units between the two requests: they get
        // ids above the cursor, so the next portion must not repeat or drop any
        // of the rows the sweep is collecting.
        $latecomers = SerialUnit::factory()->count(3)->create(['work_order_id' => $woId]);

        $second = $this->getUnits([
            'work_order_id' => $woId,
            'per_page' => 1000,
            'before_id' => $first->json('meta.next_before_id'),
        ]);

        $collected = array_merge($this->ids($first), $this->ids($second));

        sort($collected);
        sort($expected);

        $this->assertCount(205, $this->ids($second));
        $this->assertSame($expected, $collected, 'every unit present before the sweep stays, none appears twice');

        foreach ($latecomers as $latecomer) {
            $this->assertNotContains($latecomer->id, $collected, 'a unit above the cursor is not part of this sweep');
        }

        // total is the live count of the filtered set, not the remainder.
        $this->assertSame(self::UNITS_IN_FIRST_WO + 3, $second->json('meta.total'));
    }

    public function test_portion_before_the_oldest_unit_is_empty_and_closes_the_list(): void
    {
        $response = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 1000, 'before_id' => 1]);

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertFalse($response->json('meta.has_more'));
        $this->assertNull($response->json('meta.next_before_id'));
        $this->assertSame(self::UNITS_IN_FIRST_WO, $response->json('meta.total'));
    }

    public function test_work_order_filter_applies_to_the_cursor(): void
    {
        $first = $this->getUnits(['work_order_id' => $this->otherWo->id, 'per_page' => 100]);
        $second = $this->getUnits([
            'work_order_id' => $this->otherWo->id,
            'per_page' => 100,
            'before_id' => $first->json('meta.next_before_id'),
        ]);

        $collected = array_merge($this->ids($first), $this->ids($second));
        $expected = SerialUnit::where('work_order_id', $this->otherWo->id)->pluck('id')->all();

        $this->assertCount(100, $this->ids($first));
        $this->assertCount(30, $this->ids($second));
        $this->assertSame(self::UNITS_IN_SECOND_WO, $first->json('meta.total'));
        $this->assertSame(self::UNITS_IN_SECOND_WO, $second->json('meta.total'));
        $this->assertTrue($first->json('meta.has_more'));
        $this->assertFalse($second->json('meta.has_more'));
        $this->assertEqualsCanonicalizing($expected, $collected);
    }

    public function test_per_page_above_maximum_serves_one_thousand_rows(): void
    {
        $response = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 5000]);

        $response->assertOk();
        $this->assertCount(1000, $response->json('data'));
        $this->assertSame(1000, $response->json('meta.per_page'));
        $this->assertSame(self::UNITS_IN_FIRST_WO, $response->json('meta.total'));
        $this->assertTrue($response->json('meta.has_more'));
    }

    public function test_per_page_zero_serves_one_row(): void
    {
        $response = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 0]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.per_page'));
        $this->assertTrue($response->json('meta.has_more'));
        $this->assertSame($response->json('data.0.id'), $response->json('meta.next_before_id'));
    }

    public function test_rows_come_newest_first(): void
    {
        $response = $this->getUnits(['work_order_id' => $this->wo->id, 'per_page' => 5]);

        $ids = $this->ids($response);
        $sorted = $ids;
        rsort($sorted);

        $this->assertSame($sorted, $ids);
    }

    public function test_malformed_before_id_is_rejected(): void
    {
        $response = $this->getUnits(['work_order_id' => $this->wo->id, 'before_id' => 'abc']);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.before_id.0', 'before_id must be a positive integer id');
    }

    /** @param  array<string, mixed>  $query */
    private function getUnits(array $query): TestResponse
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/serial-units?'.http_build_query($query));
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return array_map('intval', array_column($response->json('data'), 'id'));
    }
}
