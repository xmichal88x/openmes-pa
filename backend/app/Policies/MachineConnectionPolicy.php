<?php

namespace App\Policies;

use App\Models\MachineConnection;
use App\Models\User;

class MachineConnectionPolicy
{
    /**
     * The machine gateway (MachineBridge) may read connection metadata so it can
     * resolve its own config, but only an Admin may change connectivity.
     */
    public function viewAny(User $u): bool
    {
        return $u->hasAnyRole(['Admin', 'MachineBridge']);
    }

    public function view(User $u, MachineConnection $c): bool
    {
        return $u->hasAnyRole(['Admin', 'MachineBridge']);
    }

    public function create(User $u): bool
    {
        return $u->hasRole('Admin');
    }

    public function update(User $u, MachineConnection $c): bool
    {
        return $u->hasRole('Admin');
    }

    public function delete(User $u, MachineConnection $c): bool
    {
        return $u->hasRole('Admin');
    }
}
