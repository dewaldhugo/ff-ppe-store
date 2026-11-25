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
     * Names the output: it is part of the image plan hash, so changing the width or quality has every product fetch its images
     * again, and an image stored under another signature is processed again.
     */
    public function signature(): string
    {
        return 'webp-'.$this->maxWidth().'-q'.$this->quality();
    }

    /**
     * @throws RuntimeException When the bytes are not an image GD can read, or cannot be written as WebP.
     */
    public function optimise(string $content): string
    {
        $size = @getimagesizefromstring($content);

        if ($size === false) {
            throw new RuntimeException('the file is not an image');
        }

        [$width, $height] = $size;

        $previous = $this->raiseMemoryFor($width * $height * self::BYTES_PER_PIXEL + self::HEADROOM);

        try {
            return $this->encode($content, $width, $height);
        } finally {
            if ($previous !== null) {
                ini_set('memory_limit', $previous);
            }
        }
    }

    private function encode(string $content, int $width, int $height): string
    {
        $source = @imagecreatefromstring($content);

        if ($source === false) {
            throw new RuntimeException('the image could not be decoded');
        }

        // WebP needs true colour; GIFs and 8 bit PNGs are palette images.
        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        if ($width > $this->maxWidth()) {
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

    private function maxWidth(): int
    {
        return max(1, (int) config('vendorsync.images.max_width', 1200));
    }

    private function quality(): int
    {
        return min(100, max(1, (int) config('vendorsync.images.webp_quality', 85)));
    }
}
