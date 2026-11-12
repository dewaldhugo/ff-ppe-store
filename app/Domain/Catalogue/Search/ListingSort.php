<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

use App\Domain\Pricing\VatDisplayMode;

enum ListingSort: string
{
    case Relevance = 'relevance';
    case PriceAscending = 'price-asc';
    case PriceDescending = 'price-desc';
    case Newest = 'newest';

    public function label(): string
    {
        return match ($this) {
            self::Relevance => 'Relevance',
            self::PriceAscending => 'Price: low to high',
            self::PriceDescending => 'Price: high to low',
            self::Newest => 'Newest',
        };
    }

    /**
     * Meilisearch sort expressions. Price sorts follow the customer's VAT
     * display mode so the order matches the prices they see.
     *
     * @return list<string>
     */
    public function expressions(VatDisplayMode $vatMode): array
    {
        return match ($this) {
            self::Relevance => ['sort_order:asc'],
            self::PriceAscending => [$vatMode->priceField().':asc'],
            self::PriceDescending => [$vatMode->priceField().':desc'],
            self::Newest => ['created_at:desc'],
        };
    }
}
