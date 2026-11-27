<?php

declare(strict_types=1);
use App\Domain\Catalogue\Media\VideoUploadLimit;

// Livewire holds every upload to 12 MB unless told otherwise, which would turn a product video away before the form sees it
// (config/livewire.php, App\Domain\Catalogue\Media\VideoUploadLimit).

it('lets Livewire take a file as big as the store\'s 200 MB video limit', function (): void {
    $rules = (array) config('livewire.temporary_file_upload.rules');

    expect($rules)->toContain('max:204800')
        ->and((int) config('livewire.temporary_file_upload.max_upload_time'))->toBeGreaterThanOrEqual(30);
});

it('lets the media library save a video as big as the store\'s 200 MB limit', function (): void {
    // At 10 MB (the package's own limit) a 12 MB video passed the upload and was then refused when the form was saved.
    expect((int) config('media-library.max_file_size'))->toBeGreaterThanOrEqual(VideoUploadLimit::MAX_KILOBYTES * 1024);
});
