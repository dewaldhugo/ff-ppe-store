<?php

declare(strict_types=1);

/*
 * Vendor ingestion (VendorSync). Owned by the ingestion module.
 *
 * Secrets come from the environment only and have no defaults here.
 */
return [

    // The schedule only registers when this is true, so a developer machine
    // never polls the production API on its own.
    'schedule_enabled' => (bool) env('VENDORSYNC_SCHEDULE_ENABLED', false),

    // Failed, aborted and stale runs are mailed here and logged as errors.
    'alert_email' => env('VENDORSYNC_ALERT_EMAIL'),

    // Local only: DatabaseSeeder imports tests/Fixtures/VendorSync through
    // the real pipeline instead of running the fixture seeder.
    'seed_from_fixtures' => (bool) env('VENDORSYNC_SEED_FROM_FIXTURES', false),

    // Listing price ex VAT = cost x markup, capped at the RRP ex VAT.
    // A decimal string, never a float.
    'markup' => '1.5',

    // Abort a catalogue run that sees fewer than this share of the products
    // seen by the previous successful run.
    'safety_threshold_percent' => 70,

    // Consecutive successful full runs a product may be absent from before
    // it is drafted.
    'discontinue_after_missed_runs' => 2,

    'messages_cap' => 200,

    'reports_path' => storage_path('app/vendorsync/reports'),

    'vendors' => [
        'SJ' => [
            'name' => 'Safety Jogger',
            'stock_buffer' => 0,
            // The 15 minute stock feed is fresher than the catalogue.
            'stock_authority' => 'feed',
        ],
        'FS' => [
            'name' => 'Footsure',
            'stock_buffer' => 1,
            'stock_authority' => 'catalogue',
        ],
    ],

    // The feature codes of the data contract. Anything else is dropped and
    // reported, never invented.
    'features' => [
        'SRA', 'SRB', 'SRC', 'SR', 'ESD', 'CI', 'HI', 'HRO', 'WR', 'FO', 'M', 'AN', 'E', 'A', 'P', 'METAL_FREE',
    ],

    // EU to UK size charts by vendor code. Safety Jogger's published chart,
    // identical for men and women. Never guess a missing conversion.
    'size_charts' => [
        'SJ' => [
            '35' => '3', '36' => '3.5', '37' => '4', '38' => '5', '39' => '6', '40' => '6.5', '41' => '7',
            '42' => '8', '43' => '9', '44' => '10', '45' => '10.5', '46' => '11', '47' => '12', '48' => '13',
            '49' => '13.5',
            '35/36' => '3/3.5', '37/38' => '4/5', '39/40' => '6/6.5', '41/42' => '7/8', '43/44' => '9/10',
            '45/46' => '10.5/11', '47/48' => '12/13',
        ],
    ],

    'safety_jogger' => [
        'base_url' => env('VENDORSYNC_SJ_BASE_URL'),
        'username' => env('VENDORSYNC_SJ_USERNAME'),
        'password' => env('VENDORSYNC_SJ_PASSWORD'),
        // Used instead of the password when set.
        'token' => env('VENDORSYNC_SJ_TOKEN'),
        'validity_days' => 30,
        'language' => 'en',
        'timeout' => 30,
        'requests_per_second' => 2,
        'retries' => 3,

        // Catalogue scope: article type in this list AND an EN ISO 20345 or
        // EN ISO 20347 norm. Everything else is out of scope.
        'article_types' => ['shoes'],
        'excluded_brands' => [],

        // RRP includes VAT at this rate whatever the tax class (basis points).
        'rrp_vat_basis_points' => 2000,

        // zero-protective only for these models with an EN ISO 20345 norm.
        'boot_models' => ['high_boot', 'high'],

        // model name => contract `cut`.
        'cut_map' => [
            'low' => 'Low',
            'high' => 'High',
            'high_boot' => 'High',
        ],

        // Benefit name => contract feature code.
        'benefit_map' => [
            'NOMETAL' => 'METAL_FREE',
        ],

        // Norm family => article type => model => collection handle.
        // "safety" means an EN ISO 20345 norm, "occupational" EN ISO 20347
        // only. "*" matches any model.
        'categories' => [
            'safety' => [
                'shoes' => [
                    'low' => 'safety-shoes',
                    'high' => 'safety-boots',
                    'high_boot' => 'safety-boots',
                ],
            ],
            'occupational' => [
                'shoes' => [
                    '*' => 'occupational-footwear',
                ],
            ],
        ],

        // Local seeding only: where FixtureSource reads its JSON from.
        'fixtures_path' => base_path('tests/Fixtures/VendorSync/SafetyJogger'),
    ],

    'footsure' => [
        'incoming_path' => env('VENDORSYNC_FS_INCOMING_PATH', storage_path('app/vendorsync/footsure/incoming')),
        'archive_path' => env('VENDORSYNC_FS_ARCHIVE_PATH', storage_path('app/vendorsync/footsure/archive')),
        'archive_days' => 30,
        'max_age_hours' => 36,

        // Case-insensitive, matching any brand that starts with an entry.
        // Safety Jogger products come directly from the Safety Jogger API.
        'excluded_brands' => ['Safety Jogger'],

        // Vendor brand => customer-facing brand. Null means no brand.
        'brand_map' => [
            'Miscellaneous Other' => null,
        ],

        // Group Class => collection handle. An unmapped value drafts the
        // product and is reported.
        'categories' => [
            'Boots Safety' => 'safety-boots',
            'Goodyear Welted Safety' => 'safety-boots',
            'Shoes- Safety' => 'safety-shoes',
            'Mens Occupational Footwear' => 'occupational-footwear',
            'Classic Ladies Slippers' => 'slippers',
            'Classic Mens Slippers' => 'slippers',
            'Touch Fastening Ladies Slipper' => 'slippers',
            'Touch Fastening Ladies Bootees' => 'slippers',
            'Touch Fastening Mens Bootees' => 'slippers',
            'Gusset Plimsolls' => 'plimsolls',
            'Lace Plimsolls' => 'plimsolls',
            'Unisex Sports' => 'casual-footwear',
            'Mens Boots' => 'casual-footwear',
            'Insoles' => 'accessories',
        ],

        'gender_map' => [
            'Male' => 'Men',
            'Female' => 'Women',
            'Unisex' => 'Unisex',
        ],

        // Country name => ISO 3166-1 alpha-2. Unknown names are reported.
        'countries' => [
            'China' => 'CN',
            'Spain' => 'ES',
            'India' => 'IN',
            'Poland' => 'PL',
            'Portugal' => 'PT',
            'Italy' => 'IT',
            'Vietnam' => 'VN',
            'United Kingdom' => 'GB',
            'Indonesia' => 'ID',
            'Bangladesh' => 'BD',
            'Thailand' => 'TH',
            'Romania' => 'RO',
            'Turkey' => 'TR',
            'Pakistan' => 'PK',
            'Cambodia' => 'KH',
        ],

        // Rows without real measurements carry these placeholders. They are
        // stored as null.
        'placeholder_measurements' => [
            'weight' => '999',
            'length' => '333',
            'width' => '222',
            'height' => '111',
        ],

        // Local seeding only.
        'fixture_file' => base_path('tests/Fixtures/VendorSync/Footsure/footsure.csv'),
    ],

    'images' => [
        'retries' => 3,
        'timeout' => 60,
        // Minimum milliseconds between downloads from one host.
        'throttle_ms' => 250,
        'refresh_days' => 7,
    ],

];
