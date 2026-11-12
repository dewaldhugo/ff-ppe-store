<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owned by the ingestion module. Created once in Phase 1; do not modify.
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lunar.database.table_prefix');

        Schema::create('vendor_products', function (Blueprint $table) use ($prefix): void {
            $table->id();
            // Not unique: the same Lunar product may be supplied by more than one vendor.
            $table->foreignId('product_id')->index()->constrained($prefix.'products');
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->string('vendor_product_id');
            $table->string('commodity_code', 32)->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'vendor_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_products');
    }
};
