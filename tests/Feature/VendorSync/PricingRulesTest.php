<?php

declare(strict_types=1);

use App\Domain\VendorSync\Normalisers\PriceCalculator;
use App\Domain\VendorSync\Normalisers\PricingRules;

/**
 * The price a vendor's mapper would give a 10.00 cost with no RRP.
 */
function mapperPrice(string $code): int
{
    $mapper = app("vendorsync.mapper.{$code}");
    $property = new ReflectionProperty($mapper, 'prices');

    /** @var PriceCalculator $calculator */
    $calculator = $property->getValue($mapper);

    return $calculator->calculate(1000, null)['price'];
}

function mapperCalculator(string $code): PriceCalculator
{
    $mapper = app("vendorsync.mapper.{$code}");

    return (new ReflectionProperty($mapper, 'prices'))->getValue($mapper);
}

beforeEach(function (): void {
    config()->set('vendorsync.markup', '1.5');
    config()->set('vendorsync.vendors.SJ.markup', null);
    config()->set('vendorsync.vendors.FS.markup', null);
});

describe('markup per vendor', function (): void {
    it('uses the global default of 1.5 for every vendor', function (): void {
        expect(PricingRules::markup('SJ'))->toBe('1.5')
            ->and(PricingRules::markup('FS'))->toBe('1.5')
            ->and(mapperPrice('SJ'))->toBe(1500)
            ->and(mapperPrice('FS'))->toBe(1500);
    });

    it('lets a vendor override the default without touching the other', function (): void {
        config()->set('vendorsync.vendors.SJ.markup', '1.6');

        expect(mapperPrice('SJ'))->toBe(1600)
            ->and(mapperPrice('FS'))->toBe(1500);
    });

    it('follows a changed global default for vendors without an override', function (): void {
        config()->set('vendorsync.markup', '1.4');
        config()->set('vendorsync.vendors.FS.markup', '1.75');

        expect(mapperPrice('SJ'))->toBe(1400)
            ->and(mapperPrice('FS'))->toBe(1750);
    });

    it('treats an empty override, as an empty env variable gives, as no override', function (): void {
        config()->set('vendorsync.vendors.SJ.markup', '');

        expect(PricingRules::markup('SJ'))->toBe('1.5');
    });

    it('gives each mapper a calculator of its own', function (): void {
        expect(mapperCalculator('SJ'))->not->toBe(mapperCalculator('FS'));
    });
});

describe('markup validation', function (): void {
    it('rejects a global default outside 1.0 to 5 or not a number', function (string $bad): void {
        config()->set('vendorsync.markup', $bad);

        expect(fn () => app('vendorsync.mapper.SJ'))->toThrow(InvalidArgumentException::class, 'VENDORSYNC_MARKUP')
            ->and(fn () => app('vendorsync.mapper.FS'))->toThrow(InvalidArgumentException::class, 'VENDORSYNC_MARKUP');
    })->with(['0.99', '0', '5.01', '6', 'abc', '1,5', '-1.5', '1.5x']);

    it('rejects a vendor override outside 1.0 to 5 or not a number, naming the vendor', function (string $code, string $bad): void {
        config()->set("vendorsync.vendors.{$code}.markup", $bad);

        expect(fn () => app("vendorsync.mapper.{$code}"))
            ->toThrow(InvalidArgumentException::class, "VENDORSYNC_{$code}_MARKUP");
    })->with([
        ['SJ', '0.9'],
        ['SJ', '5.5'],
        ['SJ', 'free'],
        ['FS', '0.5'],
        ['FS', '10'],
        ['FS', '1.5.2'],
    ]);

    it('validates the global default even when a vendor overrides it', function (): void {
        config()->set('vendorsync.markup', '9');
        config()->set('vendorsync.vendors.SJ.markup', '1.5');

        expect(fn () => app('vendorsync.mapper.SJ'))->toThrow(InvalidArgumentException::class);
    });

    it('accepts the limits 1.0 and 5', function (): void {
        config()->set('vendorsync.vendors.SJ.markup', '1.0');
        config()->set('vendorsync.vendors.FS.markup', '5');

        expect(mapperPrice('SJ'))->toBe(1000)
            ->and(mapperPrice('FS'))->toBe(5000);
    });
});
