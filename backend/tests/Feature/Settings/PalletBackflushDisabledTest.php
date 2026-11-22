<?php

namespace Tests\Feature\Settings;

use App\Exceptions\PalletBackflushDisabledException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-265 (B2): the pallet backflush switch is retired, so the admin panel
 * must reject `true` outright and report the effective (always OFF) state.
 */
class PalletBackflushDisabledTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'backflush_on_pallet_creation';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

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

    private function store(string $key, mixed $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()],
        );
    }

    private function stored(string $key): mixed
    {
        return json_decode(DB::table('system_settings')->where('key', $key)->value('value'), true);
    }

    public function test_json_request_cannot_switch_the_retired_switch_on(): void
    {
        $this->store(self::KEY, true);
        $this->store('workflow_mode', 'board_status');

        $this->actingAs($this->admin)
            ->postJson('/settings/system', $this->payload([self::KEY => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(self::KEY);

        // Nothing from the rejected request was written - not the switch, and
        // not the unrelated field that travelled with it.
        $this->assertTrue($this->stored(self::KEY));
        $this->assertSame('board_status', $this->stored('workflow_mode'));
    }

    public function test_form_request_cannot_switch_the_retired_switch_on(): void
    {
        $this->store(self::KEY, true);

        $this->actingAs($this->admin)
            ->from('/settings/system')
            ->post('/settings/system', $this->payload([self::KEY => true]))
            ->assertRedirect('/settings/system')
            ->assertSessionHasErrors(self::KEY);

        $this->assertTrue($this->stored(self::KEY));
    }

    public function test_false_is_accepted_and_stored_alongside_the_rest_of_the_form(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/system', $this->payload([
                self::KEY => false,
                'workflow_mode' => 'board_status',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->stored(self::KEY));
        $this->assertSame('board_status', $this->stored('workflow_mode'));
    }

    public function test_a_form_without_the_field_leaves_the_stored_value_alone(): void
    {
        $this->store(self::KEY, true);

        $this->actingAs($this->admin)
            ->post('/settings/system', $this->payload(['workflow_mode' => 'board_status']))
            ->assertSessionHasNoErrors();

        // Saving another tab must not quietly rewrite the stored value.
        $this->assertTrue($this->stored(self::KEY));
        $this->assertSame('board_status', $this->stored('workflow_mode'));
    }

    public function test_the_page_reports_the_effective_state_not_the_stored_one(): void
    {
        $this->store(self::KEY, true);

        $this->actingAs($this->admin)
            ->get('/settings/system')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.backflush_on_pallet_creation', false)
                ->where('settings.backflush_on_pallet_creation_stored', true)
                ->where('settings.material_backflush.status', 'disabled')
                ->where('settings.material_backflush.code', PalletBackflushDisabledException::CODE));
    }

    public function test_the_page_reports_null_when_nothing_is_stored(): void
    {
        DB::table('system_settings')->where('key', self::KEY)->delete();

        $this->actingAs($this->admin)
            ->get('/settings/system')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.backflush_on_pallet_creation', false)
                ->where('settings.backflush_on_pallet_creation_stored', null)
                ->where('settings.material_backflush.status', 'disabled'));
    }
}
