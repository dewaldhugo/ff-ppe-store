<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Queues one image job per product of a vendor and records the run.
 */
final readonly class ImageRun
{
    public const string TYPE = 'images';

    public function __construct(private RunRecorder $runs) {}

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
        $vendor = $this->runs->vendor(strtoupper($vendorCode));

        $ids = VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->when($vendorProductId !== null, fn ($query) => $query->where('vendor_product_id', $vendorProductId))
            ->orderBy('id')
            ->pluck('id');

        $run = $this->runs->start($vendor, self::TYPE);
        $runId = $run->id;

        if ($ids->isEmpty()) {
            self::finish($runId);

            return 0;
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

            return $ids->count();
        }

        Bus::batch($ids->map(fn (int $id): SyncProductImages => new SyncProductImages($id, $refresh, $runId))->all())
            ->name("vendorsync images {$vendor->code}")
            ->allowFailures()
            ->finally(function () use ($runId): void {
                ImageRun::finish($runId);
            })
            ->dispatch();

        return $ids->count();
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
