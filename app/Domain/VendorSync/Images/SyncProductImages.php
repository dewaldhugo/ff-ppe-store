<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Exceptions\ImageSyncFailed;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Support\Queues;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronises the images of one product. Queued after a catalogue run.
 * Downloads have their own retries, so the job itself is tried once. It runs
 * on the `images` queue, whose Horizon supervisor timeout and the connection's
 * `retry_after` are both above this job's timeout (see config/horizon.php).
 *
 * The job never finishes silently without work. If the product is missing,
 * there is nothing to download, or no image could be saved, it logs an
 * error with the vendor product ID and the reason, records it in the run
 * and throws, so it lands in `failed_jobs`. When some images were saved and
 * others were not, it succeeds with a warning.
 */
final class SyncProductImages implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        // The `vendor_products` row.
        public readonly int $vendorProductId,
        public readonly bool $refresh = false,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue(Queues::IMAGES);
    }

    public function handle(ProductImageSync $sync): void
    {
        $link = VendorProduct::query()->find($this->vendorProductId);

        if ($link === null) {
            $this->fail("row {$this->vendorProductId}", 'the vendor product no longer exists');
        }

        $id = (string) $link->vendor_product_id;

        try {
            $result = $sync->sync($link, $this->refresh);
        } catch (Throwable $exception) {
            $this->fail($id, class_basename($exception).': '.$exception->getMessage(), $exception);
        }

        if ($result->blocked !== null) {
            $this->fail($id, $result->blocked);
        }

        if ($result->stored === 0) {
            $this->fail($id, "none of the {$result->planned} images could be saved. ".implode(' | ', $result->failed));
        }

        $messages = [];

        foreach ($result->notes as $note) {
            $messages[] = "Product {$id}: {$note}";
        }

        if ($result->failed !== []) {
            // A partial success: the product has images, but not all of them.
            $warning = "Product {$id}: ".count($result->failed)." of {$result->planned} images could not be saved: ".implode(' | ', $result->failed);
            $messages[] = $warning;

            Log::warning('VendorSync images: '.$warning);
        }

        $this->record($result->changed, count($result->failed), $messages);
    }

    /**
     * @return never
     */
    private function fail(string $vendorProductId, string $reason, ?Throwable $previous = null): void
    {
        $exception = ImageSyncFailed::for($vendorProductId, $reason);

        Log::error('VendorSync images: '.$exception->getMessage(), ['run_id' => $this->runId]);

        $this->record(false, 1, [$exception->getMessage()], failed: true);

        throw $previous === null ? $exception : new ImageSyncFailed($exception->getMessage(), previous: $previous);
    }

    /**
     * @param  list<string>  $messages
     */
    private function record(bool $changed, int $errors, array $messages, bool $failed = false): void
    {
        if ($this->runId === null) {
            return;
        }

        DB::transaction(function () use ($changed, $errors, $messages, $failed): void {
            $run = SyncRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null) {
                return;
            }

            $cap = (int) config('vendorsync.messages_cap', 200);
            $stats = $run->stats ?? [];
            $stats['failed_jobs'] = ($stats['failed_jobs'] ?? 0) + ($failed ? 1 : 0);

            $run->update([
                'seen' => $run->seen + 1,
                'updated' => $run->updated + ($changed ? 1 : 0),
                'unchanged' => $run->unchanged + ($changed || $failed ? 0 : 1),
                'errors' => $run->errors + $errors,
                'messages' => array_slice([...($run->messages ?? []), ...$messages], 0, $cap),
                'stats' => $stats,
            ]);
        });
    }
}
