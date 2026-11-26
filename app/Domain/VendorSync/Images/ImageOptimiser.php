<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use RuntimeException;

/**
 * Turns a downloaded vendor image into the file that is stored: WebP, at most `vendorsync.images.max_width` pixels wide (1200),
 * never enlarged, with transparency kept. The vendor's original is not kept, so a 12 megapixel PNG costs the storage of one
 * 1200 pixel WebP.
 *
 * There is no size limit on the original. The decode of a large image needs memory (GD holds about 4 bytes a pixel, more
 * while it decodes), so the memory limit is raised for the decode when it is too low, and put back afterwards.
 */
final class ImageOptimiser
{
    /** Bytes of memory a decode needs per pixel of the original, with room for the decoder's own buffers. */
    private const int BYTES_PER_PIXEL = 8;

    /** Bytes kept free on top of the decode, for the resized copy and the encoder. */
    private const int HEADROOM = 48 * 1024 * 1024;

    /**
     * Names the output (`-base15-top15-side4-ar100-grow-bg` when the subject is also put on the baseline, with these margins and canvas shape, and `-bg` when a plain background is made transparent): it is part of the image plan hash, so changing the width or quality has every product fetch its images
     * again, and an image stored under another signature is processed again.
     */
    public function signature(bool $aligned = false): string
    {
        return 'webp-'.$this->maxWidth().'-q'.$this->quality().($aligned ? '-base'.(int) round($this->bottom() * 100).'-top'.(int) round($this->top() * 100).'-side'.(int) round($this->side() * 100).($this->aspect() === null ? '' : '-ar'.(int) round($this->aspect() * 100)).'-grow'.($this->removesBackground() ? '-bg' : '') : '');
    }

    /**
     * @throws RuntimeException When the bytes are not an image GD can read, or cannot be written as WebP.
     */
    public function optimise(string $content, bool $aligned = false): string
    {
        $size = @getimagesizefromstring($content);

        if ($size === false) {
            throw new RuntimeException('the file is not an image');
        }

        [$width, $height] = $size;

        $previous = $this->raiseMemoryFor($width * $height * self::BYTES_PER_PIXEL + self::HEADROOM);

        try {
            return $this->encode($content, $width, $height, $aligned);
        } finally {
            if ($previous !== null) {
                ini_set('memory_limit', $previous);
            }
        }
    }

    private function encode(string $content, int $width, int $height, bool $aligned): string
    {
        $source = @imagecreatefromstring($content);

        if ($source === false) {
            throw new RuntimeException('the image could not be decoded');
        }

        // WebP needs true colour; GIFs and 8 bit PNGs are palette images.
        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        // An aligned image is resampled once, from its full resolution, by the aligner (sharper than a resize and then a move).
        if (! $aligned && $width > $this->maxWidth()) {
            $newWidth = $this->maxWidth();
            $newHeight = max(1, (int) round($height * $newWidth / $width));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, (int) imagecolorallocatealpha($resized, 0, 0, 0, 127));
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            unset($source);
            $source = $resized;
        }

        // The subject is found on the original, and a subject that is clearly too small for its room (a lot of empty space round it
        // in the photo) is enlarged to fill it, though never beyond the original's own pixels.
        if ($aligned) {
            // A photo on a plain background (Footsure's shoes on white) is cut out first, so the card's backdrop shows behind it.
            if ($this->removesBackground()) {
                $source = (new BackgroundRemover)->remove($source, $this->maxWidth());
            }

            $source = (new SubjectAligner)->align($source, $this->bottom(), $this->top(), $this->side(), $this->aspect(), min($width, $this->maxWidth()), 1.0);
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);

        ob_start();
        $written = imagewebp($source, null, $this->quality());
        $webp = (string) ob_get_clean();

        unset($source);

        if (! $written || $webp === '') {
            throw new RuntimeException('the image could not be written as WebP');
        }

        return $webp;
    }

    /**
     * Raise the memory limit to cover `$needed` more bytes, or leave it when it already does.
     *
     * @return ?string The limit to put back, or null when it was not changed.
     */
    private function raiseMemoryFor(int $needed): ?string
    {
        $limit = (string) ini_get('memory_limit');
        $bytes = $this->bytes($limit);

        if ($bytes < 0) {
            return null;
        }

        $target = memory_get_usage(true) + $needed;

        if ($target <= $bytes) {
            return null;
        }

        return @ini_set('memory_limit', (string) $target) === false ? null : $limit;
    }

    private function bytes(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '-1') {
            return -1;
        }

        $number = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private function bottom(): float
    {
        return min(0.4, max(0.0, (float) config('vendorsync.images.align.bottom', 0.15)));
    }

    /**
     * Width over height of the canvas an aligned image is put on, or null to keep its own shape.
     */
    private function aspect(): ?float
    {
        $aspect = config('vendorsync.images.align.aspect', 1.0);

        return is_numeric($aspect) && (float) $aspect > 0 ? min(4.0, max(0.25, (float) $aspect)) : null;
    }

    private function side(): float
    {
        return min(0.2, max(0.0, (float) config('vendorsync.images.align.side', 0.04)));
    }

    private function removesBackground(): bool
    {
        return (bool) config('vendorsync.images.align.remove_background', true);
    }

    private function top(): float
    {
        return min(0.4, max(0.0, (float) config('vendorsync.images.align.top', 0.15)));
    }

    private function maxWidth(): int
    {
        return max(1, (int) config('vendorsync.images.max_width', 1200));
    }

    private function quality(): int
    {
        return min(100, max(1, (int) config('vendorsync.images.webp_quality', 85)));
    }
}
