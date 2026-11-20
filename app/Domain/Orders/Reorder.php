<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Cart\CartRejected;
use App\Domain\Cart\CustomerCart;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;

/**
 * "Buy again": puts the goods from a past order into the signed-in customer's cart, in the same
 * quantities, on top of whatever is already there. Everything goes through CustomerCart::add(), so the
 * usual rules hold: a product withdrawn since, or short of stock, is refused with its reason and left out
 * while the rest are added. Prices are today's (Lunar prices the cart), not the old order's, and delivery
 * and VAT are worked out again at checkout. The delivery line of the old order is never copied.
 */
final class Reorder
{
    public function __construct(private readonly CustomerCart $cart) {}

    /**
     * @return array{added: int, skipped: list<string>} `added` counts items (units); `skipped` says what was left out and why.
     */
    public function __invoke(Order $order): array
    {
        $added = 0;
        $skipped = [];

        foreach ($order->lines->where('type', 'physical') as $line) {
            $variant = $line->purchasable;

            if (! $variant instanceof ProductVariant) {
                $skipped[] = $line->description.' (no longer sold)';

                continue;
            }

            try {
                $this->cart->add($variant, (int) $line->quantity);
                $added += (int) $line->quantity;
            } catch (CartRejected $rejected) {
                $skipped[] = $line->description.' ('.rtrim($rejected->getMessage(), '.').')';
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }
}
