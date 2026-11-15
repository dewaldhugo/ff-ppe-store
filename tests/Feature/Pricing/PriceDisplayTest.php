<?php

declare(strict_types=1);

use App\Domain\Catalogue\Features\FeatureLabels;
use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\VatDisplayMode;
use Lunar\Models\ProductVariant;

function displayVariant(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->sole();
}

it('always shows the storefront ex VAT', function (): void {
    session(['vat_display_mode' => 'inc']);

    expect(app(PriceDisplay::class)->mode())->toBe(VatDisplayMode::ExVat)
        ->and(VatDisplayMode::default())->toBe(VatDisplayMode::ExVat);
});

it('prices a variant ex VAT when no mode is asked for', function (): void {
    $price = app(PriceDisplay::class)->forVariant(displayVariant('SJ-1492552'));

    expect($price->formatted())->toBe('£68.90')
        ->and($price->suffix())->toBe('ex VAT');
});

it('adds 20% to a standard rated variant in inc VAT mode', function (): void {
    $display = app(PriceDisplay::class);
    $ligero = displayVariant('SJ-1492552');

    $inc = $display->forVariant($ligero, VatDisplayMode::IncVat);
    $ex = $display->forVariant($ligero, VatDisplayMode::ExVat);

    expect($ex->price->value)->toBe(6890)
        ->and($inc->price->value)->toBe(8268)
        ->and($ex->comparePrice->value)->toBe(7650)
        ->and($inc->comparePrice->value)->toBe(9180)
        ->and($inc->formatted())->toBe('£82.68')
        ->and($ex->formatted())->toBe('£68.90')
        ->and($inc->suffix())->toBe('inc VAT')
        ->and($inc->vatAppliesForEmployers)->toBeFalse();
});

it('shows the same price in both modes for a zero-protective variant', function (): void {
    $display = app(PriceDisplay::class);
    $fs9 = displayVariant('FS-01049-00848-03');

    $inc = $display->forVariant($fs9, VatDisplayMode::IncVat);
    $ex = $display->forVariant($fs9, VatDisplayMode::ExVat);

    expect($inc->price->value)->toBe(5248)
        ->and($ex->price->value)->toBe(5248)
        ->and($inc->comparePrice->value)->toBe(5999)
        ->and($inc->vatAppliesForEmployers)->toBeTrue();
});

it('has no compare price when the variant has none', function (): void {
    expect(app(PriceDisplay::class)->forVariant(displayVariant('SJ-1342249'))->comparePrice)->toBeNull();
});

it('finds the lowest variant price of a product', function (): void {
    $product = displayVariant('FS-00048-00480-03')->product->load('variants.prices');

    expect(app(PriceDisplay::class)->fromPrice($product, VatDisplayMode::IncVat)->price->value)->toBe(1798);
});

it('labels every feature code in the contract', function (string $code): void {
    expect(FeatureLabels::label($code))->not->toBe($code)
        ->and(FeatureLabels::tooltip($code))->not->toBeEmpty();
})->with(['SRA', 'SRB', 'SRC', 'SR', 'ESD', 'CI', 'HI', 'HRO', 'WR', 'FO', 'M', 'AN', 'E', 'A', 'P', 'LG', 'SC', 'METAL_FREE']);

it('uses the labels given in the brief', function (): void {
    expect(FeatureLabels::label('SRC'))->toBe('Slip resistant (SRC)')
        ->and(FeatureLabels::label('ESD'))->toBe('ESD, electrostatic dissipative')
        ->and(FeatureLabels::label('METAL_FREE'))->toBe('Metal free')
        ->and(FeatureLabels::label('NEW_CODE'))->toBe('NEW CODE')
        ->and(FeatureLabels::tooltip('NEW_CODE'))->toBeNull();
});
