<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\ImageOptimiser;

/** A real image of the given size: opaque blue, or with a transparent left half. */
function optimiserSource(int $width, int $height, string $format = 'png', bool $transparent = false): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 80, 200));

    if ($transparent) {
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image),
        'gif' => imagegif($image),
        default => imagepng($image),
    };

    return (string) ob_get_clean();
}

/**
 * @return array{0: int, 1: int}
 */
function optimisedSize(string $webp): array
{
    $size = getimagesizefromstring($webp);

    return [$size[0], $size[1]];
}

it('stores an image as WebP', function (): void {
    $webp = (new ImageOptimiser)->optimise(optimiserSource(400, 300, 'jpeg'));

    expect(getimagesizefromstring($webp)['mime'])->toBe('image/webp')
        ->and(optimisedSize($webp))->toBe([400, 300]);
});

it('shrinks an image wider than 1200 pixels to 1200, keeping its proportions', function (): void {
    expect(optimisedSize((new ImageOptimiser)->optimise(optimiserSource(2400, 1800))))->toBe([1200, 900])
        ->and(optimisedSize((new ImageOptimiser)->optimise(optimiserSource(1201, 1201))))->toBe([1200, 1200])
        // Height alone does not shrink an image: only the width is limited.
        ->and(optimisedSize((new ImageOptimiser)->optimise(optimiserSource(800, 3000))))->toBe([800, 3000]);
});

it('never enlarges an image', function (): void {
    expect(optimisedSize((new ImageOptimiser)->optimise(optimiserSource(1200, 800))))->toBe([1200, 800])
        ->and(optimisedSize((new ImageOptimiser)->optimise(optimiserSource(90, 60))))->toBe([90, 60]);
});

it('keeps transparency', function (): void {
    $webp = (new ImageOptimiser)->optimise(optimiserSource(2000, 1000, 'png', transparent: true));

    $image = imagecreatefromstring($webp);
    $transparent = (imagecolorat($image, 10, 500) >> 24) & 127;
    $opaque = (imagecolorat($image, 1190, 500) >> 24) & 127;

    expect($transparent)->toBeGreaterThan(100)->and($opaque)->toBe(0);
});

it('reads a palette image such as a GIF', function (): void {
    $webp = (new ImageOptimiser)->optimise(optimiserSource(300, 200, 'gif'));

    expect(optimisedSize($webp))->toBe([300, 200]);
});

it('honours the configured width and quality, and names them in its signature', function (): void {
    expect((new ImageOptimiser)->signature())->toBe('webp-1200-q85');

    config(['vendorsync.images.max_width' => 600, 'vendorsync.images.webp_quality' => 70]);

    expect((new ImageOptimiser)->signature())->toBe('webp-600-q70')
        ->and(optimisedSize((new ImageOptimiser)->optimise(optimiserSource(1000, 500))))->toBe([600, 300]);
});

it('refuses bytes that are not an image', function (): void {
    expect(fn () => (new ImageOptimiser)->optimise('<html>not an image</html>'))->toThrow(RuntimeException::class, 'not an image');
});

it('leaves the memory limit as it found it after a large image', function (): void {
    $before = ini_get('memory_limit');

    (new ImageOptimiser)->optimise(optimiserSource(3000, 2000));

    expect(ini_get('memory_limit'))->toBe($before);
});

it('names the baseline in the signature only when it aligns', function (): void {
    expect((new ImageOptimiser)->signature())->toBe('webp-1200-q85')
        ->and((new ImageOptimiser)->signature(true))->toBe('webp-1200-q85-base11-side4-ar100');

    config(['vendorsync.images.align.bottom' => 0.2]);

    expect((new ImageOptimiser)->signature(true))->toBe('webp-1200-q85-base20-side4-ar100');

});

it('puts the subject on the baseline when asked, and leaves it where it is otherwise', function (): void {
    // A 600 x 400 transparent PNG with a shoe-shaped block floating in the middle (rows 150 to 249).
    $image = imagecreatetruecolor(600, 400);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 100, 150, 499, 249, (int) imagecolorallocate($image, 20, 30, 40));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $bottomRow = function (string $webp): int {
        $decoded = imagecreatefromstring($webp);
        $bottom = -1;

        for ($y = 0; $y < imagesy($decoded); $y++) {
            if (((imagecolorat($decoded, 300, $y) >> 24) & 127) < 80) {
                $bottom = $y;
            }
        }

        return $bottom;
    };

    // As it was: the block ends at row 249.
    expect($bottomRow((new ImageOptimiser)->optimise($png)))->toBe(249);

    // On the baseline of a square canvas: the picture becomes 600 x 600, and 89% of 600 = 534.
    $aligned = (new ImageOptimiser)->optimise($png, true);

    expect(getimagesizefromstring($aligned)[1])->toBe(600)
        ->and($bottomRow($aligned))->toBeGreaterThanOrEqual(531)->toBeLessThanOrEqual(535);

    // Without a canvas shape it keeps its own size and baseline: 89% of 400 = 356.
    config(['vendorsync.images.align.aspect' => null]);

    $kept = (new ImageOptimiser)->optimise($png, true);

    expect(getimagesizefromstring($kept)[1])->toBe(400)
        ->and($bottomRow($kept))->toBeGreaterThanOrEqual(353)->toBeLessThanOrEqual(357);
});
