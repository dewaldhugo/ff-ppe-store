<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use GdImage;

/**
 * Crops a product photo to its subject (the shoe, the harness, the earplugs): the picture is cut tight to the subject on all four
 * sides, with only a hair of padding so the antialiased edge and a faint shadow are not clipped, and is stored as that. It has no
 * margins and no baseline of its own. The storefront's cards then give every picture the same padding and align it to the bottom
 * (object-contain, object-bottom), so the margins are a style choice that can change without importing a picture again, and the
 * subjects of one grid stand on one baseline whatever size or shape they are.
 *
 * The subject is found without being told where it is: on a transparent image it is the area that is not transparent, and on an
 * opaque one it is whatever differs from the background colour of the corners. An image where no subject can be found (a speck
 * is not one) is returned as it is. The result is scaled down, never up, so that its longest side is at most `$maxSide` pixels;
 * the picture is resampled once, from its own full resolution, which is sharper than resizing first and cropping after.
 */
final class SubjectAligner
{
    /** The smallest share of the image the subject must cover for the result to be trusted (a speck is noise). */
    private const float MIN_AREA = 0.03;

    /** How different a pixel must be from the background colour (the largest channel difference) to be part of the subject. */
    private const int COLOUR_DISTANCE = 28;

    /** The alpha value (0 opaque, 127 transparent) below which a pixel is part of the subject: a faint shadow is not. */
    private const int ALPHA_LIMIT = 80;

    /**
     * @param  float  $padding  Room kept round the subject, as a share of its longer side (0.02 is 2%, and never less than 2 pixels).
     * @param  int  $maxSide  The most pixels the longer side of the result may have.
     */
    public function crop(GdImage $image, float $padding, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $box = $this->subject($image, $width, $height);

        if ($box === null) {
            return $image;
        }

        [$left, $upper, $right, $lower] = $box;

        // A hair of room, so the edge pixels and a faint shadow are not cut off.
        $pad = max(2, (int) round(max($right - $left + 1, $lower - $upper + 1) * max(0.0, $padding)));
        $fromX = max(0, $left - $pad);
        $fromY = max(0, $upper - $pad);
        $toX = min($width, $right + 1 + $pad);
        $toY = min($height, $lower + 1 + $pad);

        $cropWidth = $toX - $fromX;
        $cropHeight = $toY - $fromY;
        $scale = min(1.0, max(1, $maxSide) / max($cropWidth, $cropHeight));
        $newWidth = max(1, (int) round($cropWidth * $scale));
        $newHeight = max(1, (int) round($cropHeight * $scale));

        // Nothing to cut off and nothing to shrink: as it is.
        if ($newWidth === $width && $newHeight === $height) {
            return $image;
        }

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, $fromX, $fromY, $newWidth, $newHeight, $cropWidth, $cropHeight);

        return $canvas;
    }

    /**
     * The box round the subject as [left, top, right, bottom] (pixels, inclusive), or null when there is none to trust.
     *
     * @return ?array{0: int, 1: int, 2: int, 3: int}
     */
    private function subject(GdImage $image, int $width, int $height): ?array
    {
        $transparent = $this->hasTransparentCorners($image, $width, $height);
        [$red, $green, $blue] = $this->cornerColour($image, $width, $height);

        $left = $width;
        $upper = $height;
        $right = -1;
        $lower = -1;
        $hits = 0;

        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $pixel = imagecolorat($image, $x, $y);

                if ($transparent) {
                    $isSubject = (($pixel >> 24) & 127) < self::ALPHA_LIMIT;
                } else {
                    $isSubject = max(
                        abs((($pixel >> 16) & 255) - $red),
                        abs((($pixel >> 8) & 255) - $green),
                        abs(($pixel & 255) - $blue),
                    ) > self::COLOUR_DISTANCE;
                }

                if (! $isSubject) {
                    continue;
                }

                $hits++;
                $left = min($left, $x);
                $right = max($right, $x);
                $upper = min($upper, $y);
                $lower = max($lower, $y);
            }
        }

        if ($right < 0 || $hits * 4 < $width * $height * self::MIN_AREA) {
            return null;
        }

        return [$left, $upper, min($width - 1, $right + 1), min($height - 1, $lower + 1)];
    }

    private function hasTransparentCorners(GdImage $image, int $width, int $height): bool
    {
        foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
            if ((imagecolorat($image, $x, $y) >> 24 & 127) < 100) {
                return false;
            }
        }

        return true;
    }

    /**
     * The average colour of the four corners.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function cornerColour(GdImage $image, int $width, int $height): array
    {
        $sum = [0, 0, 0];

        foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
            $pixel = imagecolorat($image, $x, $y);
            $sum[0] += ($pixel >> 16) & 255;
            $sum[1] += ($pixel >> 8) & 255;
            $sum[2] += $pixel & 255;
        }

        return [intdiv($sum[0], 4), intdiv($sum[1], 4), intdiv($sum[2], 4)];
    }
}
