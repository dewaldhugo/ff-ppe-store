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
            // API request count, response and throttle wait times, own
            // processing time, elapsed time and peak memory of the run.
            $table->json('stats')->nullable()->after('messages');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropColumn('stats');
        });
    }
};
