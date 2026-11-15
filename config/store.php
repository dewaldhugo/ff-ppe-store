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
    'vat_number' => env('STORE_VAT_NUMBER', 'TBC'),
    'phone' => env('STORE_PHONE', 'TBC'),
    'email' => env('STORE_EMAIL', 'TBC'),
];
