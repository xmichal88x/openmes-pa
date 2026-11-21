<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Settings\RuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P2.4 — `allow_overproduction` is a system setting (Settings -> System), not a
 * deployment flag. The stored value is the source of truth; ALLOW_OVERPRODUCTION
 * only supplies the declared default when nothing is stored, and every answer
 * reports which of the two is in charge (`database` or `env`).
 */
class OverproductionSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        $this->workOrder = WorkOrder::factory()->create(['planned_qty' => 10]);
    }

    private function settings(): RuntimeSettings
    {
        return app(RuntimeSettings::class);
    }

    /** Store the switch as the API/UI would (JSON), then read it afresh. */
    private function storeSetting(mixed $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => RuntimeSettings::ALLOW_OVERPRODUCTION],
            ['value' => json_encode($value), 'updated_at' => now()],
        );
        $this->settings()->flush();
    }

    private function forgetSetting(): void
    {
        DB::table('system_settings')->where('key', RuntimeSettings::ALLOW_OVERPRODUCTION)->delete();
        $this->settings()->flush();
    }

    /** A batch of 15 against a work order planned for 10. */
    private function storeBatchAbovePlan()
    {
        return $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$this->workOrder->id}/batches", [
                'target_qty' => 15,
            ]);
    }

    public function test_stored_true_wins_over_env_false_and_the_guard_lets_it_through(): void
    {
        config(['openmmes.allow_overproduction' => false]);
        $this->storeSetting(true);

        $this->assertTrue($this->settings()->allowOverproduction());
        $this->assertSame(RuntimeSettings::SOURCE_DATABASE, $this->settings()->allowOverproductionSource());

        $this->storeBatchAbovePlan()->assertStatus(201);
    }

    public function test_stored_false_wins_over_env_true_and_the_guard_blocks(): void
    {
        config(['openmmes.allow_overproduction' => true]);
        $this->storeSetting(false);

        $this->assertFalse($this->settings()->allowOverproduction());
        $this->assertSame(RuntimeSettings::SOURCE_DATABASE, $this->settings()->allowOverproductionSource());

        $this->storeBatchAbovePlan()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity');
    }

    public function test_missing_key_uses_env_true_as_the_declared_default(): void
    {
        $this->forgetSetting();
        config(['openmmes.allow_overproduction' => true]);

        $this->assertTrue($this->settings()->allowOverproduction());
        $this->assertSame(RuntimeSettings::SOURCE_ENV, $this->settings()->allowOverproductionSource());
    }

    public function test_missing_key_uses_env_false_as_the_declared_default(): void
    {
        $this->forgetSetting();
        config(['openmmes.allow_overproduction' => false]);

        $this->assertFalse($this->settings()->allowOverproduction());
        $this->assertSame(RuntimeSettings::SOURCE_ENV, $this->settings()->allowOverproductionSource());
    }

    public function test_an_unreadable_stored_value_is_logged_and_the_env_default_applies(): void
    {
        config(['openmmes.allow_overproduction' => true]);
        DB::table('system_settings')->where('key', RuntimeSettings::ALLOW_OVERPRODUCTION)
            ->update(['value' => json_encode('tak'), 'updated_at' => now()]);
        $this->settings()->flush();

        Log::spy();

        $this->assertTrue($this->settings()->allowOverproduction());
        $this->assertSame(RuntimeSettings::SOURCE_ENV, $this->settings()->allowOverproductionSource());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'system_settings.invalid_value'
                && ($context['key'] ?? null) === RuntimeSettings::ALLOW_OVERPRODUCTION
                && ($context['raw'] ?? null) === '"tak"'
                && ($context['applied_value'] ?? null) === true)
            ->once();
    }

    public function test_a_saved_setting_is_visible_once_the_memo_is_flushed(): void
    {
        $this->storeSetting(false);
        $this->assertFalse($this->settings()->allowOverproduction());

        $this->actingAs($this->admin)->post('/settings/system', [
            'production_period' => 'none',
            'workflow_mode' => 'status',
            'schedule_view_mode' => 'weekly',
            'schedule_shifts_per_day' => 1,
            'schedule_horizon_weeks' => 6,
            'realtime_mode' => 'polling',
            'production_tracking_mode' => 'per_operation',
            'production_qty_edit_policy' => 'none',
            'scanner_mode' => 'hid',
            'allow_overproduction' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(true, json_decode(DB::table('system_settings')
            ->where('key', RuntimeSettings::ALLOW_OVERPRODUCTION)->value('value'), true));

        // The memo still holds the old answer: no TTL, and nothing was flushed by
        // the write. In production the next request gets a fresh scoped instance;
        // inside one process flush() is what re-reads the stored value.
        $this->assertFalse($this->settings()->allowOverproduction());
        $this->settings()->flush();
        $this->assertTrue($this->settings()->allowOverproduction());
        $this->assertSame(RuntimeSettings::SOURCE_DATABASE, $this->settings()->allowOverproductionSource());
    }

    public function test_the_settings_page_reports_where_the_value_comes_from(): void
    {
        config(['openmmes.allow_overproduction' => true]);
        $this->forgetSetting();

        $this->actingAs($this->admin)->get('/settings/system')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.allow_overproduction', true)
                ->where('settings.allow_overproduction_source', RuntimeSettings::SOURCE_ENV));

        $this->storeSetting(false);

        $this->actingAs($this->admin)->get('/settings/system')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.allow_overproduction', false)
                ->where('settings.allow_overproduction_source', RuntimeSettings::SOURCE_DATABASE));
    }
}
