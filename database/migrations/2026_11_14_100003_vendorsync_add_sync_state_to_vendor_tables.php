<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owned by the ingestion module. The storefront never depends on these.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_products', function (Blueprint $table): void {
            // Hash of the payload at the last successful write.
            $table->char('payload_hash', 64)->nullable()->after('commodity_code');
            // Hash of the last vendor value per content field, to tell a
            // vendor change from an admin edit.
            $table->json('content_hashes')->nullable()->after('payload_hash');
            // Why the product is draft. Empty means it may be published.
            $table->json('draft_reasons')->nullable()->after('content_hashes');
            // The vendor's related product IDs, resolved to cross-sells.
            $table->json('related_ids')->nullable()->after('draft_reasons');
            // Consecutive successful full runs the product was absent from.
            $table->unsignedTinyInteger('missed_runs')->default(0)->after('related_ids');
            // Hash of the image source URL set last synchronised.
            $table->char('image_urls_hash', 64)->nullable()->after('missed_runs');
            $table->timestamp('images_synced_at')->nullable()->after('image_urls_hash');
        });

        Schema::table('vendor_offers', function (Blueprint $table): void {
            // False when a rule forces the variant to stock 0 (no cost, cost
            // at or above RRP, discontinued colour or product). The stock
            // sync never raises the stock of an unsellable offer.
            $table->boolean('sellable')->default(true)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_offers', function (Blueprint $table): void {
            $table->dropColumn('sellable');
        });

        Schema::table('vendor_products', function (Blueprint $table): void {
            $table->dropColumn([
                'payload_hash', 'content_hashes', 'draft_reasons', 'related_ids',
                'missed_runs', 'image_urls_hash', 'images_synced_at',
            ]);
        });
    }
};
