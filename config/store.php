<?php

declare(strict_types=1);

/*
 * Who the store is, for the header, the footer and the UK trading disclosure.
 * The address, phone number and email are fixed here, not in .env. The other
 * values come from .env and read "TBC" until they are known.
 */
return [
    'name' => env('STORE_NAME', 'TBC'),
    'legal_name' => env('STORE_LEGAL_NAME', 'TBC'),
    'company_number' => env('STORE_COMPANY_NUMBER', 'TBC'),
    'registered_office' => env('STORE_REGISTERED_OFFICE', 'TBC'),

    // The address customers see in the footer and on the Contact page. It may differ
    // from the registered office, which only appears in the trading disclosure.
    'address' => '7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA',
    'vat_number' => env('STORE_VAT_NUMBER', 'TBC'),
    'phone' => '+44 7557 687679',
    'email' => 'info@frontlinesafetygear.com',

    // Where Contact page enquiries are emailed: the store email, unless STORE_ENQUIRY_EMAIL is set.
    'enquiry_email' => env('STORE_ENQUIRY_EMAIL') ?: 'info@frontlinesafetygear.com',

    // Who is emailed when a customer places an order: addresses separated by commas, in .env
    // (STORE_ORDER_NOTIFICATION_EMAILS). Empty by default, so nothing is sent until it is set.
    // App\Domain\Orders\NotifyStaffOfNewOrder, docs/cart.md.
    'order_notification_emails' => env('STORE_ORDER_NOTIFICATION_EMAILS', ''),

    // Who the invoice comes from, printed at the top left and in the footer of every invoice
    // (docs/accounts.md, Invoices). Fixed here, with no environment variable: it never changes. The
    // registered office line is worded as the client gave it.
    'invoice' => [
        'name' => 'Frontline Holdings Ltd',
        'trading_as' => 'Frontline Footwear Ltd',
        'address' => '7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA',
        'vat_number' => '377 3202 94',
        'email' => 'accounts@frontlinesafetygear.com',
        'website' => 'www.frontlinesafetygear.com',
        'company_number' => '13184904',
        'registered_office' => 'Attention: Frontline Footwear Ltd, 7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA',
    ],

    // The bank account customers pay 30 day account invoices into. Printed on an unpaid account invoice
    // and its payment advice slip (docs/accounts.md, Invoices). Fixed here, with no environment variable,
    // like the address, phone and email: it never changes. Taken from the client's sample invoice.
    'bank' => [
        'account_name' => 'Frontline Holdings Limited',
        'bank_name' => 'Wise Bank',
        'sort_code' => '23-08-01',
        'account_number' => '63606297',
        'iban' => 'GB90 TRWI 2308 0163 6062 97',
        'address' => '56 Shoreditch High Street, London, E1 6JJ, United Kingdom',
    ],

    // Delivery: a flat charge, free once the goods come to MORE than the free-over amount (exactly that
    // amount still pays). Pence, ex VAT, goods before discounts. The rule itself is
    // App\Domain\Cart\Delivery\DeliveryPolicy, which also words the note customers see.
    'delivery' => [
        'free_over' => 50_000, // GBP 500.00
        'charge' => 3_000,     // GBP 30.00
    ],

    // Returns, in days: how long after delivery a customer can return goods they do not want
    // (window_days), how soon they must report goods that arrive damaged, wrong or short
    // (report_days), and how long a refund takes once we have checked the return (refund_days).
    // Written from general good practice, not from the client: confirm with them before launch
    // (docs/launch-checklist.md). The Returns and Refunds page reads these, so it follows a change.
    'returns' => [
        'window_days' => 30,
        'report_days' => 3,
        'refund_days' => 14,
    ],

    // One switch for search engines. While true, every page of the site carries
    // <meta name="robots" content="noindex">. Set STORE_NOINDEX=false at launch
    // (see the Launch checklist in CLAUDE.md). It defaults to true so a new
    // environment is never indexed by accident.
    'noindex' => (bool) env('STORE_NOINDEX', true),

    // Whether a new customer must verify their email address before using My Account.
    // Defaults to true. Set STORE_REQUIRE_EMAIL_VERIFICATION=false on a server that has no
    // working mailer yet (app:preflight fails when this is on and MAIL_MAILER is log or array).
    'require_email_verification' => filter_var(env('STORE_REQUIRE_EMAIL_VERIFICATION', true), FILTER_VALIDATE_BOOL),
];
