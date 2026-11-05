<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07 §4.4 — one part label belongs to at most one carton.
 *
 * The FK is the only source of truth for box membership; packaging_boxes.
 * part_count is a cache. Physical deletion of an empty box nulls the FK and
 * keeps the label/scanned history intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_eans', function (Blueprint $table) {
            $table->foreignId('packaging_box_id')
                ->nullable()
                ->after('state')
                ->constrained('packaging_boxes')
                ->nullOnDelete();

            $table->index('packaging_box_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_eans', function (Blueprint $table) {
            // SQLite refuses to drop a column still referenced by an index.
            $table->dropIndex(['packaging_box_id']);
            $table->dropConstrainedForeignId('packaging_box_id');
        });
    }
};
