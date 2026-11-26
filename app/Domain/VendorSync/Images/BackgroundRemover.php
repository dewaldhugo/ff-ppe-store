<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use GdImage;

/**
 * Makes the plain background of an opaque product photo transparent, so the storefront's card backdrop (the radial spotlight)
 * shows behind the shoe the way it does behind Safety Jogger's cut-out pictures. Footsure's photos are a shoe on white.
 *
 * Only the background that is connected to the edge of the picture is removed (a flood fill from the border through pixels close
 * to the background colour), so a white sole or a white lace inside the shoe stays. A picture is left as it is when it has
 * transparent corners already, when its four corners are not one colour, when its border is not mostly that colour (a lifestyle
 * photo, not a studio shot), or when the fill would remove almost nothing or almost everything (the subject is the background
 * colour, or the fill leaked). The pixels on the new edge are blended away from the background colour (colour to alpha), so the
 * shoe has no white fringe on the teal.
 */
final class BackgroundRemover
{
    /** How different a pixel may be from the background colour (the largest channel difference) to be background. */
    private const int TOLERANCE = 24;

    /** How different the four corners may be from each other for the background to count as one colour. */
    private const int CORNER_SPREAD = 12;

    /** The share of the border pixels that must be the background colour. */
    private const float MIN_BORDER = 0.9;

    /** The share of the picture the fill must remove, at least and at most, for the result to be trusted. */
    private const float MIN_REMOVED = 0.05;

    private const float MAX_REMOVED = 0.97;

