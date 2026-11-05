<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pipeline_logs')) {
            return;
        }

        Schema::create('pipeline_logs', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 64);
            $table->string('stage', 32);
            $table->jsonb('details')->nullable();
            $table->string('source', 32)->nullable();
            $table->timestamp('logged_at')->useCurrent();

            $table->index('order_no');
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_logs');
    }
};
