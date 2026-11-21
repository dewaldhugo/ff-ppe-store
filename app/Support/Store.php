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

    public static function companyNumber(): string
    {
        return (string) config('store.company_number');
    }

    public static function vatNumber(): string
    {
        return (string) config('store.vat_number');
    }

    /**
     * Who is emailed when a customer places an order: the valid, distinct addresses in
     * `STORE_ORDER_NOTIFICATION_EMAILS` (comma, semicolon or space separated). An empty list means
     * no notification is sent.
     *
     * @return list<string>
     */
    public static function orderNotificationEmails(): array
    {
        $addresses = preg_split('/[\s,;]+/', (string) config('store.order_notification_emails'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $valid = array_filter($addresses, fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false);

        return array_values(array_unique(array_map('strtolower', $valid)));
    }

    /**
     * One of the fixed details printed at the top and in the footer of an invoice (`config/store.php`,
     * `invoice`): name, trading_as, address, vat_number, email, website, company_number, registered_office.
     */
    public static function invoice(string $key): string
    {
        return (string) config('store.invoice.'.$key);
    }

    /**
     * The bank details printed on an unpaid 30 day account invoice, label to value, in print order. A
     * value that is not set reads TBC.
     *
     * @return array<string, string>
     */
    public static function bankDetails(): array
    {
        $bank = (array) config('store.bank');

        $value = fn (string $key): string => filled($bank[$key] ?? null) ? (string) $bank[$key] : 'TBC';

        return [
            'Account name' => $value('account_name'),
            'Bank' => $value('bank_name'),
            'Sort code' => $value('sort_code'),
            'Account number' => $value('account_number'),
            'IBAN' => $value('iban'),
            "Wise's address" => $value('address'),
        ];
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
