<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use GdImage;

/**
 * Puts the subject of a product photo (the shoe or boot) on a common baseline, so a grid of products lines up: the sole of every
 * subject ends at the same height, with the same room under it. Safety Jogger's main images come in two framings, one with the
 * boot filling the canvas and others with a shoe floating in the middle or at the top, and in a grid the shoes look misplaced
 * next to the boots.
 *
 * The subject is found without being told where it is: on a transparent image it is the area that is not transparent, and on an
 * opaque one it is whatever differs from the background colour of the corners. It is moved straight down (or up, when it touches
 * the bottom edge and has no room under it) so its bottom edge is at `1 - $bottom` of the height. A subject too tall to fit
 * between that baseline and the top margin, or too wide to leave `$side` of the width free on each side, is scaled down to fit,
 * never up, and with its own shadow (the whole picture moves, not only the box round the subject). Its horizontal centre is kept, but it is never closer than that margin to a side.
 *
 * With an `$aspect` (width over height) the result is on a canvas of that shape, made as wide as the image and as tall as the
 * shape asks, with the subject on its baseline: a wide picture of a shoe and a tall one of a boot then end up on the same
 * canvas, and a grid that fits each picture into a box (object-contain) shows them on one baseline. Without one, the canvas
 * keeps its size. An image where
 * no subject can be found, or one that is already on the baseline, is returned as it is.
 */
final class SubjectAligner
{
    /** The smallest share of the image the subject must cover for the result to be trusted (a speck is noise). */
    private const float MIN_AREA = 0.03;

    /** A subject whose fitting scale is more than this many times a plain resize is "clearly too small" and is enlarged. */
    private const float SMALL_FACTOR = 1.25;

    /** How close to the baseline, as a share of the height, counts as already there. */
    private const float TOLERANCE = 0.01;

    /** How different a pixel must be from the background colour (the largest channel difference) to be part of the subject. */
    private const int COLOUR_DISTANCE = 28;

    /** The alpha value (0 opaque, 127 transparent) below which a pixel is part of the subject: a faint shadow is not. */
    private const int ALPHA_LIMIT = 80;

    /**
     * @param  float  $bottom  Room left under the subject, as a share of the height.
     * @param  float  $top  Least room left above it, as a share of the height.
     * @param  float  $side  Least room left at each side, as a share of the width.
     * @param  ?float  $aspect  Width over height of the canvas to put the subject on (1.0 is square), or null to keep the shape.
     * @param  ?int  $outWidth  Width of the result in pixels (the picture is resampled to it from its own full resolution, which is
     *                          sharper than resizing first and moving after), or null to keep the width.
     * @param  float  $maxUpscale  How far a subject may be enlarged relative to the source pixels (1.0 never enlarges them). A subject
     *                             that already fills most of its room is never enlarged beyond a plain resize; one that is clearly
     *                             too small for its room (the photographer left a lot of empty space round it) is enlarged to
     *                             fill the room, up to this limit.
     */
    public function align(GdImage $image, float $bottom, float $top, float $side = 0.0, ?float $aspect = null, ?int $outWidth = null, float $maxUpscale = 1.0): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $box = $this->subject($image, $width, $height);

        if ($box === null) {
            return $image;
        }

        [$left, $upper, $right, $lower] = $box;
        $boxWidth = $right - $left + 1;
        $boxHeight = $lower - $upper + 1;

        $outWidth ??= $width;
        $outHeight = $aspect !== null && $aspect > 0 ? max(1, (int) round($outWidth / $aspect)) : max(1, (int) round($height * $outWidth / $width));
        $baseline = (int) round($outHeight * (1 - $bottom));
        $room = max(1, $baseline - (int) round($outHeight * $top));
        $margin = (int) round($outWidth * $side);
        $roomWidth = max(1, $outWidth - 2 * $margin);

        // What scale (source pixels to result pixels) would make the subject fill its room, and what a plain resize would be.
        $fit = min($room / $boxHeight, $roomWidth / $boxWidth);
        $natural = min($outWidth / $width, max(1.0, $maxUpscale));

        // Never larger than a plain resize, unless the subject is clearly too small for its room: then it grows to fill it.
        $scale = min($fit, $natural);

        if ($fit > $natural * self::SMALL_FACTOR && $maxUpscale > $natural) {
            $scale = min($fit, $maxUpscale);
        }

        $placed = $outWidth === $width
            && $outHeight === $height
            && abs($scale - 1.0) < 1e-9
            && abs(($lower + 1) - $baseline) <= $height * self::TOLERANCE
            && $left >= $margin - $width * self::TOLERANCE
            && $right + 1 <= $width - $margin + $width * self::TOLERANCE;

        // Already on the baseline and clear of the sides, with nothing to scale.
        if ($placed) {
            return $image;
        }

        $newWidth = max(1, (int) round($boxWidth * $scale));
        $newHeight = max(1, (int) round($boxHeight * $scale));
        $centre = ($left + $boxWidth / 2) / $width * $outWidth;
        $x = max($margin, min($outWidth - $newWidth - $margin, (int) round($centre - $newWidth / 2)));
        $y = max(0, $baseline - $newHeight);

        $canvas = imagecreatetruecolor($outWidth, $outHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, $this->background($image, $width, $height));

        // Not the box alone but the area round it, so the faint shadow under a shoe comes with it (the box ignores it, and a
        // copy of the box alone would cut it off). Only that area is copied, however large the source is.
        $pad = (int) round(max($boxWidth, $boxHeight) * 0.15);
        $fromX = max(0, $left - $pad);
        $fromY = max(0, $upper - $pad);
        $toX = min($width, $right + 1 + $pad);
        $toY = min($height, $lower + 1 + $pad);

        imagecopyresampled(
            $canvas,
            $image,
            $x - (int) round(($left - $fromX) * $scale),
            $y - (int) round(($upper - $fromY) * $scale),
            $fromX,
            $fromY,
            max(1, (int) round(($toX - $fromX) * $scale)),
            max(1, (int) round(($toY - $fromY) * $scale)),
            $toX - $fromX,
            $toY - $fromY,
        );

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

    /**
     * What the canvas is filled with: transparent where the image is, otherwise its own background colour.
     */
    private function background(GdImage $image, int $width, int $height): int
    {
        if ($this->hasTransparentCorners($image, $width, $height)) {
            return (int) imagecolorallocatealpha($image, 0, 0, 0, 127);
        }

        [$red, $green, $blue] = $this->cornerColour($image, $width, $height);

        return (int) imagecolorallocate($image, $red, $green, $blue);
    }
}
