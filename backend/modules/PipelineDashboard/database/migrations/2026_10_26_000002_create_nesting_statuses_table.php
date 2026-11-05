<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nesting_statuses')) {
            return;
        }

        Schema::create('nesting_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 64)->unique();
            $table->string('status', 32);
            $table->jsonb('details')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nesting_statuses');
    }
};
