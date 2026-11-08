<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The QA decision that produced a history row (rework / recut / scrap).
 *
 * Set only by internal services - the public step API does not accept it.
 * Nullable on purpose: ordinary process steps carry no QA decision and
 * historical rows are not backfilled. The (decision, id) index is the
 * queue's incremental cursor over re-cut events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_step_history', function (Blueprint $table) {
            $table->string('decision', 20)->nullable()->after('result');
            $table->index(['decision', 'id'], 'unit_step_history_decision_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('unit_step_history', function (Blueprint $table) {
            $table->dropIndex('unit_step_history_decision_id_index');
            $table->dropColumn('decision');
        });
    }
};
