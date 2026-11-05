<?php

namespace App\Policies;

use App\Models\MachineCommand;
use App\Models\User;

/**
 * Authorization for the machine-command outbox (P1b).
 *
 * Requesting an intent is a human action scoped to the production line the
 * operator is assigned to. Processing the outbox (claim/complete) is reserved
 * for the machine bridge. Reading is allowed to both.
 */
class MachineCommandPolicy
{
    /**
     * Request permission required by each command type.
     *
     * @var array<string, string>
     */
    private const TYPE_PERMISSIONS = [
        MachineCommand::TYPE_LOAD_NC => 'request machine load',
        MachineCommand::TYPE_MANUAL_M30 => 'request machine manual m30',
        MachineCommand::TYPE_GENERATE_LABELS => 'request machine labels',
    ];

    public function viewAny(User $user): bool
    {
        return $user->can('process machine commands')
            || $user->canAny(array_values(self::TYPE_PERMISSIONS));
    }

    /**
     * Command types this user may see in a list.
     *
     * `null` means "all types" — only outbox processors (the machine bridge,
     * admin) get that. Everyone else is limited to the types they hold the
     * request permission for, so a manual_m30 row can never leak into an
     * operator's queue through the list endpoint.
     *
     * @return array<int, string>|null
     */
    public function visibleTypes(User $user): ?array
    {
        if ($user->can('process machine commands')) {
            return null;
        }

        $types = [];

        foreach (self::TYPE_PERMISSIONS as $type => $permission) {
            if (! $user->can($permission)) {
                continue;
            }

            // manual_m30 ends the machine cycle: a hand-granted permission is
            // not enough to even reveal these rows. Only trusted roles see them.
            if ($type === MachineCommand::TYPE_MANUAL_M30
                && ! $user->hasAnyRole(['Admin', 'Supervisor'])) {
                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    public function view(User $user, MachineCommand $command): bool
    {
        if ($user->can('process machine commands')) {
            return true;
        }

        $permission = self::TYPE_PERMISSIONS[$command->type] ?? null;

        if ($permission === null || ! $user->can($permission)) {
            return false;
        }

        // manual_m30 is destructive; a hand-granted permission on its own must
        // not give access to an existing destructive command either.
        if ($command->type === MachineCommand::TYPE_MANUAL_M30
            && ! $user->hasAnyRole(['Admin', 'Supervisor'])) {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        return $user->lines()->where('lines.id', $command->line_id)->exists();
    }

    public function create(User $user, string $type): bool
    {
        $permission = self::TYPE_PERMISSIONS[$type] ?? null;

        if ($permission === null || ! $user->can($permission)) {
            return false;
        }

        // manual_m30 ends the machine cycle. A permission granted by hand in
        // Settings → Access is not enough; the role must be trusted with it.
        if ($type === MachineCommand::TYPE_MANUAL_M30) {
            return $user->hasAnyRole(['Admin', 'Supervisor']);
        }

        return true;
    }

    public function cancel(User $user, MachineCommand $command): bool
    {
        // The machine bridge processes the outbox; it must never cancel work.
        if ($user->hasRole('MachineBridge')) {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        if ((int) $command->requested_by_id !== (int) $user->id) {
            return false;
        }

        return $user->lines()->where('lines.id', $command->line_id)->exists();
    }

    public function process(User $user): bool
    {
        return $user->can('process machine commands');
    }
}
