<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Brands;

use Illuminate\Support\Str;

/**
 * The stand-in for a brand without a logo: its initials on a pastel circle.
 *
 * The colour is worked out from the slug, so a brand keeps the same colour on
 * every visit and every server. Every hue passes WCAG AA (4.5:1) with these
 * lightness values; tests/Unit/Catalogue/MonogramTest.php checks it.
 */
final class Monogram
{
    private const float BACKGROUND_SATURATION = 0.70;

    private const float BACKGROUND_LIGHTNESS = 0.88;

    private const float TEXT_SATURATION = 0.50;

    private const float TEXT_LIGHTNESS = 0.26;

    /**
     * First letter of the first two words ("Safety Workwear" is SW). A
     * one-word name gets its first letter only: two letters of one word
     * ("MA" for Maxguard) read as an abbreviation of something else.
     */
    public static function initials(string $name): string
    {
        $words = array_values(array_filter(
            preg_split('/[\s&\-\/]+/u', Str::of($name)->replaceMatches('/^the\s+/i', '')->trim()->toString()) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            return '?';
        }

        $letters = mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : '');

        return mb_strtoupper($letters);
    }

    /**
     * @return array{background: string, text: string} Six hex digits each, without #.
     */
    public static function colours(string $slug): array
    {
        $hue = crc32($slug) % 360;

        return [
            'background' => self::hex($hue, self::BACKGROUND_SATURATION, self::BACKGROUND_LIGHTNESS),
            'text' => self::hex($hue, self::TEXT_SATURATION, self::TEXT_LIGHTNESS),
        ];
    }

    private static function hex(int $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;

        [$r, $g, $b] = match (intdiv($hue, 60)) {
            0 => [$chroma, $x, 0.0],
            1 => [$x, $chroma, 0.0],
            2 => [0.0, $chroma, $x],
            3 => [0.0, $x, $chroma],
            4 => [$x, 0.0, $chroma],
            default => [$chroma, 0.0, $x],
        };

        return sprintf('%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
