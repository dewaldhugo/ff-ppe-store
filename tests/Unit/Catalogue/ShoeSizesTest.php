<?php

declare(strict_types=1);

use App\Domain\Catalogue\Options\ShoeSizes;

describe('naming', function (): void {
    it('names a size by its scale, never by VAT', function (string $uk, ?float $eu, string $expected): void {
        expect(ShoeSizes::name($uk, $eu))->toBe($expected);
    })->with([
        'infant 6' => ['6', 23.0, 'Kids 6'],
        'infant 13' => ['13', 32.0, 'Kids 13'],
        'just under 33' => ['2', 32.5, 'Kids 2'],
        'junior 1' => ['1', 33.0, '1'],
        'junior 2' => ['2', 35.0, '2'],
        'junior 6' => ['6', 39.0, '6'],
        'adult 10' => ['10', 44.0, '10'],
        'no EU size' => ['6', null, '6'],
    ]);

    it('recognises an infant value', function (): void {
        expect(ShoeSizes::isInfant('Kids 6'))->toBeTrue()
            ->and(ShoeSizes::isInfant('6'))->toBeFalse();
    });
});

describe('sorting', function (): void {
    it('sorts by canonical EU size', function (string $name, float $expected): void {
        expect(ShoeSizes::sort($name))->toBe($expected);
    })->with([
        ['Kids 6', 23.0],
        ['Kids 7', 24.0],
        ['Kids 13', 32.0],
        ['1', 33.0],
        ['2', 34.0],
        ['3', 35.0],
        ['3.5', 36.0],
        ['6', 39.0],
        ['10', 44.0],
        ['13.5', 49.0],
        ['14', 50.0],
        // A jump size sorts by its first part.
        ['3/3.5', 35.0],
        ['13.5/14', 49.0],
    ]);

    it('places sizes outside the tables in order', function (): void {
        // Between entries, and beyond both ends.
        expect(ShoeSizes::sort('5.5'))->toBe(38.5)
            ->and(ShoeSizes::sort('9.5'))->toBe(43.5)
            ->and(ShoeSizes::sort('15'))->toBe(51.0)
            ->and(ShoeSizes::sort('Kids 5'))->toBe(22.0)
            ->and(ShoeSizes::sort('nonsense'))->toBe(0.0);
    });

    it('orders infant, junior and adult sizes on one scale', function (): void {
        $names = ['Kids 6', 'Kids 13', '1', '2', '3', '3.5', '4.5', '5.5', '6', '10', '13', '14', '15'];
        $sorts = array_map(ShoeSizes::sort(...), $names);

        expect($sorts)->toBe(collect($sorts)->sort()->values()->all())
            ->and(count(array_unique($sorts)))->toBe(count($sorts));
    });
});
