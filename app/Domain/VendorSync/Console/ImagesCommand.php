<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Images\ImageRun;
use Illuminate\Console\Command;

final class ImagesCommand extends Command
{
    protected $signature = 'vendorsync:images
        {vendor : Vendor code (SJ or FS)}
        {--product= : Only this vendor product ID}
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
        $queued = $images->dispatch($vendor, filled($product) ? (string) $product : null, (bool) $this->option('refresh'));

        $this->line("Queued {$queued} image jobs for {$vendor}. Run `php artisan vendorsync:status` to follow them.");

        return self::SUCCESS;
    }
}
