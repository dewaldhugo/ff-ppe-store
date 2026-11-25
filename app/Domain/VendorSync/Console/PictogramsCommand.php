<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Images\PictogramMirror;
use Illuminate\Console\Command;

final class PictogramsCommand extends Command
{
    protected $signature = 'vendorsync:pictograms
        {vendor=SJ : Vendor code (SJ or FS)}';

    protected $description = 'Download the pictogram icons the vendor\'s products list, where the storefront looks for them';

    public function handle(PictogramMirror $mirror): int
    {
        $vendor = strtoupper((string) $this->argument('vendor'));

        if (! app()->bound("vendorsync.mapper.{$vendor}")) {
            $this->error("Unknown vendor '{$vendor}'.");

            return self::FAILURE;
        }

        $report = $mirror->mirror($vendor);

        $this->line("{$vendor}: {$report['wanted']} pictograms listed, {$report['present']} already there, ".count($report['fetched']).' downloaded.');

        foreach ($report['fetched'] as $name) {
            $this->line("  downloaded {$name}.png");
        }

        foreach ($report['failed'] as $name => $reason) {
            $this->error("  {$name}: {$reason}");
        }

        return $report['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
