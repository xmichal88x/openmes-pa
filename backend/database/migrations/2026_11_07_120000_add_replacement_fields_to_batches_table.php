<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replacement batches (RQ-PA-214).
 *
 * A replacement batch re-cuts a defective batch without inflating the work
 * order's planned quantity. `is_replacement` marks it, `replaces_batch_id`
 * points at the batch it compensates for. Several replacements may target the
 * same batch (one per re-cut), so the reference is indexed but not unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->boolean('is_replacement')->default(false)->after('status');
            $table->foreignId('replaces_batch_id')->nullable()->after('is_replacement')
                ->constrained('batches')->nullOnDelete();
            $table->index('replaces_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['replaces_batch_id']);
            $table->dropIndex(['replaces_batch_id']);
            $table->dropColumn(['is_replacement', 'replaces_batch_id']);
        });
    }
};
