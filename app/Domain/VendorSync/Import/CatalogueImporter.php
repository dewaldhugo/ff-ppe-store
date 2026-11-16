<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\NormalisedProduct;
use App\Domain\VendorSync\Data\NormalisedVariant;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Support\RequestTimer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Lunar\Models\Brand;
use Lunar\Models\Product;
use Throwable;

/**
 * Runs one vendor's catalogue import:
 * source -> raw store -> mapper -> writer -> batch reindex.
 *
 * Streaming: the source yields one product at a time and each product goes
 * fetch -> trim -> hash -> map -> write (or, in a dry run, record) before
 * the next one is fetched. Nothing accumulates payloads, DTOs or responses
 * across products, so memory stays flat whatever the catalogue size. What
 * does accumulate is small and bounded per product: the vendor's product
 * IDs (for discontinuation), the Lunar product IDs to reindex, and the
 * report's counters.
 *
 * The same code serves every vendor. Sources and mappers are resolved from
 * the container as `vendorsync.source.{CODE}` and `vendorsync.mapper.{CODE}`.
 */
final class CatalogueImporter
{
    public const string TYPE = 'catalogue';

    private const int REINDEX_CHUNK = 100;

    private float $startedAt = 0.0;

    public function __construct(
        private readonly Container $container,
        private readonly RunRecorder $runs,
        private readonly RawStore $rawStore,
        private readonly RequestTimer $timer,
    ) {}

    /**
     * @param  bool  $dryRun  Run everything except the writes: the database is not touched and no file is archived.
     * @param  bool  $force  Reprocess products whose payload is unchanged.
     */
    public function run(string $vendorCode, bool $dryRun = false, bool $force = false, ?VendorSource $source = null): RunReport
    {
        $code = strtoupper($vendorCode);
        $source ??= $this->container->make("vendorsync.source.{$code}");
        /** @var VendorMapper $mapper */
        $mapper = $this->container->make("vendorsync.mapper.{$code}");

        $this->timer->reset();
        $this->startedAt = microtime(true);

        return $dryRun
            ? $this->dryRun($code, $source, $mapper, $force)
            : $this->realRun($code, $source, $mapper, $force);
    }

    /**
     * A run takes the lock for its vendor and type, and releases it when it
     * ends, however it ends. A second run while it is held is refused and
     * leaves no record. Taking the lock also fails any earlier run of this
     * vendor that is still marked running: the lock proves nothing else is.
     */
    private function realRun(string $code, VendorSource $source, VendorMapper $mapper, bool $force): RunReport
    {
        $vendor = $this->runs->vendor($code);
        $lock = $this->runs->lock($vendor, self::TYPE);

        if ($lock === null) {
            return (new RunReport($code, self::TYPE))->finish(
                RunReport::ABORTED,
                "Another catalogue run for {$code} is already running. This run was not started.",
            );
        }

        try {
            return $this->performRealRun($code, $vendor, $source, $mapper, $force);
        } finally {
            $lock->release();
        }
    }

