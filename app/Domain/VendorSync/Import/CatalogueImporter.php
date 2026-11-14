<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\NormalisedProduct;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Lunar\Models\Brand;
use Lunar\Models\Product;
use Throwable;

/**
 * Runs one vendor's catalogue import:
 * source -> raw store -> mapper -> writer -> batch reindex.
 *
 * The same code serves every vendor. Sources and mappers are resolved from
 * the container as `vendorsync.source.{CODE}` and `vendorsync.mapper.{CODE}`.
 */
final class CatalogueImporter
{
    public const string TYPE = 'catalogue';

    public function __construct(
        private readonly Container $container,
        private readonly RunRecorder $runs,
    ) {}

    /**
     * @param  bool  $dryRun  Run everything except the writes: nothing is left in the database, no file is archived.
     * @param  bool  $force  Reprocess products whose payload is unchanged.
     */
    public function run(string $vendorCode, bool $dryRun = false, bool $force = false, ?VendorSource $source = null): RunReport
    {
        $code = strtoupper($vendorCode);
        $source ??= $this->container->make("vendorsync.source.{$code}");
        /** @var VendorMapper $mapper */
        $mapper = $this->container->make("vendorsync.mapper.{$code}");

        return $dryRun
            ? $this->dryRun($code, $source, $mapper, $force)
            : $this->realRun($code, $source, $mapper, $force);
    }

