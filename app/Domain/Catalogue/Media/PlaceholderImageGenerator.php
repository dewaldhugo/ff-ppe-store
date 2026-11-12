<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Media;

use RuntimeException;

/**
 * Draws a flat placeholder product image with GD: a square filled with the
 * colour's hex value and a caption. Used by the fixture seeder only, so that
 * nothing is downloaded.
 */
final class PlaceholderImageGenerator
{
    private const string FALLBACK_HEX = '9CA3AF';

    /**
     * @return string Path of the generated PNG.
     */
    public function generate(string $caption, ?string $hex, string $directory, int $size = 1600): string
    {
        [$red, $green, $blue] = $this->rgb($hex ?? self::FALLBACK_HEX);

        // The caption is drawn small with a built-in font, then scaled up.
        $textCanvas = imagecreatetruecolor(200, 200);
        imagefill($textCanvas, 0, 0, imagecolorallocate($textCanvas, $red, $green, $blue));

        $light = (0.299 * $red + 0.587 * $green + 0.114 * $blue) > 150;
        $ink = $light
            ? imagecolorallocate($textCanvas, 17, 24, 39)
            : imagecolorallocate($textCanvas, 255, 255, 255);

        $lines = explode("\n", wordwrap($caption, 20, "\n", true));
        $y = 100 - (int) (count($lines) * 16 / 2);

        foreach ($lines as $line) {
            $x = (int) ((200 - strlen($line) * imagefontwidth(5)) / 2);
            imagestring($textCanvas, 5, max($x, 2), $y, $line, $ink);
            $y += 16;
        }

        $image = imagecreatetruecolor($size, $size);
        imagecopyresized($image, $textCanvas, 0, 0, 0, 0, $size, $size, 200, 200);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the directory [{$directory}].");
        }

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.md5($caption.($hex ?? '')).'.png';

        if (! imagepng($image, $path)) {
            throw new RuntimeException("Could not write the placeholder image [{$path}].");
        }

        return $path;
    }

    /**
     * @return array{int, int, int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = self::FALLBACK_HEX;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
