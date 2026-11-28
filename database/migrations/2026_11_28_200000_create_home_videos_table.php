<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The videos of the home page's "See how it works" section and of the Videos page, managed in the admin (Home videos).
        // A video is either a direct web address of an MP4 or WebM file (`url`) or a file uploaded to the public disk (`file`).
        // While this table has no rows the page uses the list in config/homepage.php. Owned by the storefront.
        Schema::create('home_videos', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 150);
            $table->string('caption', 255)->nullable();
            $table->string('source', 10)->default('url');
            $table->string('url', 500)->nullable();
            $table->string('file', 500)->nullable();
            $table->string('link_route', 60)->nullable();
            $table->string('link_label', 100)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_videos');
    }
};
