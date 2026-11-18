<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

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

    public function isPriceSort(): bool
    {
        return $this === self::PriceAscending || $this === self::PriceDescending;
    }

    /**
     * Meilisearch sort expressions. Price sorts use the ex VAT price, so the
     * order matches the prices shown.
     *
     * @return list<string>
     */
    public function expressions(): array
    {
        return match ($this) {
            self::Relevance => ['sort_order:asc'],
            self::PriceAscending => [ListingQuery::PRICE_FIELD.':asc'],
            self::PriceDescending => [ListingQuery::PRICE_FIELD.':desc'],
            self::Newest => ['created_at:desc'],
        };
    }
}
