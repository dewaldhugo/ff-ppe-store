<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owned by the ingestion module.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            // Variants a vendor priced in a currency other than GBP (left unpriced).
            $table->unsignedInteger('non_gbp_variants')->default(0)->after('out_of_scope');
            $table->unsignedInteger('non_gbp_products')->default(0)->after('non_gbp_variants');
            // Variants whose cost changed by more than the guard allows (price, cost and compare price kept).
            $table->unsignedInteger('held_prices')->default(0)->after('non_gbp_products');
            // The full report of the run (a JSON file), written when prices were held.
            $table->string('report_path', 500)->nullable()->after('held_prices');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['non_gbp_variants', 'non_gbp_products', 'held_prices', 'report_path']);
        });
    }
};
