<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `good_produced` becomes nullable: null means "not derivable", never zero.
 *
 * Quality now rests on the evidence a batch carries (`QualityFacts`). When a
 * batch in the window has no `produced_qty_source`, its produced figure is
 * neither attempts nor good units, so the window has no good-quantity at all -
 * and a stored 0 would read as "everything was scrap". `quality_pct` already
 * carries the same absence as null; this column follows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oee_records', function (Blueprint $table) {
            $table->decimal('good_produced', 12, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('oee_records', function (Blueprint $table) {
            $table->decimal('good_produced', 12, 2)->nullable(false)->default(0)->change();
        });
    }
};
