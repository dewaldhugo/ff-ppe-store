<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Images\ImageRun;
use Illuminate\Console\Command;

final class ImagesCommand extends Command
{
    protected $signature = 'vendorsync:images
        {vendor : Vendor code (SJ or FS)}
        {--product= : Only this vendor product ID, as shown in reports (for example 215754)}
        {--refresh : Download every image again and replace changed ones}';

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

        $queued = $images->dispatch($vendor, $product, (bool) $this->option('refresh'));

        $this->line("Queued {$queued} image ".($queued === 1 ? 'job' : 'jobs')." for {$vendor}".($product === null ? '' : " product {$product}").'.');
        $this->line('A job that cannot save any image fails and appears in failed_jobs. Run `php artisan vendorsync:status` for the result.');

        return self::SUCCESS;
    }
}
