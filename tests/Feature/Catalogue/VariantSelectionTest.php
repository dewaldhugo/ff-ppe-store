<?php

declare(strict_types=1);

use App\Domain\Catalogue\Variants\VariantSelection;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Tests\Support\CatalogueFactory;

function fixtureProduct(string $slug): Product
{
    return Url::query()->where('element_type', Product::morphName())->where('slug', $slug)->sole()->element;
}

/**
 * @return array<string, bool> Availability by value key.
 */
function availability(VariantSelection $selection, string $handle): array
{
    $option = collect($selection->options())->firstWhere('handle', $handle);

    return collect($option['values'])->mapWithKeys(fn (array $value): array => [$value['key'] => $value['available']])->all();
}

it('needs a value for every option before adding to cart', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('safety-jogger-ligero'));

    expect($selection->isComplete())->toBeFalse()
        ->and($selection->missing())->toBe(['shoe_size'])
        ->and($selection->variant())->toBeNull()
        ->and($selection->canAddToCart())->toBeFalse()
        ->and($selection->promptFor('shoe_size'))->toBe('Please select a size');

    $selection->select('shoe_size', '8');

    expect($selection->isComplete())->toBeTrue()
        ->and($selection->variant()->sku)->toBe('SJ-1492607')
        ->and($selection->canAddToCart())->toBeTrue();
});

it('selects an option with a single value automatically and marks it fixed', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('amblers-safety-fs9'));
    $colour = collect($selection->options())->firstWhere('handle', 'colour');

    expect($colour['fixed'])->toBeTrue()
        ->and($selection->selectedName('colour'))->toBe('Black')
        ->and($selection->missing())->toBe(['shoe_size']);

    // A fixed option cannot be cleared.
    $selection->select('colour', null);

    expect($selection->selectedKey('colour'))->toBe('black');
});

it('lets a product with one variant and no options be added directly', function (): void {
    $product = CatalogueFactory::product('Plain', [['stock' => 4]]);
    $selection = VariantSelection::forProduct($product);

    expect($selection->options())->toBe([])
        ->and($selection->isComplete())->toBeTrue()
        ->and($selection->canAddToCart())->toBeTrue()
        ->and($selection->variant()->id)->toBe($product->variants->first()->id);
});

it('disables values that lead to an out of stock variant', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('safety-jogger-ligero'), ['colour' => 'black']);

    // Black 9.5 has no stock; every other Black size does.
    $sizes = availability($selection, 'shoe_size');

    expect($sizes['9.5'])->toBeFalse()
        ->and(array_filter($sizes))->toHaveCount(11);
});

it('disables values that lead to a variant that does not exist', function (): void {
    $product = CatalogueFactory::product('Gaps', [
        ['colour' => 'Test Red', 'size' => '6', 'stock' => 5],
        ['colour' => 'Test Red', 'size' => '7', 'stock' => 5],
        ['colour' => 'Test Green', 'size' => '6', 'stock' => 5],
    ]);

    $selection = VariantSelection::forProduct($product, ['colour' => 'test-green']);

    expect(availability($selection, 'shoe_size'))->toBe(['6' => true, '7' => false]);
});

it('re-evaluates availability after each selection', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('safety-jogger-ligero'));

    // Navy is pre-selected and every colour is available.
    expect(availability($selection, 'colour'))->toBe(['navy' => true, 'black' => true, 'orange' => true]);

    $selection->select('shoe_size', '9.5');

    // With 9.5 chosen, Black is no longer reachable.
    expect(availability($selection, 'colour'))->toBe(['navy' => true, 'black' => false, 'orange' => true]);

    $selection->select('shoe_size', '9');

    expect(availability($selection, 'colour'))->toBe(['navy' => true, 'black' => true, 'orange' => true]);
});

it('pre-selects the first in-stock colour and never a size', function (): void {
    $product = CatalogueFactory::product('Defaults', [
        ['colour' => 'Test Red', 'size' => '6', 'stock' => 0],
        ['colour' => 'Test Green', 'size' => '6', 'stock' => 3],
        ['colour' => 'Test Green', 'size' => '7', 'stock' => 3],
    ]);

    $selection = VariantSelection::forProduct($product);

    expect($selection->selectedKey('colour'))->toBe('test-green')
        ->and($selection->selectedKey('shoe_size'))->toBeNull();

    expect(VariantSelection::forProduct(fixtureProduct('safety-jogger-ligero'))->selected())->toBe(['colour' => 'navy']);
});

it('selects no colour when every colour is out of stock', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('maxguard-sx310'));

    // SX310 has a single colour, so it is fixed even though nothing is in stock.
    expect($selection->selectedKey('colour'))->toBe('dark-red')
        ->and(array_filter(availability($selection, 'shoe_size')))->toBe([]);

    $product = CatalogueFactory::product('Sold Out', [
        ['colour' => 'Test Red', 'size' => '6', 'stock' => 0],
        ['colour' => 'Test Green', 'size' => '6', 'stock' => 0],
    ]);

    expect(VariantSelection::forProduct($product)->selectedKey('colour'))->toBeNull();
});

it('restores a requested colour and ignores an unknown one', function (): void {
    $ligero = fixtureProduct('safety-jogger-ligero');

    expect(VariantSelection::forProduct($ligero, ['colour' => 'orange'])->selectedKey('colour'))->toBe('orange')
        ->and(VariantSelection::forProduct($ligero, ['colour' => 'purple'])->selectedKey('colour'))->toBe('navy');
});

it('reports an out of stock variant as not addable', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('maxguard-sx310'));
    $selection->select('shoe_size', '8');

    expect($selection->isComplete())->toBeTrue()
        ->and($selection->variant())->not->toBeNull()
        ->and($selection->canAddToCart())->toBeFalse();
});

it('orders sizes numerically', function (): void {
    $selection = VariantSelection::forProduct(fixtureProduct('magnum-classic'));
    $sizes = collect($selection->options())->firstWhere('handle', 'shoe_size');

    expect(array_column($sizes['values'], 'key'))->toBe(['3', '4', '5', '7', '13', '14']);
});
