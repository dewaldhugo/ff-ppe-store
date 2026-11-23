<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs OldInvoicesSeeder as a check only: reads the data and the catalogue, lists every line that cannot be matched and
 * every invoice number problem, and writes nothing.
 */
final class OldInvoicesCheckSeeder extends Seeder
{
    public function run(): void
    {
        $seeder = new OldInvoicesSeeder;
        $seeder->dryRun = true;
        $seeder->setCommand($this->command);
        $seeder->run();
    }
}
