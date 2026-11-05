<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1a — machine command outbox.
 *
 * One row is an operator/system intent that the machine gateway must execute
 * (load an NC program, trigger a manual M30, generate labels). MES only owns
 * the intent and its result; the gateway consumes pending rows and reports
 * back. Append-only: cancelling sets status, it does not delete the row.
 *
 * "One active command per step and type" is a partial unique index. SQLite
 * (test suite) supports partial indexes as well, so the rule is enforced on
 * both drivers instead of being mirrored in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_step_id')->nullable()->constrained('batch_steps')->nullOnDelete();
            $table->foreignId('line_id')->constrained('lines')->cascadeOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('workstations')->nullOnDelete();

            // Audit snapshots — the gateway only ever reads the codes/names it
            // was told about, never the live rows.
            $table->string('workstation_code', 64)->nullable();
            $table->string('step_name', 255)->nullable();
            $table->string('step_kind', 32)->nullable();

            $table->string('type', 32)->comment('load_nc | manual_m30 | generate_labels');
            $table->string('status', 20)->default('pending')->comment('pending | processing | done | failed | cancelled');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('outcome', 32)->nullable()->comment('ok | already_loaded | already_exists | skipped | duplicate');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('error_class', 32)->nullable()->comment('transient | permanent | conflict | guard | post_effect');
            $table->uuid('delivery_id')->nullable()->unique()->comment('Gateway delivery id — only manual_m30');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['work_order_id', 'status']);
            $table->index(['workstation_id', 'status']);
            $table->index(['batch_step_id', 'status']);
            $table->index(['type', 'status']);
        });

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            // A step+type pair is unique while a command is still active.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX machine_commands_step_type_active_unique
                    ON machine_commands (batch_step_id, type)
                    WHERE status IN ('pending', 'processing') AND batch_step_id IS NOT NULL
                SQL);

            // Fallback for commands without a step (not used by P1a, kept for
            // forward compatibility with WO-level intents).
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX machine_commands_wo_workstation_type_active_unique
                    ON machine_commands (work_order_id, workstation_id, type)
                    WHERE status IN ('pending', 'processing') AND batch_step_id IS NULL
                SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS machine_commands_step_type_active_unique');
            DB::statement('DROP INDEX IF EXISTS machine_commands_wo_workstation_type_active_unique');
        }

        Schema::dropIfExists('machine_commands');
    }
};
