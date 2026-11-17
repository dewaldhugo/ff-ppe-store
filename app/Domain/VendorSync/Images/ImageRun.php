<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Exceptions\RunAlreadyRunning;
use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Support\Queues;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Bus;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Queues the image jobs of a vendor and records the run.
 *
 * A plain run (no `--refresh`, no `--product`) queues a job only for a product
 * whose images are incomplete or changed: its stored image hash is missing or
 * differs from the hash of its current image plan, or it has no stored images.
 * Products whose images are up to date are counted in the run's
 * `skipped_up_to_date` statistic instead of costing a job each. `--refresh`
 * and `--product` queue everything they name. The job still checks for itself,
 * so a change between queuing and running is harmless.
 */
final readonly class ImageRun
{
    public const string TYPE = 'images';

    /** Products read per query when deciding what needs a job. */
    private const int CHUNK = 200;

    public function __construct(
        private RunRecorder $runs,
        private ProductImageSync $sync,
    ) {}

    /**
     * The `vendor_products` row for a vendor product ID (the ID shown in
     * reports, for example 215754), or null when the vendor has no such
     * product.
     */
    public function find(string $vendorCode, string $vendorProductId): ?VendorProduct
    {
        $vendor = Vendor::query()->where('code', strtoupper($vendorCode))->first();

        return $vendor === null ? null : VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->where('vendor_product_id', $vendorProductId)
            ->first();
    }

    /**
     * @param  string|null  $vendorProductId  Limit to one vendor product ID.
     * @return int The number of jobs queued.
     */
    public function dispatch(string $vendorCode, ?string $vendorProductId = null, bool $refresh = false): int
    {
        return $this->run($vendorCode, $vendorProductId, $refresh)->queued;
    }

    /**
     * Like `dispatch()`, with the number of products left out as up to date.
     *
     * @param  string|null  $vendorProductId  Limit to one vendor product ID.
     */
    public function run(string $vendorCode, ?string $vendorProductId = null, bool $refresh = false): ImageDispatch
    {
        $vendor = $this->runs->vendor(strtoupper($vendorCode));

        // The lock covers queuing, not the jobs: the batch outlives this call.
        $lock = $this->runs->lock($vendor, self::TYPE, staleAfterSeconds: $this->runs->lockSeconds());

        if ($lock === null) {
            throw RunAlreadyRunning::for($vendor->code, self::TYPE);
        }

        try {
            return $this->queue($vendor, $vendorProductId, $refresh);
        } finally {
            $lock->release();
        }
    }

    private function queue(Vendor $vendor, ?string $vendorProductId, bool $refresh): ImageDispatch
    {
        $skipped = 0;
        $ids = $refresh || $vendorProductId !== null
            ? VendorProduct::query()
                ->where('vendor_id', $vendor->id)
                ->when($vendorProductId !== null, fn ($query) => $query->where('vendor_product_id', $vendorProductId))
                ->orderBy('id')
                ->pluck('id')
                ->all()
            : $this->needingImages($vendor, $skipped);

        $run = $this->runs->start($vendor, self::TYPE);
        $runId = $run->id;

        if ($skipped > 0) {
            $run->update(['stats' => [...($run->stats ?? []), 'skipped_up_to_date' => $skipped]]);
        }

        if ($ids === []) {
            self::finish($runId);

            return new ImageDispatch(0, $skipped);
        }

        // On the sync driver a failing job would throw out of the batch and
        // stop the jobs after it. Run them one by one instead: each failure
        // is already logged and recorded in the run by the job itself.
        if (config('queue.default') === 'sync') {
            foreach ($ids as $id) {
                try {
                    SyncProductImages::dispatchSync($id, $refresh, $runId);
                } catch (Throwable) {
                    continue;
                }
            }

            self::finish($runId);

            return new ImageDispatch(count($ids), $skipped);
        }

        // A batch pushes its jobs with the queue named on the batch itself and
        // ignores each job's own `onQueue()`, so without this line every job
        // lands on the connection default, away from the images supervisor.
        Bus::batch(array_map(fn (int $id): SyncProductImages => new SyncProductImages($id, $refresh, $runId), $ids))
            ->name("vendorsync images {$vendor->code}")
            ->onQueue(Queues::IMAGES)
            ->allowFailures()
            ->finally(function () use ($runId): void {
                ImageRun::finish($runId);
            })
            ->dispatch();

        return new ImageDispatch(count($ids), $skipped);
    }

    /**
     * The `vendor_products` IDs of the vendor that need an image job, oldest
     * first, and (by reference) how many were left out as up to date.
     *
     * One query for the products and one for which of them have stored images
     * per chunk. The only per-product read is the stored payload that the
     * image plan is mapped from, and only for a product that has a stored hash
     * and images. A product with no hash or no images is queued without that
     * read, and a blocked plan (no mapper, no payload, no images) is queued
     * too, so its job reports the failure as it always has.
     *
     * @return list<int>
     */
    private function needingImages(Vendor $vendor, int &$skipped): array
    {
        $ids = [];
        $morphType = (new Product)->getMorphClass();
        $collection = (string) config('lunar.media.collection');

        VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->chunkById(self::CHUNK, function (EloquentCollection $links) use (&$ids, &$skipped, $vendor, $morphType, $collection): void {
                $withImages = Media::query()
                    ->where('model_type', $morphType)
                    ->where('collection_name', $collection)
                    ->whereIn('model_id', $links->pluck('product_id')->filter()->all())
                    ->distinct()
                    ->pluck('model_id')
                    ->flip();

                foreach ($links as $link) {
                    if ($link->image_urls_hash === null || ! $withImages->has($link->product_id)) {
                        $ids[] = $link->id;

                        continue;
                    }

                    // The vendor is the same for the whole chunk: do not load it per link.
                    $link->setRelation('vendor', $vendor);

                    // A payload the mapper cannot read is queued too: the job
                    // reports it, and one bad record must not stop the run.
                    try {
                        $current = $this->sync->planHash($link);
                    } catch (Throwable) {
                        $current = null;
                    }

                    if ($current !== $link->image_urls_hash) {
                        $ids[] = $link->id;

                        continue;
                    }

                    $skipped++;
                }
            });

        return $ids;
    }

    /**
     * Close the run when its last job has finished. A run in which every
     * job failed has failed, and raises the alert. A run with some failed
     * jobs succeeds, with the failures in its report.
     */
    public static function finish(int $runId): void
    {
        $run = SyncRun::query()->find($runId);

        if ($run === null || $run->status !== 'running') {
            return;
        }

        $failedJobs = (int) (($run->stats ?? [])['failed_jobs'] ?? 0);
        $allFailed = $run->seen > 0 && $failedJobs >= $run->seen;

        $run->update(['status' => $allFailed ? 'failed' : 'succeeded', 'finished_at' => now()]);

        if ($allFailed) {
            app(RunRecorder::class)->alert($run);
        }
    }
}
