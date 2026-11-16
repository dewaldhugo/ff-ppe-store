<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

/**
 * The listing price rule of the data contract:
 * price ex VAT = cost x markup, rounded to the penny.
 *
 * The price is never capped at the RRP. The RRP only decides two things:
 * the compare price (the RRP, when it is above the price) and whether the
 * variant is sellable. A variant with a cost at or above a non-zero RRP is
 * not sellable: that is a sign of bad data (a pack priced against a single
 * unit, say), so it is held at stock 0 and its product is drafted for review.
 * A missing or zero RRP means no compare price and no such check. A variant
 * with no cost is not sellable and has no price.
 */
final readonly class PriceCalculator
{
    public const string NO_COST = 'no_cost';

    public const string COST_AT_OR_ABOVE_RRP = 'cost_at_or_above_rrp';

    public function __construct(private string $markup) {}

    /**
     * @return array{price: ?int, compare: ?int, sellable: bool, reason: ?string}
     */
    public function calculate(?int $costPence, ?int $rrpExVatPence): array
    {
        if ($costPence === null || $costPence <= 0) {
            return ['price' => null, 'compare' => null, 'sellable' => false, 'reason' => self::NO_COST];
        }

        $price = Money::multiply($costPence, $this->markup);

        if ($rrpExVatPence === null || $rrpExVatPence <= 0) {
            return ['price' => $price, 'compare' => null, 'sellable' => true, 'reason' => null];
        }

        $belowCost = $costPence >= $rrpExVatPence;

        return [
            'price' => $price,
            'compare' => $rrpExVatPence > $price ? $rrpExVatPence : null,
            'sellable' => ! $belowCost,
            'reason' => $belowCost ? self::COST_AT_OR_ABOVE_RRP : null,
        ];
    }
}
