<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who owns the balance of a material, and where the number came from (F1-1).
 *
 * Dolibarr owns raw materials and their valuation; MES owns WIP. Without an
 * explicit owner per SKU nobody can say which side is authoritative for
 * `stock_quantity`, and a number of unknown provenance gets treated as truth by
 * accident. Both columns therefore start at `unknown` for every existing row —
 * the default is a declaration that nothing is known yet, not a guess that the
 * ERP owns it. No backfill: silence is the correct starting state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            // `erp`, `mes`, `unknown` — which system's number is authoritative.
            $table->string('stock_owner', 16)->default('unknown')->index()->after('last_stock_sync_at');
            // `erp_projection`, `opening_declared`, `demo_seed`, `unknown`, `stale`.
            $table->string('balance_source', 24)->default('unknown')->after('stock_owner');
            // When the declared balance was read. Null means no timestamp exists.
            $table->timestamp('balance_as_of')->nullable()->after('balance_source');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['stock_owner']);
            $table->dropColumn(['stock_owner', 'balance_source', 'balance_as_of']);
        });
    }
};
