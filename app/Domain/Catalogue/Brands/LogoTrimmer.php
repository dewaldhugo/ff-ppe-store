<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Brands;

use RuntimeException;

/**
 * Cuts the empty margin off a brand logo. The logos in public/images/brands sit in a 360 by 240 canvas with a
 * transparent border (the brand tiles want that room); on the product page the logo is shown small, so it is
 * trimmed to the visible mark first. Works on PNGs with an alpha channel, with GD (already part of PHP here).
 */
final class LogoTrimmer
{
    /** An alpha value (0 opaque, 127 transparent) at or above this counts as empty. */
    private const int EMPTY_ALPHA = 100;

    /**
     * Write a trimmed copy of the PNG at $source to $target.
     *
     * @return bool false when the logo has nothing visible in it (nothing is written)
     */
    public function trim(string $source, string $target): bool
    {
        $image = @imagecreatefrompng($source);

        if ($image === false) {
            throw new RuntimeException("Cannot read the PNG [{$source}].");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $left = $width;
        $top = $height;
        $right = -1;
        $bottom = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 127) >= self::EMPTY_ALPHA) {
                    continue;
                }

                $left = min($left, $x);
                $right = max($right, $x);
                $top = min($top, $y);
                $bottom = max($bottom, $y);
            }
        }

        if ($right < 0) {
            return false;
        }

        $trimmed = imagecreatetruecolor($right - $left + 1, $bottom - $top + 1);
        imagealphablending($trimmed, false);
        imagesavealpha($trimmed, true);
        imagefill($trimmed, 0, 0, (int) imagecolorallocatealpha($trimmed, 0, 0, 0, 127));
        imagecopy($trimmed, $image, 0, 0, $left, $top, $right - $left + 1, $bottom - $top + 1);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        if (! imagepng($trimmed, $target)) {
            throw new RuntimeException("Cannot write the PNG [{$target}].");
        }

        return true;
    }
}
