<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\VendorSync\Exceptions\RunAlreadyRunning;
use App\Domain\VendorSync\Images\ImageRun;
use Illuminate\Console\Command;
use Throwable;

final class ImagesCommand extends Command
{
    protected $signature = 'vendorsync:images
        {vendor : Vendor code (SJ or FS)}
        {--product= : Only this vendor product ID, as shown in reports (for example 215754)}
        {--refresh : Download every image again and replace changed ones}
        {--category= : Only products in these categories, comma separated contract handles (for example safety-shoes,safety-boots); other products are not considered}
        {--aligned : Only the categories whose main images are put on the common baseline (vendorsync.images.align.collections); same as --category with those}';

    protected $description = 'Queue image downloads for a vendor\'s products';

    public function handle(ImageRun $images): int
    {
        $vendor = strtoupper((string) $this->argument('vendor'));

        if (! app()->bound("vendorsync.mapper.{$vendor}")) {
            $this->error("Unknown vendor '{$vendor}'.");

            return self::FAILURE;
        }

        $product = $this->option('product');
        $product = filled($product) ? trim((string) $product) : null;

        // Validated before anything is queued.
        if ($product !== null && $images->find($vendor, $product) === null) {
            $this->error("{$vendor} has no imported product with vendor product ID '{$product}'. Nothing was queued.");
            $this->line('--product takes the vendor\'s own product ID, as shown in reports (for example OLIVE [273934]), not the Lunar product ID.');

            return self::FAILURE;
        }

        $categories = $this->categories();

        if ($categories === null) {
            return self::FAILURE;
        }

        try {
            $dispatch = $images->run($vendor, $product, (bool) $this->option('refresh'), $categories);
            $queued = $dispatch->queued;
        } catch (RunAlreadyRunning $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Queued {$queued} image ".($queued === 1 ? 'job' : 'jobs')." for {$vendor}".($product === null ? '' : " product {$product}").($categories === [] || $product !== null ? '' : ' in '.implode(', ', $categories)).'.');
        if ($dispatch->skipped > 0) {
            $this->line("{$dispatch->skipped} products with up-to-date images were not queued. Use --refresh to check every image again.");
        }

        $this->line('A job that cannot save any image fails and appears in failed_jobs. Run `php artisan vendorsync:status` for the result.');

        return self::SUCCESS;
    }

    /**
     * The category handles to limit the run to: empty for every category, or null (after an error line) when a handle is unknown.
     *
     * @return list<string>|null
     */
    private function categories(): ?array
    {
        $handles = $this->option('aligned')
            ? array_map(strval(...), (array) config('vendorsync.images.align.collections', []))
            : array_values(array_filter(array_map('trim', explode(',', (string) $this->option('category')))));

        foreach ($handles as $handle) {
            try {
                CatalogueKeys::collection($handle);
            } catch (Throwable) {
                $this->error("There is no category with the handle '{$handle}'. Use the contract handles, for example safety-shoes or safety-boots.");

                return null;
            }
        }

        return array_values(array_unique($handles));
    }
}
