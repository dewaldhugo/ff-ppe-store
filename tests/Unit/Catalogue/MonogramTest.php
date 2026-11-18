<?php

declare(strict_types=1);

use App\Domain\Catalogue\Brands\Monogram;

it('uses the first letter of the first two words, or one letter for one word', function (string $name, string $initials): void {
    expect(Monogram::initials($name))->toBe($initials);
})->with([
    ['Safety Workwear', 'SW'],
    ['Tiger Grip', 'TG'],
    ['Fleet & Foster', 'FF'],
    ['Hi-Tec', 'HT'],
    ['The Clean Boot', 'CB'],
    ['Maxguard', 'M'],
    ['', '?'],
]);

it('gives a brand the same colours every time', function (): void {
    expect(Monogram::colours('mirak'))->toBe(Monogram::colours('mirak'))
        ->and(Monogram::colours('mirak'))->not->toBe(Monogram::colours('centek'));
});

it('keeps monogram text readable (WCAG AA) on every pastel', function (): void {
    $luminance = function (string $hex): float {
        $channel = function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
    };

    for ($i = 0; $i < 720; $i++) {
        ['background' => $background, 'text' => $text] = Monogram::colours('brand-'.$i);

        $ratio = ($luminance($background) + 0.05) / ($luminance($text) + 0.05);

        expect($ratio)->toBeGreaterThanOrEqual(4.5, "brand-{$i}: {$text} on {$background}");
    }
});
