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
        $prefix = config('lunar.database.table_prefix');

        Schema::create('vendor_field_locks', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('product_id')->constrained($prefix.'products');
            // A product attribute handle, e.g. `description`. A locked field
            // is never overwritten by an import.
            $table->string('field', 64);
            $table->timestamps();

            $table->unique(['product_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_field_locks');
    }
};
