<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

/**
 * The listing price rule of the data contract:
 * price ex VAT = cost x markup, capped at the RRP ex VAT.
 *
 * A missing or zero RRP means no cap and no compare price. A variant with
 * no cost, or with a cost at or above a non-zero RRP, is not sellable.
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

        $price = min($price, $rrpExVatPence);
        $belowCost = $costPence >= $rrpExVatPence;

        return [
            'price' => $price,
            'compare' => $rrpExVatPence > $price ? $rrpExVatPence : null,
            'sellable' => ! $belowCost,
            'reason' => $belowCost ? self::COST_AT_OR_ABOVE_RRP : null,
        ];
    }
}
