<?php

declare(strict_types=1);

use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Normalisers\PricingRules;
use Lunar\Models\Price;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    config()->set('vendorsync.markup', '1.5');
    config()->set('vendorsync.vendors.SJ.markup', null);
    config()->set('vendorsync.vendors.FS.markup', null);
});

function repriceImport(float $cost = 40.0, float $rrp = 60.0): mixed
{
    VendorSync::fakeHttp([['id' => 700100]], [700100 => VendorSync::detail(700100, 'REPRICEBOOT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022'], $cost, $rrp)]);

    return VendorSync::import('SJ');
}

function repriceStoredPrice(): int
{
    return (int) Price::query()->sole()->getRawOriginal('price');
}

describe('fingerprint', function (): void {
    it('changes with the markup, per vendor, and not otherwise', function (): void {
        $before = PricingRules::fingerprint('SJ');

        expect(PricingRules::fingerprint('SJ'))->toBe($before);

        config()->set('vendorsync.vendors.FS.markup', '1.7');

        expect(PricingRules::fingerprint('SJ'))->toBe($before)
            ->and(PricingRules::fingerprint('FS'))->not->toBe($before);

        config()->set('vendorsync.vendors.SJ.markup', '1.6');

        expect(PricingRules::fingerprint('SJ'))->not->toBe($before);
    });
});

describe('repricing existing products', function (): void {
    it('leaves an unchanged payload alone while the rules are the same', function (): void {
        repriceImport();

        expect(repriceImport()->unchanged)->toBe(1);
    });

    it('reprices every product once when the markup changes, without --force', function (): void {
        repriceImport();

        expect(repriceStoredPrice())->toBe(6000);

        config()->set('vendorsync.vendors.SJ.markup', '1.6');
        $report = repriceImport();

        expect($report->unchanged)->toBe(0)
            ->and($report->updated)->toBe(1)
            ->and($report->held)->toBe([])
            ->and(repriceStoredPrice())->toBe(6400)
            // Once repriced it is quiet again.
            ->and(repriceImport()->unchanged)->toBe(1);
    });

    it('reprices data stored under the old rules, which have a different hash', function (): void {
        repriceImport();

        // Stored before this change: the payload hash alone, with no pricing fingerprint.
        VendorProduct::query()->update(['payload_hash' => str_repeat('0', 64)]);

        // Cost 40.00 against an RRP of 50.00 ex VAT: the old rule had capped this at 50.00.
        Price::query()->update(['price' => 5000, 'compare_price' => null]);

        $report = repriceImport();

        expect($report->unchanged)->toBe(0)
            ->and(repriceStoredPrice())->toBe(6000)
            ->and(VendorOffer::query()->sole()->cost_price)->toBe(4000);
    });

    it('holds a repricing that is also a large cost change, and the dry run says so', function (): void {
        repriceImport(cost: 10.0);
        VendorProduct::query()->update(['payload_hash' => str_repeat('0', 64)]);

        VendorSync::fakeHttp([['id' => 700100]], [700100 => VendorSync::detail(700100, 'REPRICEBOOT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022'], 20.0, 90.0)]);
        $dry = VendorSync::import('SJ', dryRun: true);

        expect($dry->held)->toHaveCount(1)
            ->and(repriceStoredPrice())->toBe(1500);
    });
});
