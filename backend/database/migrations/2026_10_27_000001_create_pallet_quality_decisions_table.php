<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual quality decision for a pallet before it is dispatched (#R07).
 *
 * The decision is append-only: it records who set pass/fail and why, while
 * pallets.quality_status keeps the current value for the dispatch gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pallet_quality_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pallet_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->text('reason')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['pallet_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_quality_decisions');
    }
};
