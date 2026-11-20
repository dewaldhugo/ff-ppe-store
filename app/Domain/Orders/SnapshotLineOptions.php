<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Closure;
use Lunar\Models\Contracts\Order as OrderContract;
use Lunar\Models\ProductVariant;

/**
 * Order creation step (config/lunar/orders.php, after CleanUpOrderLines, like SnapshotVendorOffers):
 * copies the variant's colour, size and EU size onto each goods line's meta (`colour`, `size`,
 * `size_is_uk`, `eu_size`), so the invoice shows them as they were when the order was placed, even if
 * the variant is edited or deleted later. A variant with no colour or size just leaves that key out.
 * `size_is_uk` is true when the size option is a footwear (UK) size.
 */
final class SnapshotLineOptions
{
    public function handle(OrderContract $order, Closure $next): mixed
    {
        $lines = $order->lines()
            ->where('type', 'physical')
            ->where('purchasable_type', (new ProductVariant)->getMorphClass())
            ->get();

        foreach ($lines as $line) {
            $variant = ProductVariant::query()->with('values.option')->find($line->purchasable_id);

            if ($variant === null) {
                continue;
            }

            $snapshot = [];

            foreach ($variant->values as $value) {
                $name = (string) $value->translate('name');

                if ($value->option->handle === 'colour') {
                    $snapshot['colour'] = $name;
                } else {
                    $snapshot['size'] = $name;
                    $snapshot['size_is_uk'] = $value->option->handle === 'shoe_size';
                }
            }

            $eu = trim((string) $variant->attr('eu_size'));

            if ($eu !== '') {
                $snapshot['eu_size'] = $eu;
            }

            if ($snapshot !== []) {
                $line->update(['meta' => array_merge((array) $line->meta, $snapshot)]);
            }
        }

        return $next($order);
    }
}
