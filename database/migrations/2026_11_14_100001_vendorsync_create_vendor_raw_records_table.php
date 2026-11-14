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
        Schema::create('vendor_raw_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            // The vendor's product identifier (vendor_products.vendor_product_id).
            $table->string('external_id');
            // The payload as fetched, including data not imported yet
            // (360-degree spin frame URLs, documents).
            $table->json('payload');
            // sha256 of the canonicalised payload.
            $table->char('payload_hash', 64);
            $table->timestamp('fetched_at');
            $table->foreignId('last_seen_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_raw_records');
    }
};
