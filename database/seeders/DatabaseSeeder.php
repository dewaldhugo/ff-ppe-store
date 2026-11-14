<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\VendorSync\Import\FixtureImport;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogueFoundationSeeder::class);

        // Local only, opt-in: import the recorded vendor fixtures through
        // the real ingestion pipeline instead of the fixture seeder.
        if (app()->environment('local') && config('vendorsync.seed_from_fixtures')) {
            foreach (app(FixtureImport::class)->run() as $report) {
                $this->command?->line("{$report->vendorCode} catalogue {$report->status}: {$report->created} created, {$report->errors} errors");
            }

            return;
        }

        // Fixtures stand in for the ingestion module. Never in production.
        if (app()->environment(['local', 'testing'])) {
            $this->call(FixtureCatalogueSeeder::class);
        }
    }
}
