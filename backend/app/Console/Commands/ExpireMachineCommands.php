<?php

namespace App\Console\Commands;

use App\Services\Machine\MachineCommandService;
use Illuminate\Console\Command;

class ExpireMachineCommands extends Command
{
    protected $signature = 'machine-commands:expire {--tenant= : Limit reclamation to one tenant id}';

    protected $description = 'Reclaim expired machine-command leases (requeue or fail according to max_attempts)';

    public function handle(MachineCommandService $service): int
    {
        $tenant = $this->option('tenant');
        $tenantId = ($tenant === null || $tenant === '') ? null : (int) $tenant;

        $count = $service->expireLeases($tenantId);

        $this->info($tenantId === null
            ? "Reclaimed {$count} expired machine command lease(s)."
            : "Reclaimed {$count} expired machine command lease(s) for tenant #{$tenantId}.");

        return self::SUCCESS;
    }
}
