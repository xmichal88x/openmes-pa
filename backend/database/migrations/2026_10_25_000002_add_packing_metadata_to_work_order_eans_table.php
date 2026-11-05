<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_eans', function (Blueprint $table) {
            $table->string('code_type', 20)->default('ean');
            $table->string('label_batch_id', 100)->nullable();
            $table->unsignedInteger('part_seq')->nullable();
            $table->string('state', 20)->default('expected');

            $table->index(['work_order_id', 'state'], 'work_order_eans_work_order_id_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_eans', function (Blueprint $table) {
            $table->dropIndex('work_order_eans_work_order_id_state_index');
            $table->dropColumn(['state', 'part_seq', 'label_batch_id', 'code_type']);
        });
    }
};
