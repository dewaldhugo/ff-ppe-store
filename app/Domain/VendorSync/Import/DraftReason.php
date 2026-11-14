<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

/**
 * Why a product is draft. A product is published only when it has none.
 */
final class DraftReason
{
    public const string UNMAPPED_CATEGORY = 'unmapped_category';

    public const string UNKNOWN_SIZE = 'unknown_size';

    public const string NO_COLOUR = 'no_colour';

    public const string VENDOR_DISCONTINUED = 'vendor_discontinued';

    public const string MISSING_FROM_FEED = 'missing_from_feed';

    public const string NO_IMAGE = 'no_image';
}
