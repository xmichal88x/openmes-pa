<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialSettlementScope;
use Illuminate\Console\Command;

/**
 * The console form of the material-settlement pilot decision (RQ-PA-265).
 *
 * It shows what the frozen routing allows — per component, with the reason when
 * it does not — and switches the pilot on or off through the same service the
 * API uses, so a refusal here is the same refusal a caller would get there. The
 * change is attributed to the operator of the console, and without `--dry-run`
 * it only ever leaves a trace on the order.
 */
class MaterialPilotCommand extends Command
{
    protected $signature = 'material:pilot {order_no} {--enable=* : Material codes} {--disable} {--dry-run}';

    protected $description = 'Show and change the material settlement pilot scope of one work order';

    public function handle(MaterialSettlementScope $scope): int
    {
        $orderNo = trim((string) $this->argument('order_no'));

        // Only active orders: a soft-deleted order is not on the floor anymore.
        $workOrder = WorkOrder::query()->where('order_no', $orderNo)->first();
        if (! $workOrder instanceof WorkOrder) {
            $this->error(__('No active work order with number :order_no.', ['order_no' => $orderNo]));

            return self::FAILURE;
        }

        $codes = array_values(array_filter(
            array_map(fn ($code) => trim((string) $code), (array) $this->option('enable')),
            fn (string $code) => $code !== '',
        ));
        $disable = (bool) $this->option('disable');
        $dryRun = (bool) $this->option('dry-run');

        if ($disable && $codes !== []) {
            $this->error(__('Use either --enable or --disable, not both.'));

            return self::FAILURE;
        }

        $this->info(__('Work order :order_no (:status).', [
            'order_no' => $workOrder->order_no,
            'status' => $workOrder->status,
        ]));

        $this->renderCurrent($scope->current($workOrder));
        $candidates = $scope->candidates($workOrder);
        $this->renderCandidates($candidates);

        if ($candidates === []) {
            $this->warn(__("This order's routing names no material with an operation."));
        }

        if (! $disable && $codes === []) {
            $this->line(__('Pass --enable=CODE or --disable to change the scope.'));

            return self::SUCCESS;
        }

        if ($disable) {
            // A dry run asks the same guard the write path asks, so it cannot
            // promise a change a real run would refuse — and it writes nothing.
            if ($dryRun) {
                try {
                    $scope->guardDisable($workOrder);
                } catch (\DomainException $exception) {
                    $this->error($exception->getMessage());
                    $this->warn(__('Dry run — nothing was written.'));

                    return self::FAILURE;
                }

                $this->info(__('Dry run — nothing was written.'));

                return self::SUCCESS;
            }

            $actor = $this->actor();
            if (! $actor instanceof User) {
                return self::FAILURE;
            }

            try {
                $scope->disable($workOrder, $actor);
            } catch (\DomainException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->recordedActor($actor);
            $this->info(__('Settlement scope disabled for :order_no.', ['order_no' => $orderNo]));

            return self::SUCCESS;
        }

        [$ids, $unknown] = $this->resolveCodes($codes, $candidates);

        if ($unknown !== []) {
            $this->error(__('Unknown material code: :codes (codes come from the candidate table).', [
                'codes' => implode(', ', $unknown),
            ]));

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warnWouldRefuse($codes, $candidates);
            $this->info(__('Dry run — nothing was written. A real run would enable: :materials', [
                'materials' => implode(', ', $codes),
            ]));

            return self::SUCCESS;
        }

        $actor = $this->actor();
        if (! $actor instanceof User) {
            return self::FAILURE;
        }

        try {
            $scope->enable($workOrder, $ids, $actor);
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->recordedActor($actor);
        $this->info(__('Settlement scope enabled for :order_no.', ['order_no' => $orderNo]));

        return self::SUCCESS;
    }

    /**
     * @param  array{enabled: bool, materials: array<int, int>, enabled_by: int|null, enabled_at: string|null, disabled_by: int|null, disabled_at: string|null}  $current
     */
    private function renderCurrent(array $current): void
    {
        if ($current['enabled']) {
            $this->line(__('Settlement scope: settled by the operation — enabled by user :id at :at, components: :materials', [
                'id' => $current['enabled_by'] ?? '—',
                'at' => $current['enabled_at'] ?? '—',
                'materials' => implode(', ', $current['materials']),
            ]));

            return;
        }

        $this->line(__('Settlement scope: settled with the batch plan (pilot off).'));

        if ($current['disabled_by'] !== null) {
            $this->line(__('Settlement scope ended by user :id at :at.', [
                'id' => $current['disabled_by'],
                'at' => $current['disabled_at'] ?? '—',
            ]));
        }
    }

    /**
     * @param  array<int, array{material_id: int, material_code: string|null, unit_of_measure: string|null, step_number: int|null, step_name: string|null, eligible: bool, reason: string|null}>  $candidates
     */
    private function renderCandidates(array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $this->table(
            [__('Code'), __('Unit'), __('Step'), __('Eligible'), __('Reason')],
            array_map(fn (array $candidate) => [
                $candidate['material_code'] ?? '—',
                $candidate['unit_of_measure'] ?? '—',
                $candidate['step_number'] === null
                    ? '—'
                    : trim($candidate['step_number'].' — '.($candidate['step_name'] ?? '')),
                $candidate['eligible'] ? __('Yes') : __('No'),
                $candidate['reason'] ?? '—',
            ], $candidates),
        );
    }

    /**
     * A dry run must not promise more than a real run would deliver: every
     * requested code the routing refuses is named here with its reason.
     *
     * @param  array<int, string>  $codes
     * @param  array<int, array{material_code: string|null, eligible: bool, reason: string|null}>  $candidates
     */
    private function warnWouldRefuse(array $codes, array $candidates): void
    {
        $refusals = [];
        foreach ($candidates as $candidate) {
            if ($candidate['eligible'] || ! in_array($candidate['material_code'], $codes, true)) {
                continue;
            }

            $refusals[] = sprintf('%s (%s)', $candidate['material_code'], $candidate['reason']);
        }

        if ($refusals === []) {
            return;
        }

        $this->warn(__('A real run would refuse: :components.', [
            'components' => implode(', ', $refusals),
        ]));
    }

    /**
     * Material codes are what a person reads in the table; the scope stores ids.
     *
     * @param  array<int, string>  $codes
     * @param  array<int, array{material_id: int, material_code: string|null}>  $candidates
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function resolveCodes(array $codes, array $candidates): array
    {
        $byCode = [];
        foreach ($candidates as $candidate) {
            if ($candidate['material_code'] !== null) {
                $byCode[$candidate['material_code']] = $candidate['material_id'];
            }
        }

        $ids = [];
        $unknown = [];
        foreach ($codes as $code) {
            if (! array_key_exists($code, $byCode)) {
                $unknown[] = $code;

                continue;
            }

            $ids[] = $byCode[$code];
        }

        return [$ids, $unknown];
    }

    /**
     * Who the change is recorded against: the signed-in user when the command
     * runs inside an authenticated context, otherwise the first Admin account —
     * named on the console once the change is written, so the trace is never
     * anonymous.
     */
    private function actor(): ?User
    {
        $current = auth()->user();
        if ($current instanceof User) {
            return $current;
        }

        $admin = User::query()->role('Admin')->orderBy('id')->first();
        if (! $admin instanceof User) {
            $this->error(__('No Admin user to attribute this settlement scope change to.'));

            return null;
        }

        return $admin;
    }

    /** Name the account the change was written against, so the audit trail is traceable. */
    private function recordedActor(User $actor): void
    {
        $this->line(__('Recorded actor: :name <:email>.', [
            'name' => $actor->name,
            'email' => $actor->email,
        ]));
    }
}
