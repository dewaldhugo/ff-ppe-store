<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Brands;

/**
 * One brand on the brands page, and the scope of /brands/{slug}.
 */
final readonly class BrandTile
{
    /**
     * @param  list<string>  $memberNames  Lunar brand names, as indexed in the search `brand` field.
     * @param  ?string  $background  Six hex digits without #, or null for white.
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $memberNames,
        public ?string $logoUrl,
        public ?string $background,
        public int $productCount,
    ) {}

    public function monogram(): string
    {
        return Monogram::initials($this->name);
    }

    /**
     * @return array{background: string, text: string}
     */
    public function monogramColours(): array
    {
        return Monogram::colours($this->slug);
    }
}
