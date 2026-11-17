<?php

declare(strict_types=1);

use App\Domain\Catalogue\Options\GarmentSizes;

describe('naming', function (): void {
    it('normalises a known vendor spelling to its short form', function (string $raw, string $expected): void {
        expect(GarmentSizes::name($raw))->toBe($expected);
    })->with([
        ['Sml', 'S'], ['sml', 'S'], ['S', 'S'], ['Small', 'S'],
        ['Med', 'M'], ['M', 'M'], ['Medium', 'M'],
        ['Lge', 'L'], ['L', 'L'], ['Large', 'L'],
        ['Xsl', 'XS'], ['XS', 'XS'], ['Xsmall', 'XS'],
        ['XL', 'XL'], ['Xlarge', 'XL'],
        ['XXL', 'XXL'], ['2XL', 'XXL'], ['Xxlarge', 'XXL'],
        ['3XL', '3XL'], ['4XL', '4XL'], ['5XL', '5XL'], ['6XL', '6XL'],
        ['Xxs', 'XXS'], ['XXSmall', 'XXS'],
    ]);

    it('turns a vendor token for no size at all into One Size', function (string $raw): void {
        expect(GarmentSizes::name($raw))->toBe(GarmentSizes::ONE_SIZE_LABEL);
    })->with(['Itm', 'ITM', 'Item', 'OS', 'One Size', 'UNI', 'Unisize', 'N/A']);

    it('keeps a measurement or unrecognised wording exactly as the vendor gives it', function (string $raw): void {
        expect(GarmentSizes::name($raw))->toBe($raw);
    })->with(['34"', '42', '10', 'W42L30', 'INT', 'Jnr']);

    it('returns null only for a genuinely blank value', function (): void {
        expect(GarmentSizes::name(''))->toBeNull()
            ->and(GarmentSizes::name('   '))->toBeNull();
    });
});

describe('sorting', function (): void {
    it('puts One Size first', function (): void {
        expect(GarmentSizes::sort(GarmentSizes::ONE_SIZE_LABEL))->toBeLessThan(GarmentSizes::sort('XS'));
    });

    it('orders the alphabetic scale', function (): void {
        $names = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL', '6XL'];
        $sorts = array_map(GarmentSizes::sort(...), $names);

        expect($sorts)->toBe(collect($sorts)->sort()->values()->all())
            ->and(count(array_unique($sorts)))->toBe(count($sorts));
    });

    it('sorts numeric and measurement values ascending, after the alphabetic scale', function (): void {
        expect(GarmentSizes::sort('XL'))->toBeLessThan(GarmentSizes::sort('30'))
            ->and(GarmentSizes::sort('30'))->toBeLessThan(GarmentSizes::sort('32"'))
            ->and(GarmentSizes::sort('32"'))->toBeLessThan(GarmentSizes::sort('42'));
    });

    it('sorts unrecognised wording after every known size', function (): void {
        expect(GarmentSizes::sort('W42L30'))->toBeGreaterThan(GarmentSizes::sort('42'))
            ->and(GarmentSizes::sort('W42L30'))->toBeGreaterThan(GarmentSizes::sort(GarmentSizes::ONE_SIZE_LABEL));
    });
});
