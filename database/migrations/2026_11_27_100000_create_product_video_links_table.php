<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Videos that staff add to a product by web address (YouTube, Vimeo, or a direct MP4 or WebM file). Owned by the
        // storefront: the ingestion module never writes here, and the vendor's own `video_url` attribute is not touched.
        // Rows go when the product is deleted for good.
        Schema::create('product_video_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('lunar_products')->cascadeOnDelete();
            $table->string('url', 500);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_video_links');
    }
};
