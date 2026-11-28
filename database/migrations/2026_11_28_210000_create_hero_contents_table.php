<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sets of words for the home page hero, managed in the admin (Hero content): heading, sub-heading and the first button
        // (its wording and where it goes). One active set is picked at random on every page load. While there are no rows the
        // headings of config/homepage.php are used. The second button is fixed. Owned by the storefront.
        Schema::create('hero_contents', function (Blueprint $table): void {
            $table->id();
            $table->string('heading', 200);
            $table->string('sub_heading', 300)->nullable();
            $table->string('cta_text', 60);
            $table->string('cta_link', 500);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_contents');
    }
};
