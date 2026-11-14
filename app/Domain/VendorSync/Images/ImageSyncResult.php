<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

/**
 * What one product's image sync did.
 */
final class ImageSyncResult
{
    /** The product's media or status changed. */
    public bool $changed = false;

    /** Nothing needed doing: the image list is unchanged and the images are there. */
    public bool $upToDate = false;

    public int $planned = 0;

    public int $downloaded = 0;

    /** Images the product has when the sync ends. */
    public int $stored = 0;

    /** @var list<string> Images that could not be saved, each with its reason. */
    public array $failed = [];

    /** @var list<string> Things worth knowing that are not failures (a smaller rendition was used). */
    public array $notes = [];

    /** Why no work could be done at all. Null when some work was possible. */
    public ?string $blocked = null;
}
