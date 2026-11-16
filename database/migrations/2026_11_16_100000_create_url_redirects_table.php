<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paths are compared exactly, never through the database collation, so
        // the columns are binary (case sensitive).
        Schema::create('url_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('old_path')->collation('utf8mb4_bin')->unique();
            $table->string('new_path')->collation('utf8mb4_bin')->index();
            $table->timestamp('created_at')->nullable();
        });

        // The last known path of every category collection. The sync compares
        // it with the live path to find what changed, however it changed.
        Schema::create('collection_path_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collection_id')->unique();
            $table->string('path')->collation('utf8mb4_bin');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_path_snapshots');
        Schema::dropIfExists('url_redirects');
    }
};
