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
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            // catalogue, stock, images
            $table->string('type', 16);
            // running, succeeded, failed, aborted
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('seen')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('drafted')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('out_of_scope')->default(0);
            // Capped list of report entries.
            $table->json('messages')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
