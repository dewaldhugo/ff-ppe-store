<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use Lunar\Models\Order;

/**
 * What became of a card payment: paid (the order is placed), still pending at the bank
 * (the webhook places the order when it clears), or failed (nothing was charged).
 */
final readonly class CardResult
{
    public function __construct(
        public bool $paid,
        public bool $pending = false,
        public ?Order $order = null,
        public ?string $message = null,
    ) {}

    public static function paid(Order $order): self
    {
        return new self(paid: true, order: $order);
    }

    public static function pending(?Order $order = null): self
    {
        return new self(paid: false, pending: true, order: $order);
    }

    public static function failed(?string $message = null): self
    {
        return new self(paid: false, message: $message);
    }
}
