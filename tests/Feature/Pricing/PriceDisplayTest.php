<?php

declare(strict_types=1);

use App\Domain\Catalogue\Features\FeatureLabels;
use App\Domain\Pricing\PriceDisplay;
use Lunar\Models\ProductVariant;

function displayVariant(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->sole();
}

it('prices a variant ex VAT and says so', function (): void {
    $price = app(PriceDisplay::class)->forVariant(displayVariant('SJ-1492552'));

    // The stored price is ex VAT, and nothing adds VAT to it.
    expect($price->price->value)->toBe(6890)
        ->and($price->formatted())->toBe('£68.90')
        ->and($price->suffix())->toBe('ex VAT')
        ->and($price->vatAppliesForEmployers)->toBeFalse();
});

it('labels the compare price as the manufacturer\'s RRP, ex VAT', function (): void {
    $price = app(PriceDisplay::class)->forVariant(displayVariant('SJ-1492552'));

    expect($price->comparePrice->value)->toBe(7650)
        ->and($price->rrpFormatted())->toBe('£76.50');
});

it('shows a zero-protective variant ex VAT and flags the employer rate', function (): void {
    $price = app(PriceDisplay::class)->forVariant(displayVariant('FS-01049-00848-03'));

    expect($price->price->value)->toBe(5248)
        ->and($price->rrpFormatted())->toBe('£59.99')
        ->and($price->vatAppliesForEmployers)->toBeTrue();
});

it('has no compare price when the variant has none', function (): void {
    expect(app(PriceDisplay::class)->forVariant(displayVariant('SJ-1342249'))->comparePrice)->toBeNull();
});

it('finds the lowest variant price of a product', function (): void {
    $product = displayVariant('FS-00048-00480-03')->product->load('variants.prices');

    expect(app(PriceDisplay::class)->fromPrice($product)->price->value)->toBe(1498);
});

it('labels every feature code in the contract', function (string $code): void {
    expect(FeatureLabels::label($code))->not->toBe($code)
        ->and(FeatureLabels::tooltip($code))->not->toBeEmpty();
})->with(['SRA', 'SRB', 'SRC', 'SR', 'ESD', 'CI', 'HI', 'HRO', 'WR', 'FO', 'M', 'AN', 'E', 'A', 'P', 'LG', 'SC', 'WRU', 'METAL_FREE']);

it('uses the labels given in the brief', function (): void {
    expect(FeatureLabels::label('SRC'))->toBe('Slip resistant (SRC)')
        ->and(FeatureLabels::label('ESD'))->toBe('ESD, electrostatic dissipative')
        ->and(FeatureLabels::label('METAL_FREE'))->toBe('Metal free')
        ->and(FeatureLabels::label('NEW_CODE'))->toBe('NEW CODE')
        ->and(FeatureLabels::tooltip('NEW_CODE'))->toBeNull();
});
