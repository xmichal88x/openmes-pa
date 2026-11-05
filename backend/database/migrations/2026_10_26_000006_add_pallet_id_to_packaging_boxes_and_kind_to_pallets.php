<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07 §4.6 / R07-15 — two disjoint pallet modes.
 *
 * loose (default): parts are scanned straight onto the pallet, as today.
 * boxes: the pallet holds closed cartons only; qty is the sum of their
 * part_count. pallet_id on a box is null when the box is not on a pallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packaging_boxes', function (Blueprint $table) {
            $table->foreignId('pallet_id')
                ->nullable()
                ->after('work_order_id')
                ->constrained('pallets')
                ->nullOnDelete();

            $table->index('pallet_id');
            $table->index(['pallet_id', 'status']);
        });

        Schema::table('pallets', function (Blueprint $table) {
            $table->string('kind', 20)->default('loose')->comment('loose | boxes');
        });
    }

    public function down(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('packaging_boxes', function (Blueprint $table) {
            // SQLite refuses to drop a column still referenced by an index.
            $table->dropIndex(['pallet_id']);
            $table->dropIndex(['pallet_id', 'status']);
            $table->dropConstrainedForeignId('pallet_id');
        });
    }
};
