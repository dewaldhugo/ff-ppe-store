<?php

declare(strict_types=1);

use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Normalisers\Features;
use App\Domain\VendorSync\Normalisers\Money;
use App\Domain\VendorSync\Normalisers\PriceCalculator;
use App\Domain\VendorSync\Normalisers\SafetyClass;
use App\Domain\VendorSync\Normalisers\Sizes;

describe('safety class', function (): void {
    it('removes spaces and hyphens', function (?string $input, ?string $expected): void {
        expect(SafetyClass::normalise($input))->toBe($expected);
    })->with([
        ['S1 P', 'S1P'],
        ['SB-P', 'SBP'],
        ['s3', 'S3'],
        ['S3S', 'S3S'],
        ['OB', 'OB'],
        ['', null],
        [null, null],
    ]);

    it('combines a base class with a separate P', function (string $class, array $codes, string $expected): void {
        expect(SafetyClass::normalise($class, $codes))->toBe($expected);
    })->with([
        ['SB', ['P', 'SRA'], 'SBP'],
        ['S1', ['P', 'SRC'], 'S1P'],
        ['OB', ['P'], 'OBP'],
        ['O1', ['p'], 'O1P'],
        ['SB', ['E', 'SRA'], 'SB'],
        ['S1P', ['P'], 'S1P'],
        ['S3', ['P'], 'S3'],
        ['S3S', ['P'], 'S3S'],
    ]);
});

describe('features', function (): void {
    it('keeps contract codes and reports the rest', function (): void {
        $features = new Features(['SRC', 'SR', 'CI', 'METAL_FREE']);

        expect($features->normalise(['sr', 'LG', ' CI ', 'SC', 'SR', '']))
            ->toBe([['SR', 'CI'], ['LG', 'SC']]);
    });
});

describe('sizes', function (): void {
    $chart = ['36' => '3.5', '44' => '10', '49' => '13.5', '35/36' => '3/3.5'];

    it('writes UK sizes without a trailing zero', function (?string $raw, ?string $expected): void {
        expect(Sizes::uk($raw))->toBe($expected);
    })->with([
        ['10.0', '10'],
        ['10', '10'],
        ['6.5', '6.5'],
        [' 3 ', '3'],
        ['N/A', null],
        ['', null],
        [null, null],
    ]);

    it('converts EU sizes through the chart and never guesses', function () use ($chart): void {
        expect(Sizes::fromChart($chart, '44'))->toBe('10')
            ->and(Sizes::fromChart($chart, '49'))->toBe('13.5')
            ->and(Sizes::fromChart($chart, '35/36'))->toBe('3/3.5')
            ->and(Sizes::fromChart($chart, '50'))->toBeNull()
            ->and(Sizes::fromChart($chart, '44.5'))->toBeNull()
            ->and(Sizes::fromChart($chart, ''))->toBeNull();
    });

    it('sorts a jump size by its first part', function (): void {
        expect(Sizes::sort('3/3.5'))->toBe(3.0)
            ->and(Sizes::sort('10.5'))->toBe(10.5);
    });

    it('keeps kids sizes apart from and below adult sizes', function (): void {
        expect(Sizes::kidsName('10'))->toBe('Kids 10')
            // Children's scale: 8 ... 13 come before 1, 2 ...
            ->and(Sizes::kidsSort('8'))->toBeLessThan(Sizes::kidsSort('13'))
            ->and(Sizes::kidsSort('13'))->toBeLessThan(Sizes::kidsSort('1'))
            ->and(Sizes::kidsSort('1'))->toBeLessThan(Sizes::kidsSort('5'))
            ->and(Sizes::kidsSort('6.5'))->toBeLessThan(Sizes::sort('1'));
    });
});

describe('money', function (): void {
    it('converts vendor amounts to pence', function (mixed $value, ?int $expected): void {
        expect(Money::toPence($value))->toBe($expected);
    })->with([
        ['45.93', 4593],
        [45.93, 4593],
        ['9.99', 999],
        ['26.9900', 2699],
        [91.8, 9180],
        [0.0, 0],
        ['14', 1400],
        ['1.005', 101],
        ['', null],
        [null, null],
        ['abc', null],
        ['-1.00', null],
    ]);

    it('removes VAT rounding half up', function (): void {
        expect(Money::exVat(9180, 2000))->toBe(7650)
            ->and(Money::exVat(2399, 2000))->toBe(1999)
            ->and(Money::exVat(5999, 0))->toBe(5999)
            ->and(Money::exVat(7430, 2000))->toBe(6192);
    });

    it('multiplies by a decimal string without floats', function (): void {
        expect(Money::multiply(4593, '1.5'))->toBe(6890)
            ->and(Money::multiply(999, '1.5'))->toBe(1499)
            ->and(Money::multiply(1000, '2'))->toBe(2000)
            ->and(Money::multiply(1000, '1.25'))->toBe(1250);
    });
});

describe('price', function (): void {
    $prices = new PriceCalculator('1.5');

    it('marks up the cost and shows the RRP as the compare price', function () use ($prices): void {
        expect($prices->calculate(4593, 7650))
            ->toBe(['price' => 6890, 'compare' => 7650, 'sellable' => true, 'reason' => null]);
    });

    it('caps the price at the RRP and then has no compare price', function () use ($prices): void {
        expect($prices->calculate(4000, 5000))
            ->toBe(['price' => 5000, 'compare' => null, 'sellable' => true, 'reason' => null]);
    });

    it('has no cap and no compare price without an RRP', function (?int $rrp) use ($prices): void {
        expect($prices->calculate(29060, $rrp))
            ->toBe(['price' => 43590, 'compare' => null, 'sellable' => true, 'reason' => null]);
    })->with([null, 0]);

    it('is not sellable when the cost is at or above the RRP', function () use ($prices): void {
        expect($prices->calculate(1400, 521))
            ->toMatchArray(['sellable' => false, 'reason' => PriceCalculator::COST_AT_OR_ABOVE_RRP])
            ->and($prices->calculate(500, 500)['sellable'])->toBeFalse();
    });

    it('has no price without a cost', function (?int $cost) use ($prices): void {
        expect($prices->calculate($cost, 5000))
            ->toBe(['price' => null, 'compare' => null, 'sellable' => false, 'reason' => PriceCalculator::NO_COST]);
    })->with([null, 0]);
});

describe('payload hash', function (): void {
    it('ignores key order but not list order or values', function (): void {
        $a = new RawRecord('1', ['b' => 1, 'a' => ['y' => 2, 'x' => [1, 2]]]);
        $b = new RawRecord('1', ['a' => ['x' => [1, 2], 'y' => 2], 'b' => 1]);
        $c = new RawRecord('1', ['a' => ['x' => [2, 1], 'y' => 2], 'b' => 1]);

        expect($a->hash())->toBe($b->hash())
            ->and($a->hash())->not->toBe($c->hash());
    });

    it('hashes the hashable payload when one is given', function (): void {
        $monday = new RawRecord('1', ['date' => 'Mon', 'name' => 'Eva'], ['name' => 'Eva']);
        $tuesday = new RawRecord('1', ['date' => 'Tue', 'name' => 'Eva'], ['name' => 'Eva']);

        expect($monday->hash())->toBe($tuesday->hash());
    });
});
