<?php

namespace Tests\Feature\StartPreflight;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use App\Support\ProductionFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StartPreflightApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkOrder $workOrder;

    private Batch $batch;

    private BatchStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('Operator');

        $this->workOrder = WorkOrder::factory()->create();
        $this->user->lines()->attach($this->workOrder->line_id);
        $this->batch = app(WorkOrderService::class)->createBatch($this->workOrder, 10);
        $this->step = $this->batch->steps()->orderBy('step_number')->first();
        $this->step->update(['name' => 'CNC cutting', 'kind' => TemplateStep::KIND_PRODUCTION]);

        config([
            'start_preflight.enabled' => true,
            'start_preflight.url' => 'http://queue.test/v1/start-preflight',
            'start_preflight.token' => 'test-token',
            'start_preflight.timeout' => 1.0,
        ]);
    }

    private function asRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->lines()->attach($this->workOrder->line_id);

        return $user;
    }

    private function start(array $payload = [])
    {
        $token = $this->user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/batch-steps/{$this->step->id}/start", $payload);
    }

    private function fakeState(array $overrides = []): void
    {
        Http::fake(function ($request) use ($overrides) {
            if (str_ends_with($request->url(), '/start-lease/release')) {
                return Http::response(['released' => true], 200);
            }

            $body = array_merge([
                'schema_version' => 1,
                'state' => 'ok',
                'n_orders' => 1,
                'orders' => [['order_no' => $this->workOrder->order_no, 'wo_ids' => [$this->workOrder->id]]],
                'target_job' => null,
                'is_source' => false,
                'message' => '',
            ], $overrides);

            if (str_ends_with($request->url(), '/start-lease') && ! array_key_exists('lease_token', $overrides)) {
                $state = $body['state'] ?? 'ok';
                $confirmed = (bool) ($request->data()['confirm_shared'] ?? false);
                $body['lease_token'] = $state === 'ok' || ($state === 'merged' && $confirmed)
                    ? 'lease-token-1'
                    : null;
            }

            return Http::response($body, 200);
        });
    }

    public function test_ok_acquires_lease_starts_and_releases_committed(): void
    {
        $this->fakeState();

        $this->start()->assertOk()->assertJsonPath('data.status', BatchStep::STATUS_IN_PROGRESS);

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === [
                'wo_id' => $this->workOrder->id,
                'batch_id' => $this->batch->id,
                'step_id' => $this->step->id,
                'order_no' => $this->workOrder->order_no,
                'confirm_shared' => false,
                'system_fanout' => false,
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease/release'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === [
                'lease_token' => 'lease-token-1',
                'committed' => true,
            ]);
    }

    public function test_rebuilding_blocks_start_and_keeps_status(): void
    {
        $this->fakeState(['state' => 'rebuilding', 'message' => 'rebuilding']);

        $this->start()
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_blocked')
            ->assertJsonPath('reason', 'rebuilding');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'batch_step',
            'entity_id' => $this->step->id,
            'action' => 'start_preflight.blocked',
        ]);
    }

    public function test_merged_source_blocks_and_reports_target_and_count(): void
    {
        $this->fakeState([
            'state' => 'merged',
            'is_source' => true,
            'n_orders' => 3,
            'target_job' => 'JOB-42',
        ]);

        $this->start()
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_blocked')
            ->assertJsonPath('reason', 'source')
            ->assertJsonPath('data.target_job', 'JOB-42')
            ->assertJsonPath('data.n_orders', 3);

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
    }

    public function test_merged_source_with_system_fanout_and_confirmation_starts(): void
    {
        $this->user = $this->asRole('MachineBridge');

        $this->fakeState([
            'state' => 'merged',
            'is_source' => true,
            'n_orders' => 3,
            'target_job' => 'JOB-42',
        ]);

        $this->start(['confirm_shared_sheet' => true, 'system_fanout' => true])
            ->assertOk()
            ->assertJsonPath('data.status', BatchStep::STATUS_IN_PROGRESS);

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease'
            && $request->method() === 'POST'
            && $request->data()['system_fanout'] === true
            && $request->data()['confirm_shared'] === true);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease/release'
            && $request->data() === [
                'lease_token' => 'lease-token-1',
                'committed' => true,
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'batch_step',
            'entity_id' => $this->step->id,
            'action' => 'start_preflight.confirmed',
        ]);
    }

    public function test_operator_system_fanout_is_forbidden(): void
    {
        $this->fakeState([
            'state' => 'merged',
            'is_source' => true,
            'n_orders' => 3,
            'target_job' => 'JOB-42',
        ]);

        $this->start(['confirm_shared_sheet' => true, 'system_fanout' => true])
            ->assertStatus(403)
            ->assertJsonPath('code', 'system_fanout_forbidden');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertNothingSent();

        $audit = AuditLog::where('entity_type', 'batch_step')
            ->where('entity_id', $this->step->id)
            ->where('action', 'start_preflight.denied')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('system_fanout_forbidden', $audit->after_state['reason']);
    }

    public function test_merged_source_without_system_fanout_blocks_even_when_confirmed(): void
    {
        $this->fakeState([
            'state' => 'merged',
            'is_source' => true,
            'n_orders' => 3,
            'target_job' => 'JOB-42',
        ]);

        $this->start(['confirm_shared_sheet' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_blocked')
            ->assertJsonPath('reason', 'source');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease'
            && $request->data()['system_fanout'] === false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release')
            && $request->method() === 'POST'
            && $request->data() === [
                'lease_token' => 'lease-token-1',
                'committed' => false,
            ]);
    }

    public function test_merged_source_with_system_fanout_without_confirmation_blocks(): void
    {
        $this->user = $this->asRole('MachineBridge');

        $this->fakeState([
            'state' => 'merged',
            'is_source' => true,
            'n_orders' => 3,
            'target_job' => 'JOB-42',
        ]);

        $this->start(['system_fanout' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_blocked')
            ->assertJsonPath('reason', 'source');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease'
            && $request->data()['system_fanout'] === true);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release'));
    }

    public function test_system_fanout_must_be_boolean(): void
    {
        $this->fakeState();

        $this->start(['system_fanout' => 'yes-please'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('system_fanout');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'entity_type' => 'batch_step',
            'entity_id' => $this->step->id,
            'action' => 'start_preflight.denied',
        ]);
        Http::assertNothingSent();
    }

    public function test_admin_system_fanout_starts(): void
    {
        $this->user = $this->asRole('Admin');
        $this->fakeState([
            'state' => 'merged',
            'is_source' => true,
            'n_orders' => 2,
            'target_job' => 'JOB-7',
        ]);

        $this->start(['confirm_shared_sheet' => true, 'system_fanout' => true])
            ->assertOk()
            ->assertJsonPath('data.status', BatchStep::STATUS_IN_PROGRESS);
    }

    public function test_merged_target_without_confirmation_requires_it(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        $this->start()
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_confirm')
            ->assertJsonPath('data.n_orders', 2)
            ->assertJsonPath('data.target_job', 'JOB-7');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'batch_step',
            'entity_id' => $this->step->id,
            'action' => 'start_preflight.confirm_required',
        ]);
    }

    public function test_merged_target_with_confirmation_starts(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        $this->start(['confirm_shared_sheet' => true])->assertOk();

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'batch_step',
            'entity_id' => $this->step->id,
            'action' => 'start_preflight.confirmed',
        ]);
    }

    public function test_http_error_starts_with_warning(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->start()
            ->assertOk()
            ->assertJsonPath('warning', 'preflight_unavailable');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);

        $audit = AuditLog::where('entity_id', $this->step->id)->where('action', 'start_preflight.warning')->first();
        $this->assertNotNull($audit);
        $this->assertSame('unknown', $audit->after_state['state']);
    }

    public function test_transport_timeout_starts_with_warning(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->start()
            ->assertOk()
            ->assertJsonPath('warning', 'preflight_unavailable');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
    }

    public function test_invalid_json_starts_with_warning(): void
    {
        Http::fake(['*' => Http::response('not json', 200)]);

        $this->start()
            ->assertOk()
            ->assertJsonPath('warning', 'preflight_unavailable');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
    }

    public function test_missing_token_starts_with_warning_without_http_call(): void
    {
        config(['start_preflight.token' => '']);
        Http::fake();

        $this->start()
            ->assertOk()
            ->assertJsonPath('warning', 'preflight_unavailable');

        Http::assertNothingSent();
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
    }

    public function test_missing_url_starts_with_warning_without_http_call(): void
    {
        config(['start_preflight.url' => '']);
        Http::fake();

        $this->start()
            ->assertOk()
            ->assertJsonPath('warning', 'preflight_unavailable');

        Http::assertNothingSent();
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
    }

    public function test_confirm_shared_sheet_must_be_boolean(): void
    {
        $this->fakeState();

        $this->start(['confirm_shared_sheet' => 'yes-please'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_shared_sheet');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
    }

    public function test_auto_start_defers_merged_target_without_exception(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        // Auto-start (5th arg) must leave the step READY instead of blocking the
        // caller that is finishing the previous step.
        app(\App\Services\WorkOrder\BatchService::class)
            ->startStep($this->step, $this->user, [], false, true);

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'like', 'start_preflight.%')->count());
    }

    public function test_non_cnc_step_starts_without_any_http_call(): void
    {
        $this->step->update(['name' => 'Assembly', 'kind' => TemplateStep::KIND_PRODUCTION]);
        Http::fake();

        $this->start()
            ->assertOk()
            ->assertJsonMissingPath('warning');

        Http::assertNothingSent();
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'like', 'start_preflight.%')->count());
    }

    public function test_auto_start_deferral_keeps_previous_step_successful_and_skips_its_quantity(): void
    {
        ProductionFlow::set(ProductionFlow::TRANSFER);

        $station = Workstation::factory()->create(['line_id' => $this->workOrder->line_id]);
        $later = Workstation::factory()->create(['line_id' => $this->workOrder->line_id]);

        $steps = $this->batch->steps()->orderBy('step_number')->get();
        $steps[0]->update(['workstation_id' => $station->id, 'name' => 'Assembly']);
        $steps[1]->update(['workstation_id' => $station->id, 'name' => 'CNC cutting']);
        $steps[2]->update(['workstation_id' => $later->id, 'name' => 'Packing']);

        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);
        Log::spy();

        $service = app(BatchService::class);
        $service->startStep($steps[0], $this->user);

        // Non-CNC step: no queue call at all.
        Http::assertNothingSent();

        $service->recordQuantityThroughStation($steps[0], $this->user, good: 4);

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $steps[0]->fresh()->status);
        $this->assertEquals(4.0, $steps[0]->fresh()->passed_qty);
        $this->assertSame(BatchStep::STATUS_READY, $steps[1]->fresh()->status);
        $this->assertEquals(0.0, $steps[1]->fresh()->passed_qty);

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'start_preflight.auto_start_deferred_skip_quantity')
            ->once();
    }

    public function test_failed_start_releases_lease_as_not_committed(): void
    {
        $this->fakeState();
        $this->step->update(['status' => BatchStep::STATUS_IN_PROGRESS]);

        $this->start()->assertStatus(422);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease/release'
            && $request->data() === [
                'lease_token' => 'lease-token-1',
                'committed' => false,
            ]);
    }

    public function test_blocked_cancel_in_progress_blocks_without_status_change(): void
    {
        $this->fakeState(['state' => 'blocked', 'reason' => 'cancel_in_progress', 'message' => 'cancelling']);

        $this->start()
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_blocked')
            ->assertJsonPath('reason', 'rebuilding')
            ->assertJsonPath('queue_reason', 'cancel_in_progress');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release'));
    }

    public function test_blocked_lease_active_blocks_without_status_change(): void
    {
        $this->fakeState(['state' => 'blocked', 'reason' => 'lease_active', 'message' => 'another start']);

        $this->start()
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_blocked')
            ->assertJsonPath('reason', 'lease_active')
            ->assertJsonPath('queue_reason', 'lease_active');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release'));
    }

    public function test_merged_target_without_confirmation_does_not_start_or_release(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        $this->start()
            ->assertStatus(409)
            ->assertJsonPath('code', 'shared_sheet_confirm');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release'));
    }

    public function test_merged_target_with_confirmation_acquires_and_releases(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        $this->start(['confirm_shared_sheet' => true])->assertOk();

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease'
            && $request->data()['confirm_shared'] === true);

        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-lease/release'
            && $request->data() === [
                'lease_token' => 'lease-token-1',
                'committed' => true,
            ]);
    }

    public function test_unknown_queue_starts_with_warning_without_lease(): void
    {
        $this->fakeState(['state' => 'unknown', 'message' => 'queue down']);

        $this->start()
            ->assertOk()
            ->assertJsonPath('warning', 'preflight_unavailable');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release'));
    }

    public function test_auto_start_merged_target_uses_no_lease(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        app(BatchService::class)->startStep($this->step, $this->user, [], false, true);

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://queue.test/v1/start-preflight');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start-lease/release'));
    }

    public function test_release_failure_does_not_break_start(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/start-lease/release')) {
                return Http::response(['released' => false], 500);
            }

            return Http::response([
                'schema_version' => 1,
                'state' => 'ok',
                'n_orders' => 1,
                'orders' => [['order_no' => $this->workOrder->order_no, 'wo_ids' => [$this->workOrder->id]]],
                'target_job' => null,
                'is_source' => false,
                'lease_token' => 'lease-token-1',
                'message' => '',
            ], 200);
        });
        Log::spy();

        $this->start()->assertOk()->assertJsonPath('data.status', BatchStep::STATUS_IN_PROGRESS);

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'start_preflight.release_http_error')
            ->once();
    }
}
