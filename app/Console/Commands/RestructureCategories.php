<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Collections\RestructureCategories as Restructure;
use Illuminate\Console\Command;

final class RestructureCategories extends Command
{
    protected $signature = 'catalogue:restructure-categories {--dry-run : List what would change without writing anything}';

    protected $description = 'Move the contract categories into the category tree of the data contract and retire the ones it dropped (after the foundation seeder)';

    public function handle(Restructure $restructure): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $restructure->run($dryRun);

        foreach ($result['changes'] as $change) {
            $this->line(($dryRun ? '[dry run] ' : '').$change);
        }

        foreach ($result['missing'] as $handle) {
            $this->error("Category '{$handle}' does not exist. Run the foundation seeder first: php artisan db:seed --class=CatalogueFoundationSeeder --force");
        }

        $this->info(sprintf(
            '%s%d change(s)%s.',
            $dryRun ? '[dry run] ' : '',
            count($result['changes']),
            $result['changes'] === [] ? ': the category tree already matches the data contract' : '',
        ));

        return $result['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
