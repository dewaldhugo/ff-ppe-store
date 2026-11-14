<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Support\Facades\Bus;

/**
 * Queues one image job per product of a vendor and records the run.
 */
final readonly class ImageRun
{
    public const string TYPE = 'images';

    public function __construct(private RunRecorder $runs) {}

    /**
     * @param  string|null  $product  Limit to one vendor product ID.
     * @return int The number of jobs queued.
     */
    public function dispatch(string $vendorCode, ?string $product = null, bool $refresh = false): int
    {
        $vendor = $this->runs->vendor(strtoupper($vendorCode));

        $ids = VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->when($product !== null, fn ($query) => $query->where('vendor_product_id', $product))
            ->orderBy('id')
            ->pluck('id');

        $run = $this->runs->start($vendor, self::TYPE);
        $runId = $run->id;

        if ($ids->isEmpty()) {
            self::finish($runId);

            return 0;
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

    public static function finish(int $runId): void
    {
        $run = SyncRun::query()->find($runId);

        if ($run === null || $run->status !== 'running') {
            return;
        }

        // Failed downloads are reported; the products keep their other images.
        $run->update(['status' => 'succeeded', 'finished_at' => now()]);
    }
}
