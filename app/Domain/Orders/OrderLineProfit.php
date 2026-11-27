<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Lunar\Models\OrderLine;

/**
 * What a goods line earned: its total before VAT (after any discount) less what it cost. The cost is the vendor cost copied
 * onto the line when the order was placed (`cost_price` in the line's meta, per unit), so a later price change does not move
 * it. A line with no recorded cost (a hand-made variant, an order from before the snapshot) has the markup taken off its
 * price instead: cost = price / markup, the vendor's markup or the default one. All in whole pence.
 */
final class OrderLineProfit
{
    public function revenue(OrderLine $line): int
    {
        return (int) $line->sub_total->value;
    }

    public function cost(OrderLine $line): int
    {
        $meta = (array) $line->meta;
        $recorded = $meta['cost_price'] ?? null;

        return is_numeric($recorded)
            ? (int) $recorded * (int) $line->quantity
            : $this->costFromMarkup($this->revenue($line), (string) ($meta['vendor_code'] ?? ''));
    }

    public function profit(OrderLine $line): int
    {
        return $this->revenue($line) - $this->cost($line);
    }

    /** A price ex VAT with the markup taken off, in whole pence, rounded half up (the markup in thousandths, so no float touches the money). */
    private function costFromMarkup(int $pence, string $vendorCode): int
    {
        $markup = config('vendorsync.vendors.'.strtoupper($vendorCode).'.markup') ?? config('vendorsync.markup');
        $thousandths = is_numeric($markup) && (float) $markup >= 1.0 ? (int) round((float) $markup * 1000) : 1500;

        return intdiv($pence * 2000 + $thousandths, $thousandths * 2);
    }
}