    private function realRun(string $code, VendorSource $source, VendorMapper $mapper, bool $force): RunReport
    {
        $report = new RunReport($code, self::TYPE);
        $vendor = $this->runs->vendor($code);
        $run = $this->runs->start($vendor, self::TYPE);

        try {
            $batch = $source->fetch();
            $this->assertEnoughProducts($vendor, $batch);

            $this->process($vendor, $batch, $mapper, $report, $force, $run);

            $source->completed();
            $report->finish(RunReport::SUCCEEDED);
        } catch (SourceAborted $exception) {
            $report->finish(RunReport::ABORTED, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $report->finish(RunReport::FAILED, class_basename($exception).': '.$exception->getMessage());
        }

        $this->runs->finish($run, $report);

        // One batch reindex for everything the run wrote.
        $this->reindex($report);

        return $report;
    }

    /**
     * Fetch first, then do the writes inside a transaction that is rolled
     * back, so the counts and the sample are exactly what a real run would
     * produce.
     */
    private function dryRun(string $code, VendorSource $source, VendorMapper $mapper, bool $force): RunReport
    {
        $report = new RunReport($code, self::TYPE, dryRun: true);

        try {
            $batch = $source->fetch();
            $batch = new SourceBatch($batch->total, [...$batch->records]);
        } catch (SourceAborted $exception) {
            return $this->saveReport($report->finish(RunReport::ABORTED, $exception->getMessage()));
        }

        DB::beginTransaction();

        try {
            $vendor = $this->runs->vendor($code);
            $this->assertEnoughProducts($vendor, $batch);
            $this->process($vendor, $batch, $mapper, $report, $force, null);
            $report->finish(RunReport::SUCCEEDED);
        } catch (SourceAborted $exception) {
            $report->finish(RunReport::ABORTED, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $report->finish(RunReport::FAILED, class_basename($exception).': '.$exception->getMessage());
        } finally {
            DB::rollBack();
        }

        return $this->saveReport($report);
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

    private function process(Vendor $vendor, SourceBatch $batch, VendorMapper $mapper, RunReport $report, bool $force, ?SyncRun $run): void
    {
        // A fresh writer per run: it memoises option values and settings.
        /** @var CatalogueWriter $writer */
        $writer = $this->container->make(CatalogueWriter::class);

        // Bulk writers must not sync to search row by row (see CLAUDE.md).
        Product::withoutSyncingToSearch(function () use ($vendor, $batch, $mapper, $report, $force, $run, $writer): void {
            Brand::withoutSyncingToSearch(function () use ($vendor, $batch, $mapper, $report, $force, $run, $writer): void {
                $links = VendorProduct::query()->where('vendor_id', $vendor->id)->get()->keyBy('vendor_product_id');
                $present = [];

                foreach ($batch->records as $record) {
                    $report->seen++;

                    $inScope = $this->record($vendor, $record, $mapper, $writer, $links->get($record->externalId), $report, $force, $run);

                    if ($inScope) {
                        $present[$record->externalId] = true;
                    }
                }

                $this->discontinueMissing($links, $present, $writer, $report);

                if ($report->created + $report->updated > 0) {
                    $writer->associate($vendor);
                }
            });
        });
    }

    /**
     * Returns true when the product is still in the feed and in scope, so
     * it must not be treated as discontinued.
     */
    private function record(
        Vendor $vendor,
        RawRecord $record,
        VendorMapper $mapper,
        CatalogueWriter $writer,
        ?VendorProduct $link,
        RunReport $report,
        bool $force,
        ?SyncRun $run,
    ): bool {
        if ($record->error !== null) {
            // Could not be fetched: not proof that the product is gone.
            $report->error("{$vendor->code} product {$record->externalId}: {$record->error}");

            return true;
        }

        if ($record->outOfScope !== null) {
            $this->outOfScope($report, $record->outOfScope);

            return false;
        }

        try {
            $this->storeRaw($vendor, $record, $run);

            $result = $mapper->map($record);
        } catch (Throwable $exception) {
            $report->error("{$vendor->code} product {$record->externalId}: ".class_basename($exception).': '.$exception->getMessage());

            return true;
        }

        foreach ($result->messages as $message) {
            $report->message($message);
        }

        if ($result->outOfScope !== null) {
            $this->outOfScope($report, $result->outOfScope);

            return false;
        }

        if ($result->product === null) {
            $report->error($result->error ?? "{$vendor->code} product {$record->externalId} could not be mapped.");

            return true;
        }

        $hash = $record->hash();
        $wasMissing = in_array(DraftReason::MISSING_FROM_FEED, $link?->draft_reasons ?? [], true);

        if ($link !== null && ! $force && ! $wasMissing && $link->payload_hash === $hash) {
            $report->unchanged++;

            if ($link->missed_runs > 0) {
                $link->update(['missed_runs' => 0]);
            }

            return true;
        }

        try {
            $written = $writer->write($vendor, $result->product, $hash);
        } catch (Throwable $exception) {
            $report->error("{$vendor->code} product {$record->externalId}: ".$exception->getMessage());

            return true;
        }

        $written->created ? $report->created++ : $report->updated++;
        $report->productIds[] = $written->productId;

        if ($written->draftedByRule()) {
            $report->drafted++;
        }

        foreach ($written->messages as $message) {
            $report->message($message);
        }

        $this->describe($report, $result->product, $written);

        return true;
    }

    /**
     * A product absent from two consecutive successful full runs is set to
     * draft and its variants to stock 0. Nothing is ever deleted.
     *
     * @param  Collection<string, VendorProduct>  $links
     * @param  array<string, true>  $present
     */
    private function discontinueMissing($links, array $present, CatalogueWriter $writer, RunReport $report): void
    {
        $limit = (int) config('vendorsync.discontinue_after_missed_runs', 2);

        foreach ($links as $externalId => $link) {
            if (isset($present[(string) $externalId]) || in_array(DraftReason::MISSING_FROM_FEED, $link->draft_reasons ?? [], true)) {
                continue;
            }

            $missed = min(255, $link->missed_runs + 1);
            $link->update(['missed_runs' => $missed]);

            if ($missed >= $limit && $writer->discontinue($link)) {
                $report->drafted++;
                $report->productIds[] = $link->product_id;
                $report->message("{$report->vendorCode} product {$externalId} was absent from {$missed} consecutive runs: drafted, stock set to 0.");
            }
        }
    }

    private function storeRaw(Vendor $vendor, RawRecord $record, ?SyncRun $run): void
    {
        VendorRawRecord::query()->updateOrCreate(
            ['vendor_id' => $vendor->id, 'external_id' => $record->externalId],
            [
                'payload' => $record->payload,
                'payload_hash' => hash('sha256', RawRecord::canonical($record->payload)),
                'fetched_at' => now(),
                'last_seen_run_id' => $run?->id,
            ],
        );
    }

    private function outOfScope(RunReport $report, string $reason): void
    {
        $report->outOfScope++;
        $report->tally('out_of_scope', $reason);
    }

    private function describe(RunReport $report, NormalisedProduct $product, WriteResult $written): void
    {
        foreach ($product->collections as $collection) {
            $report->tally('collection', $collection);
        }

        foreach ($written->draftReasons as $reason) {
            $report->tally('draft_reason', $reason);
        }

        foreach (array_unique(array_map(fn ($variant): string => $variant->taxClass, $product->variants)) as $taxClass) {
            $report->tally('tax_class', $taxClass);
        }

        $prices = array_map(fn ($variant): int => $variant->price, $product->variants);

        $report->sample(sprintf(
            '%s %s %s [%s]: %s, %s, %d variants, %s to %s ex VAT, %s%s',
            $written->created ? 'create' : 'update',
            $product->brand ?? '(no brand)',
            $product->name,
            $product->vendorProductId,
            $written->status.($written->draftReasons === [] ? '' : ' ('.implode(', ', $written->draftReasons).')'),
            $product->collections === [] ? 'no collection' : implode(', ', $product->collections),
            count($product->variants),
            number_format(min($prices) / 100, 2),
            number_format(max($prices) / 100, 2),
            $product->variants[0]->taxClass,
            $product->safetyClass === null ? '' : ', '.$product->safetyClass,
        ));
    }

    private function reindex(RunReport $report): void
    {
        $ids = array_values(array_unique($report->productIds));

        if ($ids === []) {
            return;
        }

        $products = Product::query()->whereIn('id', $ids)->get();

        Brand::query()->whereIn('id', $products->pluck('brand_id')->filter()->unique())->get()->searchable();
        $products->searchable();

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

        $report->message("Dry run report saved to {$path}");

        return $report;
    }
}
