<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Global issue type reported by an operator from MES when the machine gateway
 * still holds a suspended CNC cycle (machine idle, no m30/program_replaced).
 * Idempotent: re-running matches on `code` and refreshes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $existing = DB::table('issue_types')
            ->where('code', 'MACHINE_CYCLE_SUSPENDED')
            ->orderByRaw('deleted_at IS NULL DESC')
            ->orderBy('id')
            ->first();

        $values = [
            'name' => 'Machine Cycle Suspended',
            'severity' => 'HIGH',
            'is_blocking' => false,
            'is_active' => true,
            'tenant_id' => null,
            'deleted_at' => null,
            'deleted_by_id' => null,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('issue_types')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('issue_types')->insert($values + [
            'code' => 'MACHINE_CYCLE_SUSPENDED',
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        // No-op: this type is additive and may already be referenced by
        // issues.issue_type_id (ON DELETE RESTRICT). Disable it with
        // is_active = false instead of deleting the row.
    }
};
