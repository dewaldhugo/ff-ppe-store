<?php

declare(strict_types=1);

/*
 * Who the store is, for the header, the footer and the UK trading disclosure.
 * Every value comes from .env and reads "TBC" until it is known.
 */
return [
    'name' => env('STORE_NAME', 'TBC'),
    'legal_name' => env('STORE_LEGAL_NAME', 'TBC'),
    'company_number' => env('STORE_COMPANY_NUMBER', 'TBC'),
    'registered_office' => env('STORE_REGISTERED_OFFICE', 'TBC'),

    // The address customers see in the footer and on the Contact page. It may differ
    // from the registered office, which only appears in the trading disclosure.
    'address' => env('STORE_ADDRESS', 'TBC'),
    'vat_number' => env('STORE_VAT_NUMBER', 'TBC'),
    'phone' => env('STORE_PHONE', 'TBC'),
    'email' => env('STORE_EMAIL', 'TBC'),

    // Where Contact page enquiries are emailed. Falls back to STORE_EMAIL.
    'enquiry_email' => env('STORE_ENQUIRY_EMAIL') ?: env('STORE_EMAIL', 'TBC'),

    // One switch for search engines. While true, every page of the site carries
    // <meta name="robots" content="noindex">. Set STORE_NOINDEX=false at launch
    // (see the Launch checklist in CLAUDE.md). It defaults to true so a new
    // environment is never indexed by accident.
    'noindex' => (bool) env('STORE_NOINDEX', true),
];
