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

    /** No variant can be imported (no cost, unknown size and so on). */
    public const string NO_SELLABLE_PRICE = 'no_sellable_price';

    /**
     * Every variant has a cost at or above its RRP. A data-review flag: the
     * vendor data probably mixes a pack price with a single-unit RRP.
     */
    public const string COST_AT_OR_ABOVE_RRP = 'cost_at_or_above_rrp';

    /**
     * A price came in a currency other than GBP. Never converted: the
     * variants stay unpriced and the product draft until a GBP price exists.
     */
    public const string NON_GBP_PRICE = 'non_gbp_price';

    public const string VENDOR_DISCONTINUED = 'vendor_discontinued';

    public const string MISSING_FROM_FEED = 'missing_from_feed';

    public const string NO_IMAGE = 'no_image';

    /**
     * Looks like several physical items sold under one SKU (a vendor
     * "Winterbox" bundle: a boot, socks, gloves and a beanie), not a single
     * product. Drafted for review rather than published under a single
     * category and a single image set.
     */
    public const string BUNDLE = 'bundle';
}
