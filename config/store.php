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
    'address' => 'Stamford House, 2a Lagoon Road, Orpington, BR5 3QX',
    'vat_number' => env('STORE_VAT_NUMBER', 'TBC'),
    'phone' => '+44 20 8289 5858',
    'email' => 'info@thebridgegroup.uk.com',

    // Where Contact page enquiries are emailed: the store email, unless STORE_ENQUIRY_EMAIL is set.
    'enquiry_email' => env('STORE_ENQUIRY_EMAIL') ?: 'info@thebridgegroup.uk.com',

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
