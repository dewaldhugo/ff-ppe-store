<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Vat;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\Actions\Taxes\GetTaxZone;
use Lunar\Base\ValueObjects\Cart\TaxBreakdown;
use Lunar\Base\ValueObjects\Cart\TaxBreakdownAmount;
use Lunar\DataTypes\Price;
use Lunar\Drivers\SystemTaxDriver;
use Lunar\Models\ProductVariant;

/**
 * Lunar's system tax driver, with one store rule: every customer is a vetted business
 * buying for its employees, so a `zero-protective` line (protective boots and helmets) is
 * charged at the standard rate, not at the 0% individual-use rate its tax class holds.
 *
 * The class keeps its 0% rate in Lunar on purpose (docs/data-contract.md, VAT): the rule
 * lives here, in one place, and only this driver knows about it. `zero-childrens` stays 0%
 * and is shown as zero-rated. Prices are stored ex VAT, so only that branch of the system
 * driver is needed.
 */
final class StoreTaxDriver extends SystemTaxDriver
{
    public function getBreakdown($subTotal): TaxBreakdown
    {
        if (! $this->isEmployerRated()) {
            return parent::getBreakdown($subTotal);
        }

        $taxZone = $this->taxZone ?? app(GetTaxZone::class)->execute($this->shippingAddress);

        $amounts = $taxZone->taxAmounts()
            ->whereTaxClassId(CatalogueKeys::taxClass('standard')->id)
            ->get();

        $breakdown = new TaxBreakdown;

        foreach ($amounts as $amount) {
            $percentage = (float) $amount->percentage;

            $breakdown->addAmount(new TaxBreakdownAmount(
                price: new Price((int) round($subTotal * ($percentage / 100)), $this->currency, $this->purchasable->getUnitQuantity()),
                identifier: "tax_rate_{$amount->taxRate->id}",
                description: $amount->taxRate->name,
                percentage: $percentage,
            ));
        }

        return $breakdown;
    }

    private function isEmployerRated(): bool
    {
        return $this->purchasable instanceof ProductVariant
            && $this->purchasable->tax_class_id === CatalogueKeys::taxClass('zero-protective')->id;
    }
}
