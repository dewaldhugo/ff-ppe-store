<?php

declare(strict_types=1);

namespace App\Domain\Cart\Delivery;

use Closure;
use Lunar\Base\ShippingModifier;
use Lunar\Facades\ShippingManifest;
use Lunar\Models\Contracts\Cart;

/**
 * Offers the store's delivery option on a cart. Lunar keeps the manifest for the whole
 * process and ignores an option whose identifier it already holds, so it is cleared first:
 * otherwise a second cart, or the same cart after its goods changed, would keep the first price.
 */
final class StoreShippingModifier extends ShippingModifier
{
    public function handle(Cart $cart, Closure $next)
    {
        ShippingManifest::clearOptions();
        ShippingManifest::addOption(app(DeliveryPolicy::class)->option($cart));

        return $next($cart);
    }
}
