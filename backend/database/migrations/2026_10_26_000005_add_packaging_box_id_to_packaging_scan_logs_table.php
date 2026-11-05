<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07 §4.4 — audit trail of the carton a scan went into.
 *
 * The assignment itself stays on work_order_eans.packaging_box_id; this column
 * only makes the scan history readable after a box is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packaging_scan_logs', function (Blueprint $table) {
            $table->foreignId('packaging_box_id')
                ->nullable()
                ->after('pallet_id')
                ->constrained('packaging_boxes')
                ->nullOnDelete();

            $table->index('packaging_box_id');
        });
    }

    public function down(): void
    {
        Schema::table('packaging_scan_logs', function (Blueprint $table) {
            // SQLite refuses to drop a column still referenced by an index.
            $table->dropIndex(['packaging_box_id']);
            $table->dropConstrainedForeignId('packaging_box_id');
        });
    }
};
