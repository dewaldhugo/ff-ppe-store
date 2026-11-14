<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Import\StockSync;
use Illuminate\Console\Command;

final class StockCommand extends Command
{
    protected $signature = 'vendorsync:stock {vendor : Vendor code (SJ)}';

    protected $description = 'Update stock from a vendor stock feed (fast path)';

    public function handle(StockSync $stock): int
    {
        $report = $stock->run((string) $this->argument('vendor'));

        $this->line("{$report->vendorCode} stock: {$report->status}");
        $this->table(['seen', 'updated', 'unchanged', 'reindexed'], [[
            $report->seen, $report->updated, $report->unchanged, count($report->reindexedProductIds),
        ]]);

        foreach (array_slice($report->messages, 0, 10) as $message) {
            $this->line('  '.$message);
        }

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }
}
