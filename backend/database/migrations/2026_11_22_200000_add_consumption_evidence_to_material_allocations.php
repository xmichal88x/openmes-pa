<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The identity of a consumption confirmation (RQ-PA-265 A3).
 *
 * A pilot line settles on the quantity the operation actually retrieved, so the
 * confirmation has to be a document, not a bare number: who declared it, when,
 * from which source, and a key that makes a retried request the same document
 * instead of a second booking. Without these columns a repeated call would look
 * exactly like a fresh one and the stock would move twice.
 *
 * The correction columns are added now, in the same change, because a correction
 * is the same document with a different author and a reason — they are written by
 * A5 and read here so the schema does not have to move twice on a live plant.
 *
 * No foreign keys on purpose: PostgreSQL refuses to add a constraint to an
 * existing table without a full validation pass, and SQLite cannot add one at
 * all. The `*_by_id` columns are plain references to `users.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            // Reference to `users.id` — the person who declared the retrieval.
            $table->unsignedBigInteger('consumption_recorded_by_id')->nullable()->after('consumption_recorded');
            $table->timestamp('consumption_recorded_at')->nullable()->after('consumption_recorded_by_id');
            // Where the number came from: `operator`, `system`, (later) `machine`.
            $table->string('consumption_source', 20)->nullable()->after('consumption_recorded_at');
            // Idempotency: many NULLs are allowed, a repeated non-NULL key is not.
            $table->string('consumption_key', 64)->nullable()->unique()->after('consumption_source');

            // Correction evidence (read by A5, written by A5).
            $table->unsignedBigInteger('correction_recorded_by_id')->nullable()->after('consumption_key');
            $table->timestamp('correction_recorded_at')->nullable()->after('correction_recorded_by_id');
            $table->string('correction_reason', 255)->nullable()->after('correction_recorded_at');
            $table->string('correction_reference', 64)->nullable()->after('correction_reason');
        });
    }

    public function down(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->dropUnique(['consumption_key']);
            $table->dropColumn([
                'consumption_recorded_by_id',
                'consumption_recorded_at',
                'consumption_source',
                'consumption_key',
                'correction_recorded_by_id',
                'correction_recorded_at',
                'correction_reason',
                'correction_reference',
            ]);
        });
    }
};
