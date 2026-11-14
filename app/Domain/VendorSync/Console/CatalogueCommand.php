<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunReport;
use Illuminate\Console\Command;

final class CatalogueCommand extends Command
{
    protected $signature = 'vendorsync:catalogue
        {vendor : Vendor code (SJ or FS)}
        {--dry-run : Run everything except the writes and print the counts and a sample}
        {--force : Reprocess products whose payload is unchanged}';

    protected $description = 'Import a vendor catalogue into Lunar and the vendor tables';

    public function handle(CatalogueImporter $importer, ImageRun $images): int
    {
        $vendor = strtoupper((string) $this->argument('vendor'));

        if (! app()->bound("vendorsync.source.{$vendor}")) {
            $this->error("Unknown vendor '{$vendor}'.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = $importer->run($vendor, $dryRun, (bool) $this->option('force'));

        $this->render($report);

        if (! $dryRun && $report->ok()) {
            $queued = $images->dispatch($vendor);
            $this->line("Queued {$queued} image jobs.");
        }

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function render(RunReport $report): void
    {
        $this->line(($report->dryRun ? 'DRY RUN, nothing was written. ' : '')."{$report->vendorCode} catalogue: {$report->status}");
        $this->table(array_keys($report->counts()), [array_values($report->counts())]);

        foreach ($report->tallies as $group => $tally) {
            ksort($tally);
            $this->line($group.': '.implode(', ', array_map(fn (string $key, int $count): string => "{$key} {$count}", array_keys($tally), $tally)));
        }

        if ($report->samples !== []) {
            $this->newLine();
            $this->line('Sample:');

            foreach ($report->samples as $sample) {
                $this->line('  '.$sample);
            }
        }

        if ($report->messages !== []) {
            $this->newLine();
            $this->line('Report ('.count($report->messages).' entries, first 30 shown):');

            foreach (array_slice($report->messages, 0, 30) as $message) {
                $this->line('  '.$message);
            }

            if ($report->dryRun) {
                $this->line('  '.end($report->messages));
            }
        }
    }
}
