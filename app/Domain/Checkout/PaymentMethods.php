<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use App\Models\User;

/**
 * Which payment methods a customer may use at checkout. One definition, read by the checkout
 * page and by PlaceOrder.
 *
 * Every customer sees card. It can be chosen once Stripe is configured (both keys in .env, see
 * CardPayments::isConfigured()); until then it shows as not available and a customer without account
 * terms cannot place an order. 30 day account terms are offered only to a customer staff have
 * switched them on for in the admin panel.
 */
final class PaymentMethods
{
    public const string CARD = 'card';

    /** The Lunar payment type (config/lunar/payments.php) for 30 day account orders. */
    public const string ACCOUNT = 'account-30-day';

    /** Whether card payment can be chosen right now. */
    public static function cardLive(): bool
    {
        return app(CardPayments::class)->isConfigured();
    }

    /**
     * The methods the customer can choose right now, card first.
     *
     * @return list<string>
     */
    public static function available(User $user): array
    {
        return array_values(array_filter([
            self::cardLive() ? self::CARD : null,
            $user->hasAccountTerms() ? self::ACCOUNT : null,
        ]));
    }

    public static function canUse(User $user, string $method): bool
    {
        return in_array($method, self::available($user), true);
    }

    /** The method to preselect: the first one the customer can use, or none. */
    public static function default(User $user): string
    {
        return self::available($user)[0] ?? '';
    }
}
