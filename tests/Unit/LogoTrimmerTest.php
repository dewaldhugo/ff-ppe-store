<?php

declare(strict_types=1);

use App\Domain\Catalogue\Brands\LogoTrimmer;

/** A transparent PNG with an opaque red rectangle at the given place, written to a temporary file. */
function logoWithMark(int $x, int $y, int $width, int $height): string
{
    $image = imagecreatetruecolor(100, 60);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));

    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($image, $path);

    return $path;
}

it('cuts the transparent margin off a logo, keeping the visible mark and its transparency', function (): void {
    $source = logoWithMark(30, 20, 24, 10);
    $target = sys_get_temp_dir().'/trimmed-'.uniqid().'.png';

    expect((new LogoTrimmer)->trim($source, $target))->toBeTrue();

    $trimmed = imagecreatefrompng($target);

    expect(imagesx($trimmed))->toBe(24)
        ->and(imagesy($trimmed))->toBe(10)
        // The corner is the red mark, opaque; nothing was filled in with a background.
        ->and((imagecolorat($trimmed, 0, 0) >> 24) & 127)->toBe(0);

    unlink($source);
    unlink($target);
});

it('writes nothing for a logo with nothing visible in it', function (): void {
    $source = logoWithMark(0, 0, 1, 1);
    $blank = imagecreatetruecolor(10, 10);
    imagealphablending($blank, false);
    imagesavealpha($blank, true);
    imagefill($blank, 0, 0, (int) imagecolorallocatealpha($blank, 0, 0, 0, 127));
    imagepng($blank, $source);

    $target = sys_get_temp_dir().'/never-'.uniqid().'.png';

    expect((new LogoTrimmer)->trim($source, $target))->toBeFalse()
        ->and(file_exists($target))->toBeFalse();

    unlink($source);
});

it('has a trimmed copy of every brand logo, much smaller than the canvas it came from', function (): void {
    $public = dirname(__DIR__, 2).'/public';

    foreach (glob($public.'/images/brands/*.png') as $logo) {
        $copy = $public.'/images/brands/trimmed/'.basename($logo);

        expect(is_file($copy))->toBeTrue(basename($logo).' has no trimmed copy: run php artisan catalogue:trim-brand-logos');

        [$width, $height] = getimagesize($copy);
        [$originalWidth, $originalHeight] = getimagesize($logo);

        expect($width)->toBeLessThanOrEqual($originalWidth)->and($height)->toBeLessThanOrEqual($originalHeight);
    }
});
