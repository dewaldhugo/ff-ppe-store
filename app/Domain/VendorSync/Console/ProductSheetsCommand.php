<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Images\ProductSheetSync;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Console\Command;

final class ProductSheetsCommand extends Command
{
    protected $signature = 'vendorsync:product-sheets
        {vendor=SJ : Vendor code (only Safety Jogger has product sheets)}
        {--product= : Only this vendor product ID, as shown in reports (for example 284096)}
        {--refresh : Download every sheet again and replace the ones whose file changed}';

    protected $description = 'Download the English product sheets (one PDF per colour) of a vendor\'s products and attach them to the products';

    public function handle(ProductSheetSync $sheets): int
    {
        $vendorCode = strtoupper((string) $this->argument('vendor'));
        $vendor = app()->bound("vendorsync.mapper.{$vendorCode}") ? Vendor::query()->where('code', $vendorCode)->first() : null;

        if ($vendor === null) {
            $this->error("Unknown vendor '{$vendorCode}'.");

            return self::FAILURE;
        }

        $only = filled($this->option('product')) ? trim((string) $this->option('product')) : null;

        $query = VendorProduct::query()->where('vendor_id', $vendor->id)->whereNotNull('product_id')->orderBy('id');

        if ($only !== null) {
            $query->where('vendor_product_id', $only);

            if (! $query->exists()) {
                $this->error("{$vendorCode} has no imported product with vendor product ID '{$only}'.");

                return self::FAILURE;
            }
        }

        $totals = ['products' => 0, 'downloaded' => 0, 'kept' => 0, 'removed' => 0];
        $problems = [];

        $query->chunkById(100, function ($links) use ($sheets, &$totals, &$problems): void {
            foreach ($links as $link) {
                $result = $sheets->sync($link, (bool) $this->option('refresh'));

                $totals['products']++;
                $totals['downloaded'] += $result->downloaded;
                $totals['kept'] += $result->kept;
                $totals['removed'] += $result->removed;

                foreach ($result->unmatched as $url) {
                    $problems[] = "{$link->vendor_product_id}: no colour matches the sheet {$url}";
                }

                foreach ($result->failed as $failure) {
                    $problems[] = "{$link->vendor_product_id}: {$failure}";
                }

                if ($result->blocked !== null) {
                    $problems[] = "{$link->vendor_product_id}: {$result->blocked}";
                }

                if ($result->downloaded > 0) {
                    $this->line("  {$link->vendor_product_id}: {$result->downloaded} downloaded");
                }
            }
        });

        $this->line("{$vendorCode}: {$totals['products']} products, {$totals['downloaded']} product sheets downloaded, {$totals['kept']} already stored, {$totals['removed']} removed.");

        foreach ($problems as $problem) {
            $this->warn("  {$problem}");
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
