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

    // Listing price ex VAT = vendor cost x markup, rounded to the penny.
    // This is the default; a vendor overrides it with `vendors.{CODE}.markup`.
    // A decimal string from 1.0 to 5, never a float. Anything else stops the
    // run when the mapper is resolved.
    'markup' => env('VENDORSYNC_MARKUP', '1.5'),

    // Price-change guard. When a variant's new vendor COST differs from its
    // stored cost by more than this percentage in one run, cost, price and
    // compare price are all kept and the change is held for review (listed
    // in the run report and the alert). Exactly this percentage passes.
    // 0 switches the guard off. A markup change never triggers it.
    'price_change_guard_percent' => (int) env('VENDORSYNC_PRICE_CHANGE_GUARD_PERCENT', 40),

    // Abort a catalogue run that sees fewer than this share of the products
    // seen by the previous successful run.
    'safety_threshold_percent' => 70,

    // Consecutive successful full runs a product may be absent from before
    // it is drafted.
    'discontinue_after_missed_runs' => 2,

    'messages_cap' => 200,

    // Products sent to the search index at a time at the end of a catalogue
    // run. A chunk that fails is reported and the run goes on; after three
    // failed chunks in a row the rest are not attempted (docs/vendorsync.md).
    'reindex_chunk' => 100,

    // Queue image jobs after a real catalogue run. `--without-images` turns
    // this off for one run; the command then prints the images command to run later.
    'queue_images' => (bool) env('VENDORSYNC_QUEUE_IMAGES', true),

    // Seconds a run holds its vendor and type lock. It must exceed the longest
    // expected run with margin (at least two hours); a run releases it when it ends.
    'lock_seconds' => 4 * 3600,

    'reports_path' => storage_path('app/vendorsync/reports'),

    'vendors' => [
        'SJ' => [
            'name' => 'Safety Jogger',
            // Null uses the default `markup` above.
            'markup' => env('VENDORSYNC_SJ_MARKUP'),
            'stock_buffer' => 0,
            // The 15 minute stock feed is fresher than the catalogue.
            'stock_authority' => 'feed',
        ],
        'FS' => [
            'name' => 'Footsure',
            'markup' => env('VENDORSYNC_FS_MARKUP'),
            'stock_buffer' => 1,
            'stock_authority' => 'catalogue',
        ],
    ],

    // The feature codes of the data contract. Anything else is dropped and
    // reported, never invented.
    'features' => [
        'SRA', 'SRB', 'SRC', 'SR', 'ESD', 'CI', 'HI', 'HRO', 'WR', 'FO', 'M', 'AN', 'E', 'A', 'P', 'LG', 'SC', 'WRU', 'METAL_FREE',
    ],

    // EU to UK size charts by vendor code. Safety Jogger's published chart,
    // identical for men and women. Never guess a missing conversion.
    'size_charts' => [
        'SJ' => [
            '35' => '3', '36' => '3.5', '37' => '4', '38' => '5', '39' => '6', '40' => '6.5', '41' => '7',
            '42' => '8', '43' => '9', '44' => '10', '45' => '10.5', '46' => '11', '47' => '12', '48' => '13',
            '49' => '13.5', '50' => '14',
            '35/36' => '3/3.5', '37/38' => '4/5', '39/40' => '6/6.5', '41/42' => '7/8', '43/44' => '9/10',
            '45/46' => '10.5/11', '47/48' => '12/13', '49/50' => '13.5/14',
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

        // The vendor's answers are not stable: the same account has returned
        // EUR prices for different products on different runs, and a product
        // detail has answered "an unexpected response". A product whose
        // response is not usable is fetched again, at most `refetches` more
        // times (never more than 2). The first re-fetch waits the first delay,
        // the second the second one (seconds). The first fully GBP response is
        // used; a product that never gets one keeps its stored GBP price
        // (docs/vendorsync.md, Currency instability).
        'refetches' => 2,
        'refetch_delay_seconds' => (int) env('VENDORSYNC_SJ_REFETCH_DELAY', 2),
        'refetch_second_delay_seconds' => (int) env('VENDORSYNC_SJ_REFETCH_DELAY_SECOND', 10),

        // Catalogue scope: article type in this list AND an EN ISO 20345 or
        // EN ISO 20347 norm. Everything else is out of scope.
        'article_types' => ['shoes'],
        'excluded_brands' => [],

        // RRP includes VAT at this rate whatever the tax class (basis points).
        'rrp_vat_basis_points' => 2000,

        // zero-protective only for these models with an EN ISO 20345 norm.
        // `low_boot` stays standard-rated until the accountant confirms.
        'boot_models' => ['high_boot', 'high'],

        // model name => contract `cut`.
        'cut_map' => [
            'low' => 'Low',
            'clog' => 'Low',
            'sandal' => 'Low',
            'pump' => 'Low',
            'low_boot' => 'Mid',
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
                    'sandal' => 'safety-shoes',
                    'clog' => 'safety-shoes',
                    'low_boot' => 'safety-boots',
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

        // The export's File_Create_Date format. The real feed is month/day/year
        // (11/15/2026 06:05:26). Read as day/month/year it parses without error
        // as a date in the future, so the staleness rule could never fire.
        'file_date_format' => 'm/d/Y H:i:s',

        // A file created further ahead than this is unreadable, not fresh.
        'max_future_minutes' => 60,

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
            'Mule Ladies Slippers' => 'slippers',
            'Mens Mule Slippers' => 'slippers',
            'Touch Fastening Mens Slippers' => 'slippers',
            'Trainers Safety' => 'safety-trainers',
            // Certified to EN ISO 20346 (protective footwear), class PB.
            'Ladies Safety Shoes' => 'safety-shoes',
            'Waterproof Safety Footwear' => 'safety-boots',
            'Mens Classic Lace Shoes' => 'occupational-footwear',
            'Plain PVC Wellingtons' => 'wellingtons',
            'Plain Rubber Wellingtons' => 'wellingtons',
            'Childrens Wellingtons' => 'wellingtons',
            'Waders' => 'wellingtons',
            'Canvas Ladies Summer' => 'casual-footwear',
            'Garden Shoes' => 'casual-footwear',
            'Boys BTS' => 'casual-footwear',
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
            'France' => 'FR',
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
        // Total attempts per URL. Only connection errors, HTTP 5xx and 429
        // are retried; a 404 or 403 fails at once.
        'retries' => 3,

        // Seconds for one request in total, connect included. A host that
        // accepts the connection and then stalls runs to this limit.
        'timeout' => 60,

        // Seconds to wait for the connection alone (an unreachable host).
        'connect_timeout' => 10,

        // Pause between attempts, in milliseconds.
        'retry_backoff_ms' => 500,

        // Minimum milliseconds between the starts of two requests to one
        // host, across all workers. Every HTTP attempt takes its own slot.
        'throttle_ms' => 250,

        // Seconds the short lock that hands out those slots may be held, and
        // that a worker waits for it. The lock is never held during a request.
        'slot_lock_seconds' => 5,

        // Seconds kept back at the end of an image job (its timeout minus
        // this is the deadline for starting another download), for saving
        // media and writing the run report.
        'job_margin_seconds' => 60,

        'refresh_days' => 7,

        // Largest image, in megapixels, that is stored and converted. With
        // the GD driver a conversion needs about 8 MB of memory per
        // megapixel on top of the worker's own 64 MB, so a 12 megapixel
        // vendor original does not fit in a 128 MB PHP process. A larger
        // image is replaced by its smaller rendition when the vendor has
        // one, and reported otherwise. Raise this only together with the
        // worker's memory limit, or after switching to Imagick.
        'max_megapixels' => (float) env('VENDORSYNC_IMAGE_MAX_MEGAPIXELS', 5.0),

        // Largest file accepted, in bytes. Media Library's own default of
        // 10 MB is raised to this for imports only.
        'max_file_bytes' => 20 * 1024 * 1024,
    ],

];
