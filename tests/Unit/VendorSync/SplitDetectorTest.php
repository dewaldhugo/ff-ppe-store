<?php

declare(strict_types=1);

use App\Domain\VendorSync\Footsure\ProductSignature;
use App\Domain\VendorSync\Footsure\SplitDetector;

/**
 * @param  array<string, list<string>>  $sizesByColour
 * @param  array<string, string>  $facts
 */
function signature(string $id, array $sizesByColour, string $line = 'hi-tec|squash trainer', array $facts = []): ProductSignature
{
    return new ProductSignature($id, "Squash trainer [{$id}]", $line, [
        'size scale' => 'shoe_size',
        'product type' => 'safety-footwear',
        'category' => 'sports-trainers',
        'safety class' => '',
        'standards' => '',
        'gender' => 'Unisex',
        ...$facts,
    ], $sizesByColour);
}

it('merges size bands of one line that share a colour and do not overlap', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('547', ['White' => ['7', '8', '9']]),
        signature('546', ['White' => ['3', '4', '5']]),
        signature('6983', ['White' => ['14']]),
    ]);

    // Ascending numeric order, so the lowest ProductID is the primary.
    expect($detection->groups)->toBe([['546', '547', '6983']])
        ->and($detection->kept)->toBe([])
        ->and($detection->removed())->toBe(2)
        ->and($detection->primaries())->toBe(['546' => '546', '547' => '546', '6983' => '546']);
});

it('orders ProductIDs numerically, not as text', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('10000', ['White' => ['9']]),
        signature('9999', ['White' => ['3']]),
    ]);

    expect($detection->groups)->toBe([['9999', '10000']]);
});

it('does not merge a colour and size sold under two ProductIDs, and says why', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['White' => ['3', '4', '5']]),
        signature('2', ['White' => ['5', '6']]),
    ]);

    expect($detection->groups)->toBe([])
        ->and($detection->kept)->toHaveCount(1)
        ->and($detection->kept[0]['ids'])->toBe(['1', '2'])
        ->and($detection->kept[0]['reason'])->toContain('size 5 in colour White');
});

it('does not merge products that differ in gender, category, safety class or standards', function (string $fact, string $other): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['White' => ['3', '4']]),
        signature('2', ['White' => ['7', '8']], facts: [$fact => $other]),
    ]);

    expect($detection->groups)->toBe([])
        ->and($detection->kept[0]['reason'])->toContain("differs in {$fact}");
})->with([
    'a men\'s and a unisex version' => ['gender', 'Men'],
    'another category' => ['category', 'casual-footwear'],
    'a certified and an uncertified one' => ['safety class', 'S3'],
    'another standard' => ['standards', 'EN ISO 20345:2022'],
    'another size scale' => ['size scale', 'garment_size'],
]);

it('does not merge products of other brands or names', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['White' => ['3']]),
        signature('2', ['White' => ['7']], line: 'hi-tec|squash trainer ii'),
        signature('3', ['White' => ['8']], line: 'mirak|squash trainer'),
    ]);

    expect($detection->groups)->toBe([])
        ->and($detection->kept)->toBe([]);
});

it('leaves a ProductID per colour alone: no colour in common', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['Black' => ['7', '8']]),
        signature('2', ['Navy' => ['7', '8']]),
    ]);

    expect($detection->groups)->toBe([])
        ->and($detection->kept[0]['reason'])->toBe('no colour in common');
});

it('merges bands that sell different colours as long as one colour links them', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['Lilac' => ['2'], 'Rose' => ['2']]),
        signature('2', ['Lilac' => ['3', '4'], 'Grey' => ['3', '4']]),
    ]);

    expect($detection->groups)->toBe([['1', '2']]);
});

it('merges the bands that fit and reports the odd one out', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['Black' => ['3', '4']]),
        signature('2', ['Black' => ['7', '8']]),
        signature('3', ['Black' => ['7', '8']], facts: ['gender' => 'Men']),
    ]);

    expect($detection->groups)->toBe([['1', '2']])
        ->and($detection->kept)->toHaveCount(1)
        ->and($detection->kept[0]['ids'])->toBe(['3'])
        ->and($detection->kept[0]['reason'])->toContain('differs in gender (Unisex / Men)');
});

it('treats an infant and an adult size of the same number as different sizes', function (): void {
    // The mapper names them `Kids 6` and `6`.
    $detection = (new SplitDetector)->detect([
        signature('1', ['White' => ['Kids 6', 'Kids 7']]),
        signature('2', ['White' => ['6', '7']]),
    ]);

    expect($detection->groups)->toBe([['1', '2']]);
});

it('keeps lines apart that were not split at all', function (): void {
    $detection = (new SplitDetector)->detect([
        signature('1', ['White' => ['3']]),
        signature('2', ['White' => ['3']], line: 'hi-tec|other'),
    ]);

    expect($detection->groups)->toBe([])
        ->and($detection->removed())->toBe(0);
});
