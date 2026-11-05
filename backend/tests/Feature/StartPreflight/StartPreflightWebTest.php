<?php

namespace Tests\Feature\StartPreflight;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StartPreflightWebTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private WorkOrder $workOrder;

    private Batch $batch;

    private BatchStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Operator', 'guard_name' => 'web']);
        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->workOrder = WorkOrder::factory()->create();
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

    private function actingAsOperator()
    {
        return $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $this->workOrder->line_id]);
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

    public function test_web_start_merged_target_without_confirmation_shows_error(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        $this->actingAsOperator()
            ->from(route('operator.queue'))
            ->post(route('operator.batch-step.start', $this->step), [])
            ->assertRedirect(route('operator.queue'))
            ->assertSessionHasErrors('confirm_shared_sheet')
            ->assertSessionHas('error');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
    }

    public function test_web_start_merged_target_with_confirmation_starts(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => false, 'n_orders' => 2, 'target_job' => 'JOB-7']);

        $this->actingAsOperator()
            ->post(route('operator.batch-step.start', $this->step), ['confirm_shared_sheet' => true])
            ->assertSessionHas('success');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
    }

    public function test_web_start_rebuilding_shows_error_and_keeps_status(): void
    {
        $this->fakeState(['state' => 'rebuilding']);

        $this->actingAsOperator()
            ->from(route('operator.queue'))
            ->post(route('operator.batch-step.start', $this->step), [])
            ->assertSessionHasErrors('step')
            ->assertSessionHas('error');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
    }

    public function test_web_start_with_unavailable_preflight_starts_and_warns(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAsOperator()
            ->post(route('operator.batch-step.start', $this->step), [])
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
    }

    public function test_preview_endpoint_returns_verdict_without_changing_status(): void
    {
        $this->fakeState(['state' => 'merged', 'is_source' => true, 'n_orders' => 2, 'target_job' => 'JOB-9']);

        $this->actingAsOperator()
            ->getJson(route('operator.batch-step.start-preflight', $this->step))
            ->assertOk()
            ->assertJsonPath('state', 'merged')
            ->assertJsonPath('is_source', true)
            ->assertJsonPath('target_job', 'JOB-9');

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'like', 'start_preflight.%')->count());
    }

    public function test_preview_endpoint_returns_ok_without_http_for_non_cnc_step(): void
    {
        $this->step->update(['name' => 'Assembly', 'kind' => TemplateStep::KIND_PRODUCTION]);
        Http::fake();

        $this->actingAsOperator()
            ->getJson(route('operator.batch-step.start-preflight', $this->step))
            ->assertOk()
            ->assertJsonPath('state', 'ok');

        Http::assertNothingSent();
    }

    public function test_preview_endpoint_rejects_step_from_another_line(): void
    {
        $this->fakeState();

        $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $this->workOrder->line_id + 999])
            ->getJson(route('operator.batch-step.start-preflight', $this->step))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