    /**
     * Returns the image with its background made transparent, or the image as it is when it should not be touched. An image wider
     * than `$maxWidth` is shrunk to it first, because the fill reads every pixel.
     */
    public function remove(GdImage $image, int $maxWidth = 1200): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 8 || $height < 8) {
            return $image;
        }

        $background = $this->background($image, $width, $height);

        if ($background === null) {
            return $image;
        }

        if ($width > $maxWidth) {
            $newHeight = max(1, (int) round($height * $maxWidth / $width));
            $resized = imagecreatetruecolor($maxWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);

            $image = $resized;
            $width = $maxWidth;
            $height = $newHeight;
        }

        [$red, $green, $blue] = $background;

        // 0 not looked at, 1 background, 2 not background (looked at from a background pixel).
        $state = str_repeat("\0", $width * $height);
        $stack = [];
        $edge = [];
        $removed = 0;

        for ($x = 0; $x < $width; $x++) {
            foreach ([0, $height - 1] as $y) {
                $this->seed($image, $x, $y, $width, $red, $green, $blue, $state, $stack, $removed);
            }
        }

        for ($y = 1; $y < $height - 1; $y++) {
            foreach ([0, $width - 1] as $x) {
                $this->seed($image, $x, $y, $width, $red, $green, $blue, $state, $stack, $removed);
            }
        }

        while ($stack !== []) {
            $index = array_pop($stack);
            $x = $index % $width;
            $y = intdiv($index, $width);

            foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                    continue;
                }

                $next = $ny * $width + $nx;

                if ($state[$next] !== "\0") {
                    continue;
                }

                $pixel = imagecolorat($image, $nx, $ny);

                if (max(abs((($pixel >> 16) & 255) - $red), abs((($pixel >> 8) & 255) - $green), abs(($pixel & 255) - $blue)) <= self::TOLERANCE) {
                    $state[$next] = "\1";
                    $stack[] = $next;
                    $removed++;
                } else {
                    $state[$next] = "\2";
                    $edge[] = $next;
                }
            }
        }

        $share = $removed / ($width * $height);

        if ($share < self::MIN_REMOVED || $share > self::MAX_REMOVED) {
            return $image;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $clear = 127 << 24;

        for ($y = 0; $y < $height; $y++) {
            $row = $y * $width;

            for ($x = 0; $x < $width; $x++) {
                if ($state[$row + $x] === "\1") {
                    imagesetpixel($image, $x, $y, $clear);
                }
            }
        }

        foreach ($edge as $index) {
            $x = $index % $width;
            $y = intdiv($index, $width);

            imagesetpixel($image, $x, $y, $this->blendAway(imagecolorat($image, $x, $y), $red, $green, $blue));
        }

        return $image;
    }

    /**
     * @param  list<int>  $stack
     */
    private function seed(GdImage $image, int $x, int $y, int $width, int $red, int $green, int $blue, string &$state, array &$stack, int &$removed): void
    {
        $index = $y * $width + $x;

        if ($state[$index] !== "\0") {
            return;
        }

        $pixel = imagecolorat($image, $x, $y);

        if (max(abs((($pixel >> 16) & 255) - $red), abs((($pixel >> 8) & 255) - $green), abs(($pixel & 255) - $blue)) <= self::TOLERANCE) {
            $state[$index] = "\1";
            $stack[] = $index;
            $removed++;
        } else {
            $state[$index] = "\2";
        }
    }

    /**
     * A pixel on the edge of the background, as the colour it would be without the background mixed in (colour to alpha): the
     * least opacity that explains the pixel over the background colour, and the colour that opacity needs.
     */
    private function blendAway(int $pixel, int $red, int $green, int $blue): int
    {
        $channels = [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
        $background = [$red, $green, $blue];
        $alpha = 0.0;

        foreach ($channels as $i => $value) {
            $reach = $value < $background[$i] ? $background[$i] : 255 - $background[$i];

            if ($value !== $background[$i] && $reach > 0) {
                $alpha = max($alpha, abs($value - $background[$i]) / $reach);
            }
        }

        if ($alpha <= 0.04) {
            return 127 << 24;
        }

        if ($alpha >= 1.0) {
            return $pixel & 0xFFFFFF;
        }

        $out = 0;

        foreach ($channels as $i => $value) {
            $out = ($out << 8) | max(0, min(255, (int) round($background[$i] + ($value - $background[$i]) / $alpha)));
        }

        return ((int) round(127 * (1 - $alpha)) << 24) | $out;
    }

    /**
     * The background colour, or null when the picture is not a studio shot on one colour.
     *
     * @return ?array{0: int, 1: int, 2: int}
     */
    private function background(GdImage $image, int $width, int $height): ?array
    {
        $sum = [0, 0, 0];
        $corners = [];

        foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
            $pixel = imagecolorat($image, $x, $y);

            // Transparent already: nothing to remove.
            if ((($pixel >> 24) & 127) >= 100) {
                return null;
            }

            $corners[] = [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
        }

        for ($c = 0; $c < 3; $c++) {
            $values = array_column($corners, $c);

            if (max($values) - min($values) > self::CORNER_SPREAD) {
                return null;
            }

            $sum[$c] = intdiv(array_sum($values), 4);
        }

        [$red, $green, $blue] = $sum;

        $near = 0;
        $seen = 0;
        $stepX = max(1, intdiv($width, 200));
        $stepY = max(1, intdiv($height, 200));

        for ($x = 0; $x < $width; $x += $stepX) {
            foreach ([0, $height - 1] as $y) {
                $seen++;
                $near += $this->isNear(imagecolorat($image, $x, $y), $red, $green, $blue) ? 1 : 0;
            }
        }

        for ($y = 0; $y < $height; $y += $stepY) {
            foreach ([0, $width - 1] as $x) {
                $seen++;
                $near += $this->isNear(imagecolorat($image, $x, $y), $red, $green, $blue) ? 1 : 0;
            }
        }

        return $near / $seen >= self::MIN_BORDER ? $sum : null;
    }

    private function isNear(int $pixel, int $red, int $green, int $blue): bool
    {
        return max(abs((($pixel >> 16) & 255) - $red), abs((($pixel >> 8) & 255) - $green), abs(($pixel & 255) - $blue)) <= self::TOLERANCE;
    }
}
