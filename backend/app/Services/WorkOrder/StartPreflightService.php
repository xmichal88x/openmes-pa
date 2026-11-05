<?php

namespace App\Services\WorkOrder;

use App\Models\BatchStep;
use App\Models\TemplateStep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the queue runtime about whether a step may start.
 *
 * `check()` is the read-only preview (R15/2) and never changes anything.
 * `acquireLease()` implements the R16/1 start lease: the queue hands out a
 * short-lived token, the caller starts the step, then `releaseLease()` tells the
 * queue whether the start committed. Every transport/HTTP/JSON problem degrades
 * to `state = unknown` with a `warning` so the caller can allow the start under
 * option B (warn, don't block).
 */
class StartPreflightService
{
    private const STATES = ['ok', 'rebuilding', 'merged', 'unknown', 'unavailable', 'blocked'];

    /**
     * Read-only preview. Side-effect free apart from logging.
     *
     * @return array{schema_version: int, state: string, n_orders: int, orders: array<int, array{order_no: string, wo_ids: array<int, int>}>, target_job: string|null, is_source: bool, message: string, warning?: string}
     */
    public function check(BatchStep $step): array
    {
        if (! config('start_preflight.enabled', true)) {
            return $this->result('ok', message: __('Queue preflight is disabled.'));
        }

        // The queue only governs cutting/nesting. Every other step (packing,
        // assembly, testing, ...) must never pay for an HTTP round-trip or
        // surface a queue warning.
        if (! $this->isCncStep($step)) {
            return $this->result('ok', message: __('Queue preflight does not apply to this step.'));
        }

        $url = $this->preflightUrl();
        $token = $this->token();

        if ($url === '' || $token === '') {
            return $this->notConfigured($step, $url, $token, 'preflight');
        }

        $payload = $this->payload($step);

        if ($payload === null) {
            return $this->fail('preflight_missing_work_order', __('The step has no batch/work order to preflight.'));
        }

        $response = $this->post($step, $url, $token, $payload, 'preflight');

        if (! $response['ok']) {
            return $response['result'];
        }

        return $this->normalize($response['data'], $step, 'preflight');
    }

    /**
     * Acquire a start lease from the queue (R16/1). A successful result carries
     * a `lease_token` that must be released after the start commits or fails.
     *
     * @return array<string, mixed>
     */
    public function acquireLease(BatchStep $step, bool $confirmShared, bool $systemFanout = false): array
    {
        if (! config('start_preflight.enabled', true)) {
            return $this->result('ok', message: __('Queue preflight is disabled.'));
        }

        if (! $this->isCncStep($step)) {
            return $this->result('ok', message: __('Queue preflight does not apply to this step.'));
        }

        $url = $this->leaseUrl();
        $token = $this->token();

        if ($url === '' || $token === '') {
            return $this->notConfigured($step, $url, $token, 'lease');
        }

        $payload = $this->payload($step);

        if ($payload === null) {
            return $this->fail('preflight_missing_work_order', __('The step has no batch/work order to preflight.'));
        }

        $payload['confirm_shared'] = $confirmShared;
        $payload['system_fanout'] = $systemFanout;

        $response = $this->post($step, $url, $token, $payload, 'lease');

        if (! $response['ok']) {
            return $response['result'];
        }

        return $this->normalize($response['data'], $step, 'lease');
    }

    /**
     * Release a start lease. Failures are logged and swallowed: the lease expires
     * on its own (15 s), a queue hiccup must never undo a committed start.
     */
    public function releaseLease(?string $token, bool $committed): void
    {
        $token = trim((string) $token);

        if ($token === '') {
            return;
        }

        $url = $this->releaseUrl();
        $auth = $this->token();

        if ($url === '' || $auth === '') {
            Log::warning('start_preflight.release_not_configured', [
                'committed' => $committed,
                'has_url' => $url !== '',
                'has_token' => $auth !== '',
            ]);

            return;
        }

        try {
            $response = Http::timeout((float) config('start_preflight.timeout', 1.0))
                ->withToken($auth)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'lease_token' => $token,
                    'committed' => $committed,
                ]);
        } catch (\Throwable $e) {
            Log::warning('start_preflight.release_failed', [
                'committed' => $committed,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('start_preflight.release_http_error', [
                'committed' => $committed,
                'status' => $response->status(),
            ]);
        }
    }

    /**
     * Whether the queue preflight applies to this step: only cutting/CNC work
     * is nested and shared between orders. Mirrors
     * MachineCommandService::isCncStep so the two features agree on what a CNC
     * step is.
     */
    public function isCncStep(BatchStep $step): bool
    {
        // Packing work is never a CNC step, regardless of what the workstation
        // or the step name says.
        if ($step->kind === TemplateStep::KIND_PACKING) {
            return false;
        }

        $step->loadMissing('workstation.workstationType');
        $workstation = $step->workstation;

        $allowedTypes = config('machine_commands.workstation_type_codes', []);

        if ($allowedTypes !== []) {
            if (! $this->codeInList($workstation?->workstationType?->code, $allowedTypes)) {
                return false;
            }
        }

        $needles = array_values(array_filter(array_map(
            fn ($value) => mb_strtolower(trim((string) $value)),
            [
                $step->name,
                $workstation?->code,
                $workstation?->workstationType?->code,
            ],
        ), fn (string $value) => $value !== ''));

        foreach (config('machine_commands.step_match', []) as $token) {
            $token = mb_strtolower(trim((string) $token));

            if ($token === '') {
                continue;
            }

            foreach ($needles as $haystack) {
                if (str_contains($haystack, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(BatchStep $step): ?array
    {
        $step->loadMissing('batch.workOrder');
        $batch = $step->batch;
        $workOrder = $batch?->workOrder;

        if (! $batch || ! $workOrder) {
            return null;
        }

        return [
            'wo_id' => (int) $workOrder->id,
            'batch_id' => (int) $batch->id,
            'step_id' => (int) $step->id,
            'order_no' => (string) $workOrder->order_no,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, result: array<string, mixed>}
     */
    private function post(BatchStep $step, string $url, string $token, array $payload, string $phase): array
    {
        try {
            $response = Http::timeout((float) config('start_preflight.timeout', 1.0))
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning("start_preflight.{$phase}.transport_error", [
                'step_id' => $step->id,
                'batch_id' => $step->batch_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'result' => $this->fail('preflight_unavailable', __('Queue preflight is unavailable.'), $e->getMessage()),
            ];
        }

        if (! $response->successful()) {
            Log::warning("start_preflight.{$phase}.http_error", [
                'step_id' => $step->id,
                'batch_id' => $step->batch_id,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'result' => $this->fail('preflight_unavailable', __('Queue preflight returned HTTP :status.', ['status' => $response->status()])),
            ];
        }

        $data = $response->json();

        if (! is_array($data)) {
            Log::warning("start_preflight.{$phase}.invalid_json", [
                'step_id' => $step->id,
                'batch_id' => $step->batch_id,
            ]);

            return [
                'ok' => false,
                'result' => $this->fail('preflight_unavailable', __('Queue preflight returned an unreadable response.')),
            ];
        }

        return ['ok' => true, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, BatchStep $step, string $phase): array
    {
        $state = (string) ($data['state'] ?? 'unknown');

        if (! in_array($state, self::STATES, true)) {
            $state = 'unknown';
        }

        $orders = [];
        foreach ((array) ($data['orders'] ?? []) as $order) {
            if (! is_array($order)) {
                continue;
            }
            $orders[] = [
                'order_no' => (string) ($order['order_no'] ?? ''),
                'wo_ids' => array_values(array_map('intval', (array) ($order['wo_ids'] ?? []))),
            ];
        }

        $result = [
            'schema_version' => (int) ($data['schema_version'] ?? 1),
            'state' => $state,
            'n_orders' => (int) ($data['n_orders'] ?? count($orders)),
            'orders' => $orders,
            'target_job' => isset($data['target_job']) && is_string($data['target_job']) ? $data['target_job'] : null,
            'is_source' => (bool) ($data['is_source'] ?? false),
            'message' => (string) ($data['message'] ?? ''),
        ];

        $reason = $data['reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            $result['reason'] = $reason;
        }

        if (array_key_exists('confirmation_required', $data)) {
            $result['confirmation_required'] = (bool) $data['confirmation_required'];
        }

        $leaseToken = $data['lease_token'] ?? null;
        $result['lease_token'] = is_string($leaseToken) && $leaseToken !== '' ? $leaseToken : null;

        $expiresAt = $data['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $result['expires_at'] = $expiresAt;
        }

        $sheetKey = $data['sheet_key'] ?? null;
        if (is_string($sheetKey) && $sheetKey !== '') {
            $result['sheet_key'] = $sheetKey;
        }

        if (in_array($state, ['unknown', 'unavailable', 'blocked'], true)) {
            // Option B / blocked verdicts never carry a usable lease.
            $result['lease_token'] = null;
        }

        if (in_array($state, ['unknown', 'unavailable'], true)) {
            $result['warning'] = 'preflight_unavailable';
            Log::warning('start_preflight.unknown_state', [
                'step_id' => $step->id,
                'batch_id' => $step->batch_id,
                'phase' => $phase,
                'state' => $state,
                'message' => $result['message'],
            ]);
        }

        return $result;
    }

    private function preflightUrl(): string
    {
        return trim((string) config('start_preflight.url', ''));
    }

    private function leaseUrl(): string
    {
        $url = trim((string) config('start_preflight.lease_url', ''));

        return $url !== '' ? $url : $this->deriveUrl('/start-lease');
    }

    private function releaseUrl(): string
    {
        $url = trim((string) config('start_preflight.release_url', ''));

        return $url !== '' ? $url : $this->deriveUrl('/start-lease/release');
    }

    private function deriveUrl(string $suffix): string
    {
        $base = $this->preflightUrl();

        if ($base === '') {
            return '';
        }

        if (str_contains($base, '/start-preflight')) {
            return str_replace('/start-preflight', $suffix, $base);
        }

        return rtrim($base, '/').$suffix;
    }

    private function token(): string
    {
        return trim((string) config('start_preflight.token', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function notConfigured(BatchStep $step, string $url, string $token, string $phase): array
    {
        Log::warning('start_preflight.not_configured', [
            'step_id' => $step->id,
            'batch_id' => $step->batch_id,
            'phase' => $phase,
            'has_url' => $url !== '',
            'has_token' => $token !== '',
        ]);

        return $this->fail('preflight_unavailable', __('Queue preflight is not configured (missing URL or token).'));
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function codeInList(?string $code, array $codes): bool
    {
        if ($code === null) {
            return false;
        }

        $code = mb_strtolower(trim($code));

        foreach ($codes as $candidate) {
            if (mb_strtolower(trim((string) $candidate)) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $warning, string $message, ?string $detail = null): array
    {
        $result = $this->result('unknown', message: $message);
        $result['warning'] = $warning;

        if ($detail !== null) {
            $result['detail'] = $detail;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $state, string $message = ''): array
    {
        return [
            'schema_version' => 1,
            'state' => $state,
            'n_orders' => 0,
            'orders' => [],
            'target_job' => null,
            'is_source' => false,
            'message' => $message,
        ];
    }
}
