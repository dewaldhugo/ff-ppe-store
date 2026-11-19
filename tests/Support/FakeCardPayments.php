<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Checkout\CardPayments;
use App\Domain\Checkout\CardResult;
use App\Domain\Checkout\CheckoutRejected;
use Lunar\Models\Cart;
use Lunar\Models\Order;

/**
 * Stands in for Stripe in tests: nothing leaves the process. It records the carts and orders it was
 * asked for, hands out predictable intent ids, and settles with whatever outcome the test sets.
 */
final class FakeCardPayments implements CardPayments
{
    public bool $configured = true;

    public int $amount = 6000;

    public ?string $rejectIntent = null;

    /** @var array<string, Cart> */
    public array $carts = [];

    /** @var list<array{cart: int, order: string, total: int}> */
    public array $intents = [];

    /** @var list<string> */
    public array $settled = [];

    public ?CardResult $outcome = null;

    /** Makes card payment available, and binds this fake as the app's CardPayments. */
    public static function install(): self
    {
        $fake = new self;
        app()->instance(CardPayments::class, $fake);

        return $fake;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function amount(Cart $cart): int
    {
        return $this->amount;
    }

    public function intentFor(Cart $cart, Order $order): array
    {
        if ($this->rejectIntent !== null) {
            throw new CheckoutRejected($this->rejectIntent);
        }

        $id = 'pi_test_'.(count($this->intents) + 1).'abcdef';
        $this->carts[$id] = $cart;
        $this->intents[] = ['cart' => $cart->id, 'order' => $order->reference, 'total' => $order->total->value];

        return ['id' => $id, 'client_secret' => $id.'_secret_xyz'];
    }

    public function cartForIntent(string $intentId): ?Cart
    {
        return $this->carts[$intentId] ?? null;
    }

    public function settle(Cart $cart, string $intentId): CardResult
    {
        $this->settled[] = $intentId;

        return $this->outcome ?? CardResult::failed();
    }
}
