<?php

namespace Database\Seeders;

use App\Support\TabRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Work Orders
            'view work orders', 'create work orders', 'edit work orders', 'delete work orders',
            // Change control (#182) — kept separate from 'edit work orders' so a plant
            // can let a planner raise a change while only a reviewer approves it.
            'approve work order changes',

            // Packaging (#R07) — deliberately narrow. 'manage packing work orders'
            // replaces the generic create/edit grants for the PACKING line only;
            // 'dispatch packing' is the operator action on cartons and dispatches;
            // 'process packing dispatch' belongs to the PA bridge account.
            'dispatch packing', 'manage packing work orders', 'process packing dispatch',

            // Machine commands (P1b) — requesting an intent is separate from
            // processing the outbox. Only the machine bridge may process.
            'request machine load', 'request machine labels', 'request machine manual m30',
            'process machine commands',

            // Batch & Steps
            'create batches',
            'start batch step', 'complete batch step', 'skip batch step',
            // System-level start of a shared-sheet source (machine gateway only).
            'start system fanout',

            // Issues
            'view issues', 'create issues', 'assign issues', 'resolve issues', 'close issues',

            // Lines & Workstations
            'view lines', 'manage lines',

            // Product & Process
            'view products', 'manage products', 'view process templates', 'manage process templates',

            // Engineering documents (#179)
            'view engineering documents', 'manage engineering documents',

            // CSV Import
            'import csv', 'view import history',

            // Users
            'view users', 'manage users',

            // Audit
            'view audit logs', 'view event logs',

            // System
            'manage system settings',

            // Gate 2 — Company structure
            'view factories', 'manage factories',
            'view divisions', 'manage divisions',
            'view workstation types', 'manage workstation types',
            'view subassemblies', 'manage subassemblies',

            // Gate 3 — Basics
            'view companies', 'manage companies',
            'view anomaly reasons', 'manage anomaly reasons',

            // Gate 4 — HR
            'view wage groups', 'manage wage groups',
            'view crews', 'manage crews',
            'view skills', 'manage skills',
            'view workers', 'manage workers',

            // Gate 5 — Tracking advanced
            'view production anomalies', 'manage production anomalies',

            // Gate 6 — Costing
            'view cost sources', 'manage cost sources',

            // Gate 7 — Maintenance
            'view tools', 'manage tools',
            'view maintenance events', 'manage maintenance events',

            // Attachments (cross-cutting)
            'manage attachments',
        ];

        // Per-tab access permissions (tab:<key>) backing the Settings → Access
        // role × tab matrix. Admin gets all of them via syncPermissions below;
        // other roles start with none (they have no admin-panel access today).
        $permissions = array_merge($permissions, TabRegistry::permissions());

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Admin — all permissions
        $adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        // This seeder runs on every container start (docker-entrypoint.sh), so
        // syncPermissions() must not clobber the per-role tab:* grants an admin
        // configured through Settings → Access. Capture them and merge them back
        // into the canonical operational set below. Admin keeps all tabs anyway
        // (Permission::all()); fresh installs have none, so nothing leaks.
        $keepTabs = fn (Role $role) => $role->permissions
            ->pluck('name')
            ->filter(fn ($name) => str_starts_with($name, 'tab:'))
            ->all();

        // Supervisor — operational read + production management
        $supervisorRole = Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web']);
        $supervisorRole->syncPermissions(array_merge($keepTabs($supervisorRole), [
            'view work orders', 'create work orders', 'edit work orders',
            // Supervisors are the reviewers on the shop floor: they approve and apply
            // production changes (#182).
            'approve work order changes',
            // The one admin tab a supervisor gets. Everything else they need has
            // its own route under /supervisor (gated by role) — customers,
            // priority rules, CSV import, the shift monitor — but change control
            // (#182) lives on the /admin work-order pages, and a supervisor who
            // cannot open those cannot stop a line or review the change that
            // releases it. `orders` is scoped to exactly those screens; the
            // commercial pages that used to share it are on `order_data`, which
            // supervisors do not hold. An admin can grant more through
            // Settings → Access if a particular plant wants it.
            'tab:orders',
            'create batches',
            'start batch step', 'complete batch step',
            // Supervisors release cartons too and may manage PACK work orders,
            // but never process the PA outbox.
            'dispatch packing', 'manage packing work orders',
            'view issues', 'create issues', 'assign issues', 'resolve issues', 'close issues',
            'view lines', 'view products', 'view process templates',
            'view engineering documents', 'manage engineering documents',
            'import csv', 'view import history',
            'view users', 'view audit logs', 'view event logs',
            // Gate 2
            'view factories', 'view divisions', 'view workstation types', 'view subassemblies',
            // Gate 3
            'view companies', 'view anomaly reasons',
            // Gate 4
            'view wage groups', 'view crews', 'view skills', 'view workers',
            // Gate 5
            'view production anomalies', 'manage production anomalies',
            // Gate 6
            'view cost sources',
            // Gate 7
            'view tools', 'view maintenance events',
            // Supervisors may request every machine command, including the
            // destructive manual M30.
            'request machine load', 'request machine labels', 'request machine manual m30',
        ]));

        // Operator — minimal: view queue + execute steps + report issues
        $operatorRole = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
        $operatorRole->syncPermissions(array_merge($keepTabs($operatorRole), [
            'view work orders',
            'start batch step', 'complete batch step',
            'view issues', 'create issues',
            // Operators must be able to view/download engineering docs at the station.
            'view engineering documents',
            // Operators may load NC and generate labels, never trigger M30.
            'request machine load', 'request machine labels',
        ]));

        // PackingWorker — the human packing station. Sees work orders, creates
        // cartons and dispatches them; the PACKING line scope is enforced by the
        // policy/trait, not by the role name.
        $packingWorkerRole = Role::firstOrCreate(['name' => 'PackingWorker', 'guard_name' => 'web']);
        $packingWorkerRole->syncPermissions(array_merge($keepTabs($packingWorkerRole), [
            'view work orders',
            'dispatch packing',
        ]));

        // PackingBridge — the machine account used by Production Automation to
        // create PACK work orders, push expected codes, complete them and process
        // the dispatch outbox. No CNC create/edit rights.
        $packingBridgeRole = Role::firstOrCreate(['name' => 'PackingBridge', 'guard_name' => 'web']);
        $packingBridgeRole->syncPermissions(array_merge($keepTabs($packingBridgeRole), [
            'view work orders',
            'manage packing work orders',
            'process packing dispatch',
        ]));

        // MachineBridge — the machine gateway account (RQ-189). Least privilege:
        // it reads work orders/connections and drives the batch step lifecycle
        // for its own line so it can open a batch and report machine completion.
        // Batch creation (RQ-190) is scoped to that line by the policy, so the
        // bridge no longer needs the broad 'create work orders' grant.
        // It may not request machine commands (that is the human operator
        // action) and holds no edit/delete work order grants.
        $machineBridgeRole = Role::firstOrCreate(['name' => 'MachineBridge', 'guard_name' => 'web']);
        $machineBridgeRole->syncPermissions(array_merge($keepTabs($machineBridgeRole), [
            'view work orders', 'create batches',
            'start batch step', 'start system fanout', 'complete batch step',
            'process machine commands',
            'view lines', 'view workstation types',
        ]));
    }
}