    private function performRealRun(string $code, Vendor $vendor, VendorSource $source, VendorMapper $mapper, bool $force): RunReport
    {
        $report = new RunReport($code, self::TYPE);
        $run = $this->runs->start($vendor, self::TYPE);

        try {
            $batch = $source->fetch();
            // Decided from the product count, before any detail is fetched.
            $this->assertEnoughProducts($vendor, $batch);

            // A fresh writer per run: it memoises option values and settings.
            /** @var CatalogueWriter $writer */
            $writer = $this->container->make(CatalogueWriter::class);

            // Bulk writers must not sync to search row by row (see CLAUDE.md).
            Product::withoutSyncingToSearch(function () use ($vendor, $batch, $mapper, $writer, $report, $force, $run): void {
                Brand::withoutSyncingToSearch(function () use ($vendor, $batch, $mapper, $writer, $report, $force, $run): void {
                    $this->process($vendor, $batch, $mapper, $writer, $report, $force, $run);
                });
            });

            $source->completed();
            $report->finish(RunReport::SUCCEEDED);
        } catch (SourceAborted $exception) {
            $report->finish(RunReport::ABORTED, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $report->finish(RunReport::FAILED, class_basename($exception).': '.$exception->getMessage());
        }

        $this->stats($report);
        $this->runs->finish($run, $report);

        // One batch reindex for everything the run wrote.
        $this->reindex($report);

        return $report;
    }

    /**
     * Everything except the writes. Each product is fetched, mapped and
     * compared with what is in the database, then only counted. The
     * database is read, never written. Rules that only the writer applies
     * (a cross-vendor EAN duplicate) are not evaluated.
     */
    private function dryRun(string $code, VendorSource $source, VendorMapper $mapper, bool $force): RunReport
    {
        $report = new RunReport($code, self::TYPE, dryRun: true);

        try {
            $vendor = Vendor::query()->where('code', $code)->first();
            $batch = $source->fetch();

            if ($vendor !== null) {
                $this->assertEnoughProducts($vendor, $batch);
            }

            $this->process($vendor, $batch, $mapper, null, $report, $force, null);
            $report->finish(RunReport::SUCCEEDED);
        } catch (SourceAborted $exception) {
            $report->finish(RunReport::ABORTED, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $report->finish(RunReport::FAILED, class_basename($exception).': '.$exception->getMessage());
        }

        $this->stats($report);

        return $this->saveReport($report);
    }

    /**
     * Where the run's time went: waiting for the vendor's API, waiting on
     * our own throttle, and our own processing (mapping and database).
     */
    private function stats(RunReport $report): void
    {
        $api = $this->timer->stats();
        $elapsed = microtime(true) - $this->startedAt;

        $report->stats = [
            'api_requests' => $api['requests'],
            'api_response_seconds' => $api['response_seconds'],
            'api_average_response_ms' => $api['average_response_ms'],
            'throttle_wait_seconds' => $api['wait_seconds'],
            'processing_seconds' => round(max(0.0, $elapsed - $api['response_seconds'] - $api['wait_seconds']), 3),
            'elapsed_seconds' => round($elapsed, 3),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * Safety threshold: a feed with far fewer products than last time is
     * more likely broken than true. Abort before writing anything.
     */
    private function assertEnoughProducts(Vendor $vendor, SourceBatch $batch): void
    {
        $previous = $this->runs->lastSuccessful($vendor, self::TYPE);

        if ($previous === null || $previous->seen === 0) {
            return;
        }

        $threshold = (int) config('vendorsync.safety_threshold_percent', 70);

        if ($batch->total * 100 < $previous->seen * $threshold) {
            throw SourceAborted::because(
                "Safety threshold: the feed has {$batch->total} products, fewer than {$threshold}% of the {$previous->seen} seen by the previous successful run. Nothing was written.",
            );
        }
    }

    /**
     * @param  CatalogueWriter|null  $writer  Null in a dry run.
     */
    private function process(?Vendor $vendor, SourceBatch $batch, VendorMapper $mapper, ?CatalogueWriter $writer, RunReport $report, bool $force, ?SyncRun $run): void
    {
        // Vendor product IDs still in the feed and in scope. Strings only.
        $present = [];

        foreach ($batch->records as $record) {
            $report->seen++;

            if ($this->record($vendor, $record, $mapper, $writer, $report, $force, $run)) {
                $present[$record->externalId] = true;
            }

            // The payload and everything mapped from it is released here,
            // before the source fetches the next product.
            unset($record);
        }

        if ($vendor === null) {
            return;
        }

        $this->discontinueMissing($vendor, $present, $writer, $report);

        if ($writer !== null && $report->created + $report->updated > 0) {
            $writer->associate($vendor);
        }
    }

    /**
     * Returns true when the product is still in the feed and in scope, so
     * it must not be treated as discontinued.
     */
    private function record(
        ?Vendor $vendor,
        RawRecord $record,
        VendorMapper $mapper,
        ?CatalogueWriter $writer,
        RunReport $report,
        bool $force,
        ?SyncRun $run,
    ): bool {
        $code = $report->vendorCode;

        if ($record->error !== null) {
            // Could not be fetched: not proof that the product is gone.
            $report->error("{$code} product {$record->externalId}: {$record->error}");

            return true;
        }

        if ($record->outOfScope !== null) {
            $this->outOfScope($report, $record->outOfScope);

            return false;
        }

        try {
            if ($writer !== null && $vendor !== null) {
                $this->rawStore->put($vendor, $record, $run?->id);
            }

            $result = $mapper->map($record);
        } catch (Throwable $exception) {
            $report->error("{$code} product {$record->externalId}: ".class_basename($exception).': '.$exception->getMessage());

            return true;
        }

        foreach ($result->messages as $message) {
            $report->message($message);
        }

        foreach ($result->facts as [$group, $value]) {
            $report->tally($group, $value);
        }

        if ($result->outOfScope !== null) {
            $this->outOfScope($report, $result->outOfScope);

            return false;
        }

        if ($result->product === null) {
            $report->error($result->error ?? "{$code} product {$record->externalId} could not be mapped.");

            return true;
        }

        // Counted for every product seen, changed or not, so each run's
        // report shows how many variants still wait for a GBP price.
        if (in_array(DraftReason::NON_GBP_PRICE, $result->product->draftReasons, true)) {
            $report->nonGbpProducts++;
            $report->nonGbpVariants += count(array_filter($result->product->variants, fn (NormalisedVariant $variant): bool => $variant->price === null));
        }

        $hash = $record->hash();

        // One small row per product, looked up by ID. No list of links is held.
        $link = $vendor === null ? null : VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->where('vendor_product_id', $record->externalId)
            ->first(['id', 'product_id', 'payload_hash', 'missed_runs', 'draft_reasons']);

        // No importable variant remains. A product that already exists is
        // drafted by the writer; one that does not exist cannot be created
        // in contract shape without a variant, so it is reported instead.
        if ($result->product->variants === [] && $link === null) {
            $report->error("{$result->product->name} [{$record->externalId}]: no importable variant (".implode(', ', $result->product->draftReasons).'); product not created.');

            return true;
        }

        $wasMissing = in_array(DraftReason::MISSING_FROM_FEED, $link?->draft_reasons ?? [], true);

        if ($link !== null && ! $force && ! $wasMissing && $link->payload_hash === $hash) {
            $report->unchanged++;

            if ($writer !== null && $link->missed_runs > 0) {
                $link->update(['missed_runs' => 0]);
            }

            return true;
        }

        if ($writer === null || $vendor === null) {
            // Dry run: record what a real run would do.
            $reasons = [...$result->product->draftReasons, ...($link === null ? [DraftReason::NO_IMAGE] : [])];
            $this->count($report, $result->product, $link === null, $reasons === [] ? 'published' : 'draft', $reasons);

            return true;
        }

        try {
            $written = $writer->write($vendor, $result->product, $hash);
        } catch (Throwable $exception) {
            $report->error("{$code} product {$record->externalId}: ".$exception->getMessage());

            return true;
        }

        $report->productIds[] = $written->productId;

        foreach ($written->messages as $message) {
            $report->message($message);
        }

        $this->count($report, $result->product, $written->created, $written->status, $written->draftReasons);

        return true;
    }

    /**
     * A product absent from two consecutive successful full runs is set to
     * draft and its variants to stock 0. Nothing is ever deleted.
     *
     * Compared by ID against `vendor_products`, read in chunks.
     *
     * @param  array<string, true>  $present
     */
    private function discontinueMissing(Vendor $vendor, array $present, ?CatalogueWriter $writer, RunReport $report): void
    {
        $limit = (int) config('vendorsync.discontinue_after_missed_runs', 2);

        VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->select(['id', 'vendor_id', 'product_id', 'vendor_product_id', 'missed_runs', 'draft_reasons'])
            ->chunkById(500, function (Collection $links) use ($present, $writer, $report, $limit): void {
                foreach ($links as $link) {
                    if (isset($present[(string) $link->vendor_product_id])
                        || in_array(DraftReason::MISSING_FROM_FEED, $link->draft_reasons ?? [], true)) {
                        continue;
                    }

                    $missed = min(255, $link->missed_runs + 1);

                    if ($writer === null) {
                        if ($missed >= $limit) {
                            $report->drafted++;
                            $report->message("{$report->vendorCode} product {$link->vendor_product_id} would be drafted: absent from {$missed} consecutive runs.");
                        }

                        continue;
                    }

                    $link->update(['missed_runs' => $missed]);

                    if ($missed >= $limit && $writer->discontinue($link)) {
                        $report->drafted++;
                        $report->productIds[] = $link->product_id;
                        $report->message("{$report->vendorCode} product {$link->vendor_product_id} was absent from {$missed} consecutive runs: drafted, stock set to 0.");
                    }
                }
            });
    }

    private function outOfScope(RunReport $report, string $reason): void
    {
        $report->outOfScope++;
        $report->tally('out_of_scope', $reason);
    }

    /**
     * Counts, distinct values and (for the first twenty) one sample line.
     * Nothing of the product itself is kept.
     *
     * @param  list<string>  $draftReasons
     */
    private function count(RunReport $report, NormalisedProduct $product, bool $created, string $status, array $draftReasons): void
    {
        $created ? $report->created++ : $report->updated++;

        if (array_diff($draftReasons, [DraftReason::NO_IMAGE]) !== []) {
            $report->drafted++;
        }

        foreach ($product->collections as $collection) {
            $report->tally('collection', $collection);
        }

        foreach ($draftReasons as $reason) {
            $report->tally('draft_reason', $reason);
        }

        foreach (array_unique(array_map(fn (NormalisedVariant $variant): string => $variant->taxClass, $product->variants)) as $taxClass) {
            $report->tally('tax_class', $taxClass);
        }

        $report->tally('brand', $product->brand ?? '(no brand)');

        if (count($report->samples) >= RunReport::SAMPLE_LIMIT || $product->variants === []) {
            return;
        }

        $prices = array_values(array_filter(array_map(fn (NormalisedVariant $variant): ?int => $variant->price, $product->variants), fn (?int $price): bool => $price !== null));
        $range = $prices === [] ? 'unpriced' : sprintf('%s to %s ex VAT', number_format(min($prices) / 100, 2), number_format(max($prices) / 100, 2));

        $report->sample(sprintf(
            '%s %s %s [%s]: %s, %s, %d variants, %s, %s%s',
            $created ? 'create' : 'update',
            $product->brand ?? '(no brand)',
            $product->name,
            $product->vendorProductId,
            $status.($draftReasons === [] ? '' : ' ('.implode(', ', $draftReasons).')'),
            $product->collections === [] ? 'no collection' : implode(', ', $product->collections),
            count($product->variants),
            $range,
            $product->variants[0]->taxClass,
            $product->safetyClass === null ? '' : ', '.$product->safetyClass,
        ));
    }

    /**
     * The run collected product IDs only. They are loaded and reindexed in
     * small chunks.
     */
    private function reindex(RunReport $report): void
    {
        $ids = array_values(array_unique($report->productIds));

        foreach (array_chunk($ids, self::REINDEX_CHUNK) as $chunk) {
            $products = Product::query()->whereIn('id', $chunk)->get();

            Brand::query()->whereIn('id', $products->pluck('brand_id')->filter()->unique())->get()->searchable();
            $products->searchable();
        }

        $report->reindexedProductIds = $ids;
    }

    /**
     * A dry run leaves a full report on disk (a file, not a database
     * write). Credentials never appear in it.
     */
    private function saveReport(RunReport $report): RunReport
    {
        $directory = (string) config('vendorsync.reports_path');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.sprintf('%s-catalogue-dry-run-%s.json', strtolower($report->vendorCode), now()->format('Ymd-His'));

        File::put($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $report->reportPath = $path;

        return $report;
    }
}
