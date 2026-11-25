<?php

declare(strict_types=1);

use App\Domain\Catalogue\Display\CountryName;

it('turns a country code into its English name, and leaves anything else as it is', function (): void {
    expect(CountryName::of('CN'))->toBe('China')
        ->and(CountryName::of('ro'))->toBe('Romania')
        ->and(CountryName::of('GB'))->toBe('United Kingdom')
        ->and(CountryName::of('Portugal'))->toBe('Portugal')
        ->and(CountryName::of(''))->toBe('');
});
