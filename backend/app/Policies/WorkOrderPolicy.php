<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Any role that can view work orders may list them; the query itself is
        // scoped to the user's lines for non-supervisors (WorkOrder::scopeForUser).
        return $user->can('view work orders');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        // Check permission
        if (! $user->can('view work orders')) {
            return false;
        }

        // Admin and Supervisor can view all work orders
        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        // Operators can only view work orders for their assigned lines
        return $user->lines()->where('lines.id', $workOrder->line_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create work orders');
    }

    /**
     * Create a batch on an existing work order (RQ-190).
     *
     * The machine bridge opens batches for the line it runs; that is narrower
     * than the generic 'create work orders' grant it used before. Admin and
     * Supervisor may batch any order; everyone else only their own lines.
     */
    public function createBatch(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->can('create batches')) {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        return $user->lines()->where('lines.id', $workOrder->line_id)->exists();
    }

    /**
     * Create a work order on the PACKING line (#R07).
     *
     * The bridge account must not inherit the generic CNC create grant. A human
     * worker assigned to PACKING can also create the PACK order from the station.
     */
    public function createPacking(User $user): bool
    {
        if (! $user->can('manage packing work orders')) {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        return $user->lines()->where('lines.code', 'PACKING')->exists();
    }

    /**
     * Manage a PACKING work order (expected codes, start, complete).
     */
    public function managePacking(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->can('manage packing work orders')) {
            return false;
        }

        if (! $workOrder->line || $workOrder->line->code !== 'PACKING') {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        return $user->lines()->where('lines.id', $workOrder->line_id)->exists();
    }

    /**
     * Dispatch cartons/pallets from the packing station (#R07).
     */
    public function dispatch(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->can('dispatch packing')) {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        return $user->lines()->where('lines.id', $workOrder->line_id)->exists();
    }

    /**
     * Process the dispatch outbox (PA bridge only).
     */
    public function processDispatch(User $user): bool
    {
        return $user->can('process packing dispatch');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->can('edit work orders')) {
            return false;
        }

        // Admin and Supervisor can update all work orders
        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        // Operators can only update work orders for their assigned lines
        return $user->lines()->where('lines.id', $workOrder->line_id)->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('delete work orders');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('delete work orders');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('delete work orders');
    }
}
