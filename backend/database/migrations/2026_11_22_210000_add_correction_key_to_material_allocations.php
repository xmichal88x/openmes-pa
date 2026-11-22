<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency key of a settlement correction (RQ-PA-265 A5).
 *
 * A correction is a compensation of its own and must never be posted twice: a
 * request retried after a timeout would otherwise credit or debit the store a
 * second time. The key makes a repeated request the same document — many NULLs
 * are allowed, a repeated non-NULL key is not.
 *
 * No foreign keys: this is a plain string, and SQLite cannot add constraints to
 * an existing table anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->string('correction_key', 64)->nullable()->unique()->after('correction_reference');
        });
    }

    public function down(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->dropUnique(['correction_key']);
            $table->dropColumn('correction_key');
        });
    }
};
