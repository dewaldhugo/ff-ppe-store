<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * What it took to fetch one vendor product when the vendor's answer could not
 * be used at first: extra requests, whether they helped, and, for a product
 * that is still not in GBP after them, what each attempt looked like.
 */
final readonly class FetchOutcome
{
    /**
     * @param  int  $attempts  Requests made for the product (1 when nothing had to be retried).
     * @param  int  $currencyRefetches  Extra requests made because a price came back non-GBP.
     * @param  bool  $currencyRecovered  A re-fetch returned a fully GBP response and it was used.
     * @param  int  $responseRetries  Extra requests made because the response was unexpected.
     * @param  bool  $responseRecovered  A retry returned a usable response after an unexpected one.
     * @param  list<array<string, mixed>>|null  $diagnostics  Per attempt: currency fields seen and allow-listed
     *                                                        response headers. Only set for a product that is
     *                                                        still non-GBP after the re-fetches.
     */
    public function __construct(
        public int $attempts = 1,
        public int $currencyRefetches = 0,
        public bool $currencyRecovered = false,
        public int $responseRetries = 0,
        public bool $responseRecovered = false,
        public ?array $diagnostics = null,
    ) {}
}
