<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Models\Brand;

return new class extends Migration
{
    public function up(): void
    {
        // How the storefront presents brands: the slug in /brands/{slug}, the
        // logo and its tile colour. Owned by the storefront; the ingestion
        // module never writes here and the Lunar brands table is untouched.
        Schema::create('storefront_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->collation('utf8mb4_bin')->unique();
            $table->string('name');
            // Relative to public/, e.g. images/brands/safety-jogger.png.
            $table->string('logo_path')->nullable();
            // Tile background behind the logo, six hex digits without #. Null is white.
            $table->char('logo_background', 6)->nullable();
            $table->timestamps();
        });

        // Lunar brands shown under one storefront brand, e.g. Puma Safety and
        // Puma Workwear under Puma. A Lunar brand belongs to one storefront brand at most.
        Schema::create('storefront_brand_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storefront_brand_id')->constrained('storefront_brands')->cascadeOnDelete();
            $table->foreignId('brand_id')->unique()->constrained((new Brand)->getTable())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_brand_members');
        Schema::dropIfExists('storefront_brands');
    }
};
