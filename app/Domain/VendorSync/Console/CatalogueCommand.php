<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Exceptions\RunAlreadyRunning;
use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunReport;
use Illuminate\Console\Command;

final class CatalogueCommand extends Command
{
    protected $signature = 'vendorsync:catalogue
        {vendor : Vendor code (SJ or FS)}
        {--dry-run : Run everything except the writes and print the counts and a sample}
        {--force : Reprocess products whose payload is unchanged}
        {--without-images : Do not queue image jobs after the run}';

    protected $description = 'Import a vendor catalogue into Lunar and the vendor tables';

    public function handle(CatalogueImporter $importer, ImageRun $images): int
    {
        $vendor = strtoupper((string) $this->argument('vendor'));

        if (! app()->bound("vendorsync.source.{$vendor}")) {
            $this->error("Unknown vendor '{$vendor}'.");

            return self::FAILURE;
        }

        $started = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $report = $importer->run($vendor, $dryRun, (bool) $this->option('force'));

        $this->render($report);

        if (! $dryRun && $report->ok()) {
            $this->queueImages($images, $vendor);
        }

        $this->footer($started, $report);

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Image jobs follow a real catalogue run unless `--without-images` is
     * passed or VENDORSYNC_QUEUE_IMAGES is false. When they are skipped the
     * command says how to queue them later.
     */
    private function queueImages(ImageRun $images, string $vendor): void
    {
        if ($this->option('without-images') || ! config('vendorsync.queue_images')) {
            $why = $this->option('without-images') ? '--without-images' : 'VENDORSYNC_QUEUE_IMAGES is false';
            $this->line("Image jobs were not queued ({$why}). Queue them when ready: php artisan vendorsync:images {$vendor}");

            return;
        }

        try {
            $queued = $images->dispatch($vendor);
            $this->line("Queued {$queued} image jobs.");
        } catch (RunAlreadyRunning $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Printed at the end of every run, dry runs and failed runs included.
     */
    private function footer(float $started, RunReport $report): void
    {
        $stats = $report->stats;

        if (($stats['api_requests'] ?? 0) > 0) {
            $this->line(sprintf(
                'API: %d requests, %.1fs waiting for responses (average %.0f ms), %.1fs throttle wait. Own processing %.1fs.',
                $stats['api_requests'],
                $stats['api_response_seconds'],
                $stats['api_average_response_ms'],
                $stats['throttle_wait_seconds'],
                $stats['processing_seconds'],
            ));
        } elseif (isset($stats['processing_seconds'])) {
            $this->line(sprintf('No API requests. Own processing %.1fs.', $stats['processing_seconds']));
        }

        $seconds = (int) round(microtime(true) - $started);

        $this->line(sprintf(
            'Peak memory %.1f MB, elapsed %dm %02ds.',
            memory_get_peak_usage(true) / 1048576,
            intdiv($seconds, 60),
            $seconds % 60,
        ));
    }

    private function render(RunReport $report): void
    {
        $this->line(($report->dryRun ? 'DRY RUN, nothing was written. ' : '')."{$report->vendorCode} catalogue: {$report->status}");
        $this->table(array_keys($report->counts()), [array_values($report->counts())]);

        foreach ($report->tallies as $group => $tally) {
            ksort($tally);
            $this->line($group.': '.implode(', ', array_map(fn (int|string $key, int $count): string => "{$key} {$count}", array_keys($tally), $tally)));
        }

        if ($report->reportPath !== null) {
            $this->line('Full report: '.$report->reportPath);
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

            if ($report->messagesDropped > 0) {
                $this->line("  ... {$report->messagesDropped} further entries were counted but not kept.");
            }
        }
    }
}
