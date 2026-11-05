<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07 §4.2 — cartons (packaging boxes) for panel packing.
 *
 * A box is an ad-hoc container created by the operator while packing; it is
 * not planned. Membership of the parts is stored only as
 * work_order_eans.packaging_box_id (M3), so part_count is a cache and there is
 * deliberately no label_ids column.
 *
 * No soft deletes: only an empty open box can be physically removed. History
 * lives in packaging_dispatches.box_manifest and packaging_scan_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('box_no')->comment('Sequence within the work order, >= 1');
            $table->string('box_id', 150)->unique()->comment('BOX-<Woo order no>-<n>, scan alias');
            $table->unsignedInteger('part_count')->default(0)->comment('Denormalised cache of work_order_eans.packaging_box_id');
            $table->string('status', 20)->default('open')->comment('open | closed | void');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('label_printed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'box_no']);
            $table->index('work_order_id');
            $table->index(['work_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_boxes');
    }
};
