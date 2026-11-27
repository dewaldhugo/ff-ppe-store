<?php

declare(strict_types=1);

use App\Domain\Catalogue\Media\VideoUploadLimit;

// The largest product video the admin can really take (App\Domain\Catalogue\Media\VideoUploadLimit).

it('allows the store\'s 200 MB when PHP allows at least that', function (): void {
    $limit = new VideoUploadLimit('256M', '300M');

    expect($limit->kilobytes())->toBe(204800)
        ->and($limit->label())->toBe('200 MB')
        ->and($limit->isReducedByServer())->toBeFalse()
        ->and($limit->helperText())->toBe('MP4 or WebM, up to 200 MB each.');
});

it('is held down to the smaller of PHP\'s two limits', function (): void {
    expect((new VideoUploadLimit('2M', '8M'))->kilobytes())->toBe(2048)
        ->and((new VideoUploadLimit('64M', '32M'))->kilobytes())->toBe(32768)
        ->and((new VideoUploadLimit('1G', '1G'))->kilobytes())->toBe(204800)
        ->and((new VideoUploadLimit('512K', '8M'))->label())->toBe('512 KB');
});

it('treats 0 and -1 as no limit from PHP', function (): void {
    expect((new VideoUploadLimit('0', '-1'))->kilobytes())->toBe(204800)
        ->and((new VideoUploadLimit('0', '50M'))->kilobytes())->toBe(51200);
});

it('names the limit and what to do when this server\'s is lower than the store\'s', function (): void {
    $limit = new VideoUploadLimit('2M', '8M');

    expect($limit->isReducedByServer())->toBeTrue()
        ->and($limit->label())->toBe('2 MB')
        ->and($limit->helperText())
        ->toContain('up to 2 MB each')
        ->toContain('upload_max_filesize and post_max_size')
        ->toContain('200 MB the store allows')
        ->toContain('add the video by its web address');
});

it('gives a clear message for a video that is too big, a failed upload and a file that is not a video', function (): void {
    $messages = (new VideoUploadLimit('2M', '8M'))->validationMessages();

    expect($messages)->toHaveKeys(['max', 'uploaded', 'mimetypes', 'file'])
        ->and($messages['max'])->toContain('bigger than the 2 MB this server accepts')
        ->and($messages['uploaded'])->toContain('could not be uploaded')->toContain('2 MB')
        ->and($messages['mimetypes'])->toContain('MP4 or WebM');
});
