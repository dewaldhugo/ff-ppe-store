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

it('names the crop in the signature only when it crops', function (): void {
    expect((new ImageOptimiser)->signature())->toBe('webp-1200-q85')
        ->and((new ImageOptimiser)->signature(true))->toBe('webp-1200-q85-crop2-bg');

    config(['vendorsync.images.align.padding' => 0.05]);

    expect((new ImageOptimiser)->signature(true))->toBe('webp-1200-q85-crop5-bg');

    config(['vendorsync.images.align.remove_background' => false]);

    expect((new ImageOptimiser)->signature(true))->toBe('webp-1200-q85-crop5');
});

/** A transparent PNG, as the bytes, with one opaque block in it. */
function optimiserBlockPng(int $width, int $height, int $left, int $top, int $right, int $bottom): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, $left, $top, $right, $bottom, (int) imagecolorallocate($image, 20, 30, 40));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('crops the picture to its subject when asked, tight on all four sides, and leaves it whole otherwise', function (): void {
    // A 600 x 400 transparent PNG with a shoe-shaped block (400 x 100) floating in the middle.
    $png = optimiserBlockPng(600, 400, 100, 150, 499, 249);

    // As it was: the whole picture.
    expect(optimisedSize((new ImageOptimiser)->optimise($png)))->toBe([600, 400]);

    // Cropped: the block and 2% of its longer side (8 px) all round, and nothing else. No margin is stored.
    $cropped = (new ImageOptimiser)->optimise($png, true);
    $decoded = imagecreatefromstring($cropped);

    expect(optimisedSize($cropped))->toBe([416, 116])
        // The block is 8 px in from each edge: opaque inside it, transparent in the padding round it.
        ->and((imagecolorat($decoded, 208, 58) >> 24) & 127)->toBe(0)
        ->and((imagecolorat($decoded, 12, 12) >> 24) & 127)->toBe(0)
        ->and((imagecolorat($decoded, 3, 58) >> 24) & 127)->toBeGreaterThan(100)
        ->and((imagecolorat($decoded, 208, 102) >> 24) & 127)->toBe(0)
        ->and((imagecolorat($decoded, 208, 112) >> 24) & 127)->toBeGreaterThan(100);
});

it('keeps the cropped picture within the width limit, shrinking the subject and never enlarging it', function (): void {
    // A subject 2000 wide in a 2400 x 800 picture: cropped to about 2080 x 280 and shrunk to 1200 wide.
    $wide = optimiserBlockPng(2400, 800, 200, 300, 2199, 499);

    expect(optimisedSize((new ImageOptimiser)->optimise($wide, true))[0])->toBe(1200);

    // A small subject stays at its own pixels: nothing is blown up to fill the file.
    $small = optimiserBlockPng(400, 300, 150, 100, 249, 199);

    expect(optimisedSize((new ImageOptimiser)->optimise($small, true)))->toBe([104, 104]);
});

it('still obeys the width limit for a picture it finds no subject in', function (): void {
    // One pixel is no subject (noise), so the picture comes back whole and is shrunk like any other.
    $empty = optimiserBlockPng(1800, 600, 0, 0, 0, 0);

    expect(optimisedSize((new ImageOptimiser)->optimise($empty, true)))->toBe([1200, 400]);
});
