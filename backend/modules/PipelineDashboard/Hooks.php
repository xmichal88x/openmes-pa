<?php

namespace Modules\PipelineDashboard;

use App\Events\BatchStep\StepCompleted;
use App\Events\BatchStep\StepStarted;
use App\Events\WorkOrder\WorkOrderCompleted;
use App\Events\WorkOrder\WorkOrderCreated;
use Illuminate\Support\Facades\Log;
use Modules\PipelineDashboard\Models\PipelineLog;

class Hooks
{
    private static function wooId(string $orderNo): string
    {
        if (preg_match('/^WC-(\d+)-/', $orderNo, $m)) {
            return $m[1];
        }
        return $orderNo;
    }

    public function onWorkOrderCreated(WorkOrderCreated $e): void
    {
        $this->log(self::wooId($e->workOrder->order_no), 'mes_wo', [
            'wo_id' => $e->workOrder->id,
            'status' => $e->workOrder->status,
            'extra_data' => $e->workOrder->extra_data,
        ], 'event_hook');
    }

    public function onWorkOrderCompleted(WorkOrderCompleted $e): void
    {
        $this->log(self::wooId($e->workOrder->order_no), 'mes_done', [
            'wo_id' => $e->workOrder->id,
            'produced_qty' => $e->workOrder->produced_qty,
        ], 'event_hook');
    }

    public function onStepStarted(StepStarted $e): void
    {
        $wo = $e->batchStep->batch?->workOrder;
        if (! $wo) {
            return;
        }

        $this->log(self::wooId($wo->order_no), 'sheet_step_started', [
            'batch_id' => $e->batchStep->batch_id,
            'step_id' => $e->batchStep->id,
            'workstation_id' => $e->batchStep->workstation_id,
        ], 'event_hook');
    }

    public function onStepCompleted(StepCompleted $e): void
    {
        $wo = $e->batchStep->batch?->workOrder;
        if (! $wo) {
            return;
        }

        $this->log(self::wooId($wo->order_no), 'sheet_step_done', [
            'batch_id' => $e->batchStep->batch_id,
            'step_id' => $e->batchStep->id,
        ], 'event_hook');
    }

    private function log(string $orderNo, string $stage, array $details, string $source): void
    {
        try {
            PipelineLog::create([
                'order_no' => $orderNo,
                'stage' => $stage,
                'details' => $details,
                'source' => $source,
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PipelineDashboard] log failed', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
