<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07 §4.5 — optional part metadata carried with a part label.
 *
 * PA sends these fields alongside the code; MES stores them for packing lists.
 * Every column is nullable so an older PA (three fields only) and legacy codes
 * keep working, and none of them take part in the codes hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_eans', function (Blueprint $table) {
            $table->string('part_name', 150)->nullable()->after('ean');
            $table->string('material', 100)->nullable()->after('part_name');
            $table->decimal('width_mm', 10, 2)->nullable()->after('material');
            $table->decimal('height_mm', 10, 2)->nullable()->after('width_mm');
            $table->decimal('thickness_mm', 10, 2)->nullable()->after('height_mm');
            $table->string('sheet_name', 150)->nullable()->after('thickness_mm');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_eans', function (Blueprint $table) {
            $table->dropColumn([
                'part_name',
                'material',
                'width_mm',
                'height_mm',
                'thickness_mm',
                'sheet_name',
            ]);
        });
    }
};
