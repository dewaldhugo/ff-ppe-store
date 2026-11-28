<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\SubjectAligner;

// The main image of a product is cropped to its subject, tight on all four sides with a hair of padding, and scaled down (never
// up) to a longest side. The margins and the baseline are the storefront's (the cards' padding, object-bottom), not the file's.

/**
 * A 400 x 300 image with a rectangle as the subject.
 *
 * @param  bool  $transparent  A transparent canvas, or an opaque white one.
 */
function cropImage(int $x, int $y, int $width, int $height, bool $transparent = true): GdImage
{
    $image = imagecreatetruecolor(400, 300);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $transparent ? (int) imagecolorallocatealpha($image, 0, 0, 0, 127) : (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, (int) imagecolorallocate($image, 30, 40, 50));

    return $image;
}

/**
 * The box round what is not background, as [left, top, right, bottom] inclusive.
 *
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function cropBox(GdImage $image, bool $transparent = true): array
{
    $box = [imagesx($image), imagesy($image), -1, -1];

    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixel = imagecolorat($image, $x, $y);
            $isSubject = $transparent ? (($pixel >> 24) & 127) < 80 : (($pixel >> 16) & 255) < 200;

            if ($isSubject) {
                $box = [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
            }
        }
    }

    return $box;
}

it('crops the picture to the subject with the padding all round', function (): void {
    // A 200 x 80 subject: 2% of its longer side is 4 px.
    $cropped = (new SubjectAligner)->crop(cropImage(100, 100, 200, 80), 0.02, 1200);

    expect(imagesx($cropped))->toBe(208)->and(imagesy($cropped))->toBe(88)
        // The subject starts 4 px in from the left and the top, and ends 4 px from the right and the bottom.
        ->and(cropBox($cropped))->toBe([4, 4, 203, 83]);
});

it('keeps at least 2 pixels of padding, even with none asked for', function (): void {
    $cropped = (new SubjectAligner)->crop(cropImage(100, 100, 200, 80), 0.0, 1200);

    expect(imagesx($cropped))->toBe(204)->and(imagesy($cropped))->toBe(84)
        ->and(cropBox($cropped))->toBe([2, 2, 201, 81]);
});

it('crops a subject in a corner just the same, cutting the padding where the picture ends', function (): void {
    $cropped = (new SubjectAligner)->crop(cropImage(0, 0, 100, 50), 0.02, 1200);

    // Nothing is added beyond the original: 2 px of padding at the right and the bottom only.
    expect(imagesx($cropped))->toBe(102)->and(imagesy($cropped))->toBe(52)
        ->and(cropBox($cropped))->toBe([0, 0, 99, 49]);
});

it('returns the same image when the subject fills it already and nothing needs shrinking', function (): void {
    $image = cropImage(0, 0, 400, 300);

    expect((new SubjectAligner)->crop($image, 0.02, 1200))->toBe($image);
});

it('scales the cropped picture down to the longest side, never up', function (): void {
    // A 360 x 100 subject cropped to 374 x 114 (7 px of padding), then shrunk so the longer side is 187.
    $shrunk = (new SubjectAligner)->crop(cropImage(20, 100, 360, 100), 0.02, 187);

    expect(imagesx($shrunk))->toBe(187)->and(imagesy($shrunk))->toBe(57);

    // A large limit never enlarges a small picture.
    $kept = (new SubjectAligner)->crop(cropImage(100, 100, 200, 80), 0.02, 5000);

    expect(imagesx($kept))->toBe(208)->and(imagesy($kept))->toBe(88);
});

it('limits a tall subject by its height, not its width', function (): void {
    // A 100 x 280 boot: cropped to 112 x 292 (6 px of padding), shrunk so the longer side (the height) is 146.
    $cropped = (new SubjectAligner)->crop(cropImage(150, 10, 100, 280), 0.02, 146);

    expect(imagesy($cropped))->toBe(146)->and(imagesx($cropped))->toBe(56);
});

it('returns the same image when it finds no subject', function (): void {
    $image = cropImage(0, 0, 1, 1);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

    expect((new SubjectAligner)->crop($image, 0.02, 1200))->toBe($image);
});

it('takes a speck for noise, not a subject', function (): void {
    $image = cropImage(10, 10, 3, 3);

    expect((new SubjectAligner)->crop($image, 0.02, 1200))->toBe($image);
});

it('finds the subject on an opaque background too, and keeps that background', function (): void {
    $cropped = (new SubjectAligner)->crop(cropImage(100, 100, 200, 80, transparent: false), 0.02, 1200);

    expect(imagesx($cropped))->toBe(208)->and(imagesy($cropped))->toBe(88)
        ->and(cropBox($cropped, transparent: false))->toBe([4, 4, 203, 83])
        // The corner of the crop is the photo's own white, still opaque.
        ->and(imagecolorat($cropped, 0, 0) & 0xFFFFFF)->toBe(0xFFFFFF)
        ->and((imagecolorat($cropped, 0, 0) >> 24) & 127)->toBe(0);
});

it('keeps the transparency of a transparent image', function (): void {
    $cropped = (new SubjectAligner)->crop(cropImage(100, 100, 200, 80), 0.02, 1200);

    expect((imagecolorat($cropped, 0, 0) >> 24) & 127)->toBe(127)
        ->and((imagecolorat($cropped, 100, 40) >> 24) & 127)->toBe(0);
});

it('does not let a faint shadow under the subject stretch the crop', function (): void {
    $image = cropImage(100, 100, 200, 80);
    // A shadow below the shoe, mostly transparent (alpha 110 of 127): not part of the subject.
    imagefilledrectangle($image, 120, 181, 280, 200, (int) imagecolorallocatealpha($image, 0, 0, 0, 110));

    $cropped = (new SubjectAligner)->crop($image, 0.02, 1200);

    // The subject and its 4 px of padding: the shadow inside the padding is kept, the rest of it is cut.
    expect(imagesy($cropped))->toBe(88)
        ->and((imagecolorat($cropped, 100, 85) >> 24) & 127)->toBeLessThan(127);
});
