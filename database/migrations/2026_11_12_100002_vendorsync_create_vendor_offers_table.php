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

        Schema::create('vendor_offers', function (Blueprint $table) use ($prefix): void {
            $table->id();
            // Not unique: the same Lunar variant may be supplied by more than one vendor.
            $table->foreignId('product_variant_id')->index()->constrained($prefix.'product_variants');
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->string('vendor_sku');
            $table->string('vendor_product_name');
            $table->string('vendor_colour')->nullable();
            $table->string('vendor_size')->nullable();
            $table->string('ean', 32)->nullable();
            // Pence, ex VAT. Null where the vendor cost is unknown.
            $table->unsignedInteger('cost_price')->nullable();
            $table->integer('stock')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'vendor_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_offers');
    }
};
