<?php

declare(strict_types=1);

namespace App\Domain\Orders;

/**
 * The customer-facing name of an order status: the label staff see in the admin
 * (config/lunar/orders.php), or the status made readable when it has none.
 */
final class OrderStatus
{
    public static function label(?string $status): string
    {
        $status = (string) $status;

        return (string) (config("lunar.orders.statuses.{$status}.label") ?? ucfirst(str_replace('-', ' ', $status)));
    }
}
