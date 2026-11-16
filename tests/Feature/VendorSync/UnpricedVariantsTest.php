<?php

declare(strict_types=1);

use App\Domain\Catalogue\Search\StorefrontProductIndexer;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\VendorProduct;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    config()->set('vendorsync.markup', '1.5');
});

/**
 * A product whose only SKUs are priced in EUR: imported, unpriced, draft.
 */
function unpricedProduct(): Product
{
    $detail = VendorSync::detail(800100, 'EURSHOE', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $detail['colors'][0]['skus'][0]['customerPrice'] = ['currency' => 'EUR', 'value' => 30.0];
    $detail['colors'][0]['skus'][0]['rrpPrice'] = ['currency' => 'EUR', 'value' => 58.9];

    VendorSync::fakeHttp([['id' => 800100]], [800100 => $detail]);
    VendorSync::import('SJ');
    VendorSync::syncImages(['800100']);

    return VendorSync::product('SJ', '800100');
}

it('cannot publish a product that also has a non_gbp_price, cost_at_or_above_rrp or vendor_discontinued reason, whatever the images do', function (string $reason): void {
    $product = unpricedProduct();
    $link = VendorProduct::query()->sole();

    // The product has its image now, so no_image is gone. Add the other reason
    // as a catalogue write would have stored it, then let the image pipeline re-evaluate.
    $link->update(['draft_reasons' => [$reason]]);
    VendorSync::syncImages(['800100'], refresh: true);

    expect($product->refresh()->getMedia('images'))->not->toBeEmpty()
        ->and($product->status)->toBe('draft')
        ->and($link->refresh()->draft_reasons)->toBe([$reason]);
})->with([DraftReason::NON_GBP_PRICE, DraftReason::COST_AT_OR_ABOVE_RRP, DraftReason::VENDOR_DISCONTINUED]);

it('publishes once the last reason is gone, so the guard above is the reasons and not the images', function (): void {
    $product = unpricedProduct();

    VendorProduct::query()->sole()->update(['draft_reasons' => []]);
    VendorSync::syncImages(['800100'], refresh: true);

    expect($product->refresh()->status)->toBe('published');
});

it('indexes a draft product with an unpriced variant without a price', function (): void {
    $product = unpricedProduct();

    $document = app(StorefrontProductIndexer::class)->toSearchableArray($product);

    expect(Price::query()->count())->toBe(0)
        ->and($document['price_min_ex_vat'])->toBeNull()
        ->and($document)->toHaveKey('price_min_ex_vat')
        ->and($document['in_stock'])->toBeFalse()
        ->and($product->status)->toBe('draft');
});

it('opens the admin pages of a product with an unpriced variant', function (string $page): void {
    $product = unpricedProduct();

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff')
        ->get("/lunar/products/{$product->id}/{$page}")
        ->assertOk();
})->with(['edit', 'pricing', 'variants']);
