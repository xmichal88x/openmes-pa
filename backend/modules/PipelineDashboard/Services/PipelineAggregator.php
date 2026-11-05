<?php

namespace Modules\PipelineDashboard\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Modules\PipelineDashboard\Models\NestingStatus;
use Modules\PipelineDashboard\Models\PipelineLog;

class PipelineAggregator
{
    private DolibarrClient $dolibarr;

    public function __construct(DolibarrClient $dolibarr)
    {
        $this->dolibarr = $dolibarr;
    }

    public static function wooId(string $orderNo): string
    {
        if (preg_match('/^WC-(\d+)-/', $orderNo, $m)) {
            return $m[1];
        }
        return $orderNo;
    }

    public function getActiveOrders(): array
    {
        $workOrders = WorkOrder::whereNotIn('status', ['DONE', 'REJECTED', 'CANCELLED'])
            ->orderBy('created_at', 'desc')
            ->get();

        $grouped = $workOrders->groupBy('order_no');

        $result = [];
        foreach ($grouped as $orderNo => $orders) {
            $wooId = self::wooId($orderNo);
            $woStatuses = $orders->pluck('status')->unique();
            $batches = $orders->flatMap(fn ($wo) => $wo->batches()->get());
            $totalBatches = $batches->count();
            $doneBatches = $batches->where('status', 'DONE')->count();

            $nesting = NestingStatus::where('order_no', $wooId)->first();
            $doliMo = $this->dolibarr->getMoStatus($wooId);
            $lastLog = PipelineLog::where('order_no', $wooId)
                ->orderBy('logged_at', 'desc')
                ->first();

            $stage = $this->determineStage($woStatuses, $nesting, $doliMo);

            $result[] = [
                'order_no' => $orderNo,
                'woo_id' => $wooId,
                'woo_order_id' => $orders->first()->extra_data['wc_order_number'] ?? $wooId,
                'doli_mo_ref' => $doliMo['mo_ref'] ?? null,
                'doli_mo_completed' => $doliMo['completed'] ?? false,
                'wo_count' => $orders->count(),
                'wo_statuses' => $woStatuses->values()->toArray(),
                'batch_total' => $totalBatches,
                'batch_done' => $doneBatches,
                'nesting_status' => $nesting?->status,
                'current_stage' => $stage,
                'last_update' => $lastLog?->logged_at?->toIso8601String(),
                'last_wo_id' => $orders->first()->id,
            ];
        }

        return $result;
    }

    private function determineStage(
        Collection $woStatuses,
        ?NestingStatus $nesting,
        ?array $doliMo,
    ): string {
        if ($woStatuses->contains('DONE') && $woStatuses->every(fn ($s) => $s === 'DONE')) {
            return 'mes_done';
        }
        if ($woStatuses->contains('IN_PROGRESS')) {
            return 'mes_in_progress';
        }
        if ($woStatuses->contains('ACCEPTED')) {
            return 'mes_accepted';
        }
        if ($nesting && $nesting->status === 'manifest_done') {
            return 'nesting_done';
        }
        if ($nesting && str_starts_with((string) $nesting->status, 'cdm_')) {
            return 'nesting_cdm';
        }
        if ($nesting && str_starts_with((string) $nesting->status, 'nesting_')) {
            return 'nesting';
        }
        if ($doliMo && $doliMo['completed']) {
            return 'doli_done';
        }
        if ($doliMo) {
            return 'doli_mo';
        }

        return 'mes_pending';
    }
}
