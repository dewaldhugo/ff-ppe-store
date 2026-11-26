<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\BackgroundRemover;

// The plain background of an opaque product photo is made transparent, so the card backdrop shows behind the shoe.

/**
 * A 200 x 150 opaque image: a dark "shoe" with a white "sole" inside it, on a background of the given colour.
 */
function cutoutImage(int $red = 255, int $green = 255, int $blue = 255): GdImage
{
    $image = imagecreatetruecolor(200, 150);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));
    imagefilledrectangle($image, 50, 30, 150, 110, (int) imagecolorallocate($image, 30, 40, 50));
    // A white patch inside the dark shape, not connected to the edge: it must stay.
    imagefilledrectangle($image, 80, 60, 120, 90, (int) imagecolorallocate($image, 255, 255, 255));

    return $image;
}

function cutoutAlpha(GdImage $image, int $x, int $y): int
{
    return (imagecolorat($image, $x, $y) >> 24) & 127;
}

it('makes the background connected to the edge transparent and keeps the subject', function (): void {
    $result = (new BackgroundRemover)->remove(cutoutImage());

    expect(cutoutAlpha($result, 2, 2))->toBe(127)
        ->and(cutoutAlpha($result, 197, 147))->toBe(127)
        ->and(cutoutAlpha($result, 100, 20))->toBe(127)
        ->and(cutoutAlpha($result, 60, 50))->toBe(0);
});

it('keeps a white area inside the subject', function (): void {
    $result = (new BackgroundRemover)->remove(cutoutImage());

    expect(cutoutAlpha($result, 100, 75))->toBe(0)
        ->and(imagecolorat($result, 100, 75) & 0xFFFFFF)->toBe(0xFFFFFF);
});

it('works on a background that is not white', function (): void {
    $result = (new BackgroundRemover)->remove(cutoutImage(240, 240, 235));

    expect(cutoutAlpha($result, 2, 2))->toBe(127)
        ->and(cutoutAlpha($result, 60, 50))->toBe(0);
});

it('blends the edge pixels away from the background colour', function (): void {
    $image = cutoutImage();
    // A grey pixel next to the background, as an antialiased edge has: half way between white and the dark subject.
    imagesetpixel($image, 49, 70, (int) imagecolorallocate($image, 140, 147, 152));

    $result = (new BackgroundRemover)->remove($image);

    // Partly transparent, and darker than the grey it was (the white is taken out of it).
    expect(cutoutAlpha($result, 49, 70))->toBeGreaterThan(0)->toBeLessThan(127)
        ->and((imagecolorat($result, 49, 70) >> 16) & 255)->toBeLessThan(140);
});

it('leaves an image with transparent corners as it is', function (): void {
    $image = imagecreatetruecolor(200, 150);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 50, 30, 150, 110, (int) imagecolorallocate($image, 30, 40, 50));

    $result = (new BackgroundRemover)->remove($image);

    expect($result)->toBe($image)
        ->and(cutoutAlpha($result, 60, 50))->toBe(0);
});

it('leaves a photo whose corners are not one colour as it is', function (): void {
    $image = cutoutImage();
    imagesetpixel($image, 0, 0, (int) imagecolorallocate($image, 20, 20, 20));

    $result = (new BackgroundRemover)->remove($image);

    expect(cutoutAlpha($result, 2, 2))->toBe(0);
});

it('leaves a photo whose border is mostly not the background colour as it is', function (): void {
    $image = cutoutImage();
    // Four white corners, but dark along the whole border between them.
    imagefilledrectangle($image, 10, 0, 189, 2, (int) imagecolorallocate($image, 30, 40, 50));
    imagefilledrectangle($image, 10, 147, 189, 149, (int) imagecolorallocate($image, 30, 40, 50));
    imagefilledrectangle($image, 0, 10, 2, 139, (int) imagecolorallocate($image, 30, 40, 50));
    imagefilledrectangle($image, 197, 10, 199, 139, (int) imagecolorallocate($image, 30, 40, 50));

    $result = (new BackgroundRemover)->remove($image);

    expect(cutoutAlpha($result, 100, 20))->toBe(0);
});

it('leaves a photo that is all one colour as it is', function (): void {
    $image = imagecreatetruecolor(200, 150);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));

    $result = (new BackgroundRemover)->remove($image);

    expect(cutoutAlpha($result, 100, 75))->toBe(0);
});

it('shrinks a photo wider than the limit before it works on it', function (): void {
    $result = (new BackgroundRemover)->remove(cutoutImage(), 100);

    expect(imagesx($result))->toBe(100)
        ->and(imagesy($result))->toBe(75)
        ->and(cutoutAlpha($result, 1, 1))->toBe(127);
});
