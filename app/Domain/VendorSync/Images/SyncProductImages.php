<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Synchronises the images of one product. Queued after a catalogue run.
 * Downloads have their own retries, so the job itself is tried once.
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
        public readonly int $vendorProductId,
        public readonly bool $refresh = false,
        public readonly ?int $runId = null,
    ) {}

    public function handle(ProductImageSync $sync): void
    {
        $link = VendorProduct::query()->find($this->vendorProductId);

        if ($link === null) {
            return;
        }

        try {
            $result = $sync->sync($link, $this->refresh);
            $messages = array_map(
                fn (string $url): string => "Product {$link->vendor_product_id}: image could not be downloaded: {$url}",
                $result['failed'],
            );
        } catch (Throwable $exception) {
            $result = ['changed' => false, 'failed' => []];
            $messages = ["Product {$link->vendor_product_id}: ".class_basename($exception).': '.$exception->getMessage()];
        }

        $this->record($result['changed'], $messages);
    }

    /**
     * @param  list<string>  $messages
     */
    private function record(bool $changed, array $messages): void
    {
        if ($this->runId === null) {
            return;
        }

        DB::transaction(function () use ($changed, $messages): void {
            $run = SyncRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null) {
                return;
            }

            $cap = (int) config('vendorsync.messages_cap', 200);

            $run->update([
                'seen' => $run->seen + 1,
                'updated' => $run->updated + ($changed ? 1 : 0),
                'unchanged' => $run->unchanged + ($changed ? 0 : 1),
                'errors' => $run->errors + count($messages),
                'messages' => array_slice([...($run->messages ?? []), ...$messages], 0, $cap),
            ]);
        });
    }
}
