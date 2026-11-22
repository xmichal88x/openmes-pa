<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The settlement terms a confirmation was signed under, frozen on the allocation
 * (RQ-PA-265 A7).
 *
 * The correction path used to ask the work order which scope settles the
 * material. That answer is not stable: once the withdrawal is settled the pilot
 * may be switched off, and a correction of a line settled by its operation
 * would then be refused as "not a pilot line" — a real defect found in the
 * pilot simulation. The terms therefore live where the confirmation lives.
 *
 * Values: `operation` (the operation declared the retrieval) or `legacy` (the
 * pre-existing batch-level settlement). NULL means the allocation was written
 * before this column existed, or that nothing was confirmed at all — there are
 * no frozen terms to correct against.
 *
 * No foreign keys and no backfill: a NULL stays NULL, and a line confirmed
 * under this version records its own mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->string('settlement_mode', 12)->nullable()->after('consumption_source');
        });
    }

    public function down(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->dropColumn('settlement_mode');
        });
    }
};
