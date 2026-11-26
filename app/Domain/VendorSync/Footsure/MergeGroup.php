<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

/**
 * One product line the vendor split into several ProductIDs, as the database
 * has it today: several Lunar products, to become one.
 */
final readonly class MergeGroup
{
    /**
     * @param  list<string>  $ids  The line's vendor product IDs, ascending.
     * @param  list<array{product_id: int, slug: string, status: string, vendor_product_ids: list<string>, variants: int, images: int}>  $others  The products merged into the kept one.
     * @param  string|null  $skipReason  Set when the line cannot be merged safely: it is reported and left as it is.
     */
    public function __construct(
        public array $ids,
        public string $label,
        public int $keepProductId,
        public string $keepSlug,
        public string $keepStatus,
        public int $keepVariants,
        public int $keepImages,
        public array $others,
        public int $orderLines,
        public int $cartLines,
        public int $favourites,
        public ?string $skipReason = null,
    ) {}

    /**
     * @return list<int>
     */
    public function removedProductIds(): array
    {
        return array_map(fn (array $other): int => $other['product_id'], $this->others);
    }

    public function variants(): int
    {
        return $this->keepVariants + array_sum(array_column($this->others, 'variants'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->label,
            'vendor_product_ids' => $this->ids,
            'kept' => ['product_id' => $this->keepProductId, 'slug' => $this->keepSlug, 'status' => $this->keepStatus, 'variants' => $this->keepVariants, 'images' => $this->keepImages],
            'merged_in' => $this->others,
            'variants_after' => $this->variants(),
            'order_lines_on_moved_variants' => $this->orderLines,
            'cart_lines_on_moved_variants' => $this->cartLines,
            'favourites_on_removed_products' => $this->favourites,
            'skipped' => $this->skipReason,
        ];
    }
}
