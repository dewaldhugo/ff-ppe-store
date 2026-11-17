<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The store's identity from config/store.php.
 */
final class Store
{
    public static function name(): string
    {
        return (string) config('store.name');
    }

    public static function legalName(): string
    {
        return (string) config('store.legal_name');
    }

    public static function address(): string
    {
        return (string) config('store.registered_office');
    }

    /**
     * The address shown to customers (footer, Contact page). Not the registered
     * office, which `address()` returns for the trading disclosure.
     */
    public static function contactAddress(): string
    {
        return (string) config('store.address');
    }

    public static function phone(): string
    {
        return (string) config('store.phone');
    }

    public static function email(): string
    {
        return (string) config('store.email');
    }

    /**
     * A `tel:` link, only once the number is a real one. A placeholder is
     * shown as plain text, never as a dead link.
     */
    public static function phoneHref(): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', self::phone());

        return preg_match('/^\+?\d{7,15}$/', (string) $digits) === 1 ? 'tel:'.$digits : null;
    }

    public static function emailHref(): ?string
    {
        return filter_var(self::email(), FILTER_VALIDATE_EMAIL) === false ? null : 'mailto:'.self::email();
    }

    /**
     * The UK trading disclosure shown in the footer.
     */
    public static function disclosure(): string
    {
        return sprintf(
            '%s, registered in England and Wales, company number %s. Registered office: %s. VAT number %s.',
            self::legalName(),
            config('store.company_number'),
            self::address(),
            config('store.vat_number'),
        );
    }
}
