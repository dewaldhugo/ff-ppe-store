<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Exceptions\RunAlreadyRunning;
use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Import\AcceptedPrices;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunReport;
use Illuminate\Console\Command;

final class CatalogueCommand extends Command
{
    protected $signature = 'vendorsync:catalogue
        {vendor : Vendor code (SJ or FS)}
        {--dry-run : Run everything except the writes and print the counts and a sample}
        {--force : Reprocess products whose payload is unchanged}
        {--without-images : Do not queue image jobs after the run}
        {--accept-price-changes= : Apply held price changes for these vendor SKUs (comma separated). Without a value it needs --all}
        {--accept-product= : Apply held price changes for these vendor product IDs (comma separated), as printed in brackets in the report}
        {--all : With a bare --accept-price-changes, apply every held price change}';

    protected $description = 'Import a vendor catalogue into Lunar and the vendor tables';

    public function handle(CatalogueImporter $importer, ImageRun $images): int
    {
        $vendor = strtoupper((string) $this->argument('vendor'));

        if (! app()->bound("vendorsync.source.{$vendor}")) {
            $this->error("Unknown vendor '{$vendor}'.");

            return self::FAILURE;
        }

        $accepted = $this->accepted();

        if ($accepted === null) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $report = $importer->run($vendor, $dryRun, (bool) $this->option('force'), accepted: $accepted);

        $this->render($report);

        if (! $dryRun && $report->ok()) {
            $this->queueImages($images, $vendor);
        }

        $this->footer($started, $report);

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Accepting is always explicit: named SKUs, named product IDs, or
     * `--accept-price-changes --all`. Anything else is refused before the
     * run starts. Null means the options were refused.
     */
    private function accepted(): ?AcceptedPrices
    {
        $flag = $this->input->hasParameterOption('--accept-price-changes');
        $skus = $this->list($this->option('accept-price-changes'));
        $products = $this->list($this->option('accept-product'));
        $all = (bool) $this->option('all');

        if ($this->input->hasParameterOption('--accept-product') && $products === []) {
            $this->error('--accept-product needs a vendor product ID, for example --accept-product=273934.');

            return null;
        }

        if ($flag && $skus === [] && ! $all) {
            $this->error('A bare --accept-price-changes is refused. Name the SKUs (--accept-price-changes=SKU1,SKU2), name products (--accept-product=ID), or pass --all to accept every held change.');

            return null;
        }

        if ($all && (! $flag || $skus !== [] || $products !== [])) {
            $this->error('--all only works with a bare --accept-price-changes, and not together with named SKUs or products.');

            return null;
        }

        return new AcceptedPrices($skus, $products, $all);
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item): bool => $item !== ''));
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

        if ($report->indexing !== null) {
            $this->line($report->indexing);
        }

        foreach ($report->tallies as $group => $tally) {
            ksort($tally);
            $this->line($group.': '.implode(', ', array_map(fn (int|string $key, int $count): string => "{$key} {$count}", array_keys($tally), $tally)));
        }

        if ($report->held !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%s %d price %s held for review%s. Cost, price and compare price were %s.',
                $report->vendorCode,
                count($report->held),
                count($report->held) === 1 ? 'change' : 'changes',
                $report->dryRun ? ' (dry run: they would be)' : '',
                $report->dryRun ? 'left as they are' : 'kept at their old values',
            ));

            foreach (array_slice($report->held, 0, 30) as $held) {
                $this->line('  '.$held->line($report->vendorCode));
            }

            if (count($report->held) > 30) {
                $this->line('  ... '.(count($report->held) - 30).' more in the full report.');
            }

            $this->line('Accept with --accept-price-changes=SKU1,SKU2 or --accept-product=ID.');
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
