<?php

declare(strict_types=1);

use App\Domain\Catalogue\Options\ColourSwatch;

it('reads a single colour from its name', function (string $name): void {
    expect(ColourSwatch::hexes($name))->toHaveCount(1)->each->toMatch('/^[0-9A-F]{6}$/');
})->with(['Black', 'Camo', 'Cedar', 'Charcoal', 'Crazyhorse', 'Dark Beige', 'Light Blue', 'Hi Vis Yellow', 'Brick Red']);

it('splits a combined colour into one band per colour', function (string $name, int $bands): void {
    expect(ColourSwatch::hexes($name))->toHaveCount($bands);
})->with([
    ['Brown/Orange', 2],
    ['Charcoal/Grey', 2],
    ['Dark Beige/Black/Yellow', 3],
    ['Black & Red', 2],
]);

it('prefers the vendor hex for a single colour', function (): void {
    expect(ColourSwatch::hexes('Black', '#ff0000'))->toBe(['FF0000']);
});

it('uses the names of a combination rather than one vendor hex', function (): void {
    expect(ColourSwatch::hexes('Black/Red', '112233'))->toHaveCount(2);
});

it('falls back to the vendor hex when part of a combination is unknown', function (): void {
    expect(ColourSwatch::hexes('Black/Zorp', '112233'))->toBe(['112233'])
        ->and(ColourSwatch::hexes('Black/Zorp'))->toHaveCount(1);
});

it('gives nothing for a name it cannot read', function (string $name): void {
    expect(ColourSwatch::hexes($name))->toBe([]);
})->with(['N/A', 'Check', 'Zorp', '']);
