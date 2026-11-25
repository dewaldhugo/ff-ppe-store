<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\SubjectAligner;

// The subject (shoe or boot) of a product photo is put on a common baseline: 6% of the height under it, never scaled up, scaled
// down only when it would leave less than 2% above it, and never moved sideways.

/**
 * A 400 x 300 image with a rectangle as the subject.
 *
 * @param  bool  $transparent  A transparent canvas, or an opaque white one.
 */
function alignerImage(int $x, int $y, int $width, int $height, bool $transparent = true): GdImage
{
    $image = imagecreatetruecolor(400, 300);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $transparent ? (int) imagecolorallocatealpha($image, 0, 0, 0, 127) : (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, (int) imagecolorallocate($image, 30, 40, 50));

    return $image;
}

/**
 * The box round what is not background, as [left, top, right, bottom] inclusive, at full resolution.
 *
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function alignerBox(GdImage $image, bool $transparent = true): array
{
    $box = [400, 300, -1, -1];

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

it('moves a shoe floating in the middle down to the baseline', function (): void {
    $image = alignerImage(100, 100, 200, 80);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02));

    // The baseline is 94% of 300 = 282. The size and the sideways position are as they were.
    expect($box[3])->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(283)
        ->and($box[3] - $box[1] + 1)->toBeGreaterThanOrEqual(79)->toBeLessThanOrEqual(82)
        ->and($box[0])->toBeGreaterThanOrEqual(98)->toBeLessThanOrEqual(102)
        ->and($box[2])->toBeGreaterThanOrEqual(297)->toBeLessThanOrEqual(302);
});

it('gives a subject that touches the bottom edge some room under it', function (): void {
    $image = alignerImage(100, 220, 200, 80);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02));

    expect($box[3])->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(283)
        ->and($box[3] - $box[1] + 1)->toBeGreaterThanOrEqual(79)->toBeLessThanOrEqual(82);
});

it('scales down a boot that fills the height so that it fits above the baseline, and keeps it centred', function (): void {
    $image = alignerImage(100, 0, 200, 300);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02));

    // 282 - 6 = 276 high, bottom on the baseline, still centred on x = 200.
    expect($box[3])->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(283)
        ->and($box[3] - $box[1] + 1)->toBeGreaterThanOrEqual(273)->toBeLessThanOrEqual(279)
        ->and(($box[0] + $box[2]) / 2)->toBeGreaterThan(197)->toBeLessThan(203);
});

it('never scales a subject up', function (): void {
    $image = alignerImage(150, 120, 100, 50);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02));

    expect($box[3] - $box[1] + 1)->toBeLessThanOrEqual(52)->toBeGreaterThanOrEqual(48)
        ->and($box[2] - $box[0] + 1)->toBeLessThanOrEqual(102)->toBeGreaterThanOrEqual(98);
});

it('returns the same image when the subject is already on the baseline', function (): void {
    $image = alignerImage(100, 202, 200, 80);

    expect((new SubjectAligner)->align($image, 0.06, 0.02))->toBe($image);
});

it('returns the same image when it finds no subject', function (): void {
    $image = alignerImage(0, 0, 1, 1);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

    expect((new SubjectAligner)->align($image, 0.06, 0.02))->toBe($image);
});

it('finds the subject on an opaque background too, and keeps that background', function (): void {
    $image = alignerImage(100, 100, 200, 80, transparent: false);

    $aligned = (new SubjectAligner)->align($image, 0.06, 0.02);
    $box = alignerBox($aligned, transparent: false);

    expect($box[3])->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(283)
        // The corner is still white, and the old position is white now.
        ->and(imagecolorat($aligned, 0, 0) & 0xFFFFFF)->toBe(0xFFFFFF)
        ->and(imagecolorat($aligned, 200, 110) & 0xFFFFFF)->toBe(0xFFFFFF);
});

it('keeps the transparency of a transparent image', function (): void {
    $aligned = (new SubjectAligner)->align(alignerImage(100, 100, 200, 80), 0.06, 0.02);

    expect((imagecolorat($aligned, 0, 0) >> 24) & 127)->toBe(127)
        ->and((imagecolorat($aligned, 200, 110) >> 24) & 127)->toBe(127);
});

it('ignores a faint shadow under the subject', function (): void {
    $image = alignerImage(100, 100, 200, 80);
    // A shadow below the shoe, mostly transparent (alpha 110 of 127).
    imagefilledrectangle($image, 120, 181, 280, 200, (int) imagecolorallocatealpha($image, 0, 0, 0, 110));

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02));

    // The shoe, not the shadow, sits on the baseline.
    expect($box[3])->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(283);
});

it('moves the faint shadow with the shoe, so it is still under it', function (): void {
    $image = alignerImage(100, 100, 200, 80);
    // A shadow just under the shoe (rows 181 to 200), mostly transparent.
    imagefilledrectangle($image, 120, 181, 280, 200, (int) imagecolorallocatealpha($image, 0, 0, 0, 110));

    $aligned = (new SubjectAligner)->align($image, 0.12, 0.02);
    $shoe = alignerBox($aligned);

    // The shoe's bottom is on the baseline (88% of 300 = 264). A few rows under it the shadow is still there, not cut off:
    // not fully transparent, and not as solid as the shoe.
    $shadowAlpha = (imagecolorat($aligned, 200, $shoe[3] + 6) >> 24) & 127;

    expect($shoe[3])->toBeGreaterThanOrEqual(262)->toBeLessThanOrEqual(266)
        ->and($shadowAlpha)->toBeLessThan(127)->toBeGreaterThan(80);
});

it('keeps a wide subject clear of the sides, scaling it down to fit and keeping it centred', function (): void {
    // A shoe that fills the whole width: columns 0 to 399.
    $image = alignerImage(0, 100, 400, 80);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02, 0.04));

    // 4% of 400 = 16 px each side, so at most 368 wide, still centred, and on the baseline.
    expect($box[0])->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(18)
        ->and($box[2])->toBeGreaterThanOrEqual(381)->toBeLessThanOrEqual(384)
        ->and(($box[0] + $box[2]) / 2)->toBeGreaterThan(197)->toBeLessThan(203)
        ->and($box[3])->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(283);
});

it('pushes a subject that is off to one side in from that side without changing its size', function (): void {
    // 10 px from the left edge, 120 wide: not wide, but too close to the left.
    $image = alignerImage(4, 100, 120, 80);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02, 0.04));

    expect($box[0])->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(18)
        ->and($box[2] - $box[0] + 1)->toBeGreaterThanOrEqual(118)->toBeLessThanOrEqual(122);
});

it('leaves a subject alone that is on the baseline and clear of the sides', function (): void {
    $image = alignerImage(60, 202, 280, 80);

    expect((new SubjectAligner)->align($image, 0.06, 0.02, 0.04))->toBe($image);
});

it('does not move a subject sideways when there is no side margin asked for', function (): void {
    $image = alignerImage(0, 100, 400, 80);

    $box = alignerBox((new SubjectAligner)->align($image, 0.06, 0.02));

    expect($box[0])->toBeLessThanOrEqual(1)->and($box[2])->toBeGreaterThanOrEqual(398);
});

it('puts a wide picture on a square canvas, with the subject on that canvas\'s baseline', function (): void {
    // 400 x 300 becomes 400 x 400: the baseline is 94% of 400 = 376.
    $aligned = (new SubjectAligner)->align(alignerImage(40, 100, 320, 100), 0.06, 0.02, 0.04, 1.0);

    $box = alignerBox($aligned);

    expect(imagesx($aligned))->toBe(400)->and(imagesy($aligned))->toBe(400)
        ->and($box[3])->toBeGreaterThanOrEqual(374)->toBeLessThanOrEqual(377)
        // Not scaled: it fits (320 wide is inside the 4% margins of 400, which allow 368).
        ->and($box[2] - $box[0] + 1)->toBeGreaterThanOrEqual(318)->toBeLessThanOrEqual(322);
});

it('puts a wide shoe and a tall boot on the same baseline of the same canvas', function (): void {
    $shoe = (new SubjectAligner)->align(alignerImage(20, 120, 360, 90), 0.06, 0.02, 0.04, 1.0);
    $boot = (new SubjectAligner)->align(alignerImage(120, 0, 160, 300), 0.06, 0.02, 0.04, 1.0);

    expect(imagesy($shoe))->toBe(imagesy($boot))
        ->and(abs(alignerBox($shoe)[3] - alignerBox($boot)[3]))->toBeLessThanOrEqual(3);
});

it('keeps the canvas as it is when no shape is asked for', function (): void {
    $aligned = (new SubjectAligner)->align(alignerImage(100, 100, 200, 80), 0.06, 0.02, 0.04, null);

    expect(imagesx($aligned))->toBe(400)->and(imagesy($aligned))->toBe(300);
});
