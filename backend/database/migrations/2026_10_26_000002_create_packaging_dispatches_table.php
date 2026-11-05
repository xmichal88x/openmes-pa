<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R07 §4.3 — dispatch outbox + result event.
 *
 * One row is an operator intent to ship a work order's cartons/pallets. P1/PA
 * consumes pending rows and writes the Dolibarr result back (done/failed/
 * skipped/cancelled). Append-only: cancelling sets status, it does not delete.
 *
 * The "one active dispatch per work order" rule is a partial unique index on
 * PostgreSQL. SQLite (test suite) has no driver-neutral partial unique support
 * here, so PackagingDispatch mirrors the rule on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('order_no', 100)->comment('Snapshot of the Woo order number');
            $table->string('customer_order_no', 100)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending | processing | done | failed | skipped | cancelled');
            $table->string('source', 20)->default('operator')->comment('operator | external');
            $table->uuid('idempotency_key')->unique();
            $table->json('scanned_boxes');
            $table->json('scanned_pallets');
            $table->json('box_manifest')->comment('Immutable snapshot of cartons at dispatch time');
            $table->json('pallet_manifest')->comment('Audit snapshot of pallets; PA does not interpret it');
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->decimal('expected_weight_kg', 10, 3)->nullable()->comment('MES-computed sum of box weights; null disables tolerance');
            $table->decimal('weight_deviation_pct', 6, 3)->nullable();
            $table->string('carrier', 100)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable()->comment('Dolibarr shipment id (result)');
            $table->string('shipment_status', 20)->nullable()->comment('draft | validated | closed');
            $table->text('last_error')->nullable();
            $table->string('packing_list_path', 255)->nullable();
            $table->string('boxes_hash', 64)->nullable()->comment('SHA-256 of the manifest used for the PDF');
            $table->timestamp('docs_generated_at')->nullable();
            $table->boolean('docs_stale')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('order_no');
            $table->index(['status', 'id']);
            $table->index(['work_order_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // cancelled is intentionally absent: a cancelled intent must not
            // block a new dispatch for the same work order.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX packaging_dispatches_active_unique
                    ON packaging_dispatches (work_order_id)
                    WHERE status IN ('pending', 'processing')
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS packaging_dispatches_active_unique');
        }

        Schema::dropIfExists('packaging_dispatches');
    }
};
