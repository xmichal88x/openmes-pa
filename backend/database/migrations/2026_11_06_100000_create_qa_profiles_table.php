<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_type_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('product_type_code', 50)->unique();
            $table->boolean('required')->default(true);
            $table->json('checklist_json')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_profiles');
    }
};
