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

    /**
     * The first image in the vendor's list (the product's hero) is not stored
     * and could not be saved this time. The product is not published while
     * this is true.
     */
    public bool $heroFailed = false;

    /**
     * The first image in the vendor's list could not be saved, but the product
     * already has a hero (its URL changed): the existing image is kept and the
     * product is left as it was. `heroRefreshFailure` is the failed image and
     * its reason.
     */
    public bool $heroKept = false;

    public ?string $heroRefreshFailure = null;

    /** Failed images whose download never started because the host's slot lock stayed busy. */
    public int $lockTimeouts = 0;

    /** Failed images that were never tried because the job's time budget ran out. */
    public int $notAttempted = 0;

    /** @var list<string> Things worth knowing that are not failures (a smaller rendition was used). */
    public array $notes = [];

    /** Why no work could be done at all. Null when some work was possible. */
    public ?string $blocked = null;
}
