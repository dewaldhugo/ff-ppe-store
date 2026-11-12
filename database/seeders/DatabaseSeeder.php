<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogueFoundationSeeder::class);

        // Fixtures stand in for the ingestion module. Never in production.
        if (app()->environment(['local', 'testing'])) {
            $this->call(FixtureCatalogueSeeder::class);
        }
    }
}
