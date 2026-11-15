<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('stores prices exclusive of tax', function (): void {
    expect(config('lunar.pricing.stored_inclusive_of_tax'))->toBeFalse();
});

it('has a public products disk for media', function (): void {
    expect(config('filesystems.disks.products.visibility'))->toBe('public');
});

it('creates the vendor tables and the key registry', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['vendors', 'vendor_products', 'vendor_offers', 'catalogue_keys']);

it('leaves variants and products shareable between vendors', function (): void {
    $unique = fn (string $table): array => collect(Schema::getIndexes($table))
        ->where('unique', true)
        ->where('primary', false)
        ->pluck('columns')
        ->all();

    expect($unique('vendor_products'))->toBe([['vendor_id', 'vendor_product_id']])
        ->and($unique('vendor_offers'))->toBe([['vendor_id', 'vendor_sku']]);
});

it('creates the ingestion module tables', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['sync_runs', 'vendor_raw_records', 'vendor_field_locks']);
