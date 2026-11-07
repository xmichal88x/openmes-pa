<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic JSON bucket for batch-level metadata (RQ-PA-214).
 *
 * Used by the re-cut path to carry the caller's `idempotency_key` and `reason`
 * without adding dedicated columns (and without a unique index on the mutable
 * `replaces_batch_id`). Additive and nullable, so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->json('extra_data')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('extra_data');
        });
    }
};
