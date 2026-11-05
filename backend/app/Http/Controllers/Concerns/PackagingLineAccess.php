<?php

namespace App\Http\Controllers\Concerns;

use App\Models\WorkOrder;
use Illuminate\Http\Request;

/**
 * Shop-floor accounts (Operator, PackingWorker) only touch their own production
 * line; supervisors and admins see every line. Mirrors the rule used by the EAN
 * scan API and WorkOrderPolicy.
 */
trait PackagingLineAccess
{
    protected function assertWorkOrderLineAccess(Request $request, WorkOrder $workOrder): void
    {
        $user = $request->user();

        if (! $user || $user->hasAnyRole(['Admin', 'Supervisor'])) {
            return;
        }

        if (! $workOrder->line_id || ! $user->lines()->where('lines.id', $workOrder->line_id)->exists()) {
            abort(403, __('You do not have access to this line.'));
        }
    }

    /**
     * @return array<int, int>|null null means "no line restriction"
     */
    protected function operatorLineIds(Request $request): ?array
    {
        $user = $request->user();

        if (! $user || $user->hasAnyRole(['Admin', 'Supervisor'])) {
            return null;
        }

        return $user->lines()->pluck('lines.id')->all();
    }
}
