<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Product Spotlight campaigns of the home page, managed in the admin (Spotlight banners): an uploaded banner picture and the
        // product it is about. One active set is picked at random on every page load. Once there is a row, these replace the
        // campaigns of config/homepage.php. Rows go when the product is deleted for good. Owned by the storefront.
        Schema::create('spotlight_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('lunar_products')->cascadeOnDelete();
            $table->string('image', 500);
            $table->string('alt', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spotlight_banners');
    }
};
