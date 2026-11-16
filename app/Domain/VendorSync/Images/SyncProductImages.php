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
 * others were not, it succeeds with a warning that names each failed image
 * and its reason (lock timeouts and images not attempted for lack of time are
 * counted apart), on every run for as long as images are missing. When the
 * first image is the one missing, the product stays draft and the warning
 * starts `HERO IMAGE FAILED`.
 *
 * No download starts unless the time left in the job (its timeout minus
 * `vendorsync.images.job_margin_seconds`) covers the longest one can take, so
 * a hanging host cannot get the job killed before it reports.
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

        // The queue kills this job at `$timeout`. No download starts that
        // could still be running when the margin for saving and reporting
        // begins, so a hanging host costs images, not the whole report.
        $deadline = now()->addSeconds(max(0, $this->timeout - (int) config('vendorsync.images.job_margin_seconds', 60)));

        try {
            $result = $sync->sync($link, $this->refresh, $deadline);
        } catch (Throwable $exception) {
            $this->fail($id, class_basename($exception).': '.$exception->getMessage(), $exception);
        }

        if ($result->blocked !== null) {
            $this->fail($id, $result->blocked);
        }

        if ($result->stored === 0) {
            $this->fail($id, "none of the {$result->planned} images could be saved. ".implode(' | ', $result->failed), result: $result);
        }

        $messages = [];

        foreach ($result->notes as $note) {
            $messages[] = "Product {$id}: {$note}";
        }

        $counts = [];

        if ($result->failed !== []) {
            // Some images were saved and others were not. The job succeeds,
            // but the run says so on every run for as long as it stays true.
            $warning = $this->partialMessage($id, $result);
            $messages[] = $warning;
            $counts = $this->counts($result) + ['partial_jobs' => 1, 'hero_failed_jobs' => $result->heroFailed ? 1 : 0];

            Log::warning('VendorSync images: '.$warning, ['run_id' => $this->runId]);
        }

        $this->record($result->changed, count($result->failed), $messages, counts: $counts);
    }

    /**
     * What a partial result says in the run report: which kind, how many
     * images were saved, and why the others were not.
     */
    private function partialMessage(string $id, ImageSyncResult $result): string
    {
        $lead = $result->heroFailed
            ? "Product {$id}: HERO IMAGE FAILED, left draft (no_image) until it downloads. {$result->stored} of {$result->planned} images saved"
            : "Product {$id}: PARTIAL, {$result->stored} of {$result->planned} images saved";

        return $lead.', '.count($result->failed)." failed ({$result->lockTimeouts} lock timeouts, {$result->notAttempted} not attempted): ".implode(' | ', $result->failed);
    }

    /**
     * Run statistics for the failed images of one product.
     *
     * @return array<string, int>
     */
    private function counts(ImageSyncResult $result): array
    {
        return [
            'images_failed' => count($result->failed),
            'lock_timeouts' => $result->lockTimeouts,
            'not_attempted' => $result->notAttempted,
        ];
    }

    /**
     * @return never
     */
    private function fail(string $vendorProductId, string $reason, ?Throwable $previous = null, ?ImageSyncResult $result = null): void
    {
        $exception = ImageSyncFailed::for($vendorProductId, $reason);

        Log::error('VendorSync images: '.$exception->getMessage(), ['run_id' => $this->runId]);

        $this->record(false, 1, [$exception->getMessage()], failed: true, counts: $result === null ? [] : $this->counts($result));

        throw $previous === null ? $exception : new ImageSyncFailed($exception->getMessage(), previous: $previous);
    }

    /**
     * @param  list<string>  $messages
     * @param  array<string, int>  $counts  Added to the run's statistics.
     */
    private function record(bool $changed, int $errors, array $messages, bool $failed = false, array $counts = []): void
    {
        if ($this->runId === null) {
            return;
        }

        DB::transaction(function () use ($changed, $errors, $messages, $failed, $counts): void {
            $run = SyncRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null) {
                return;
            }

            $cap = (int) config('vendorsync.messages_cap', 200);
            $stats = $run->stats ?? [];
            $stats['failed_jobs'] = ($stats['failed_jobs'] ?? 0) + ($failed ? 1 : 0);

            foreach ($counts as $name => $count) {
                $stats[$name] = ($stats[$name] ?? 0) + $count;
            }

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
