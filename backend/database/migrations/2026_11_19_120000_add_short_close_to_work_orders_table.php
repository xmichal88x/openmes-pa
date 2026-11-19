<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit short close of a work order (P1/M-1).
 *
 * When production delivered fewer good units than planned, a Supervisor may
 * close the order with a documented shortage instead of leaving it open: the
 * timestamp, the user who decided and the reason form the trace required by
 * ISO 9001 §8.7. All three columns are nullable — an order closed at full
 * quantity simply never sets them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('short_closed_at')->nullable()->after('completed_at');
            $table->foreignId('short_closed_by_id')->nullable()->after('short_closed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('short_close_reason')->nullable()->after('short_closed_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['short_closed_by_id']);
            $table->dropColumn(['short_closed_at', 'short_closed_by_id', 'short_close_reason']);
        });
    }
};
