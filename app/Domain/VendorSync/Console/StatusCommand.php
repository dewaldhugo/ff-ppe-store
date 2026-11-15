<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'vendorsync:status';

    protected $description = 'Show the last run per vendor and type, with counts and errors';

    public function handle(): int
    {
        $rows = [];
        $problems = [];

        foreach (Vendor::query()->orderBy('code')->get() as $vendor) {
            foreach (['catalogue', 'stock', 'images'] as $type) {
                $run = SyncRun::query()->where('vendor_id', $vendor->id)->where('type', $type)->latest('id')->first();

                if ($run === null) {
                    $rows[] = [$vendor->code, $type, 'never run', '', '', '', '', '', '', '', ''];

                    continue;
                }

                $rows[] = [
                    $vendor->code,
                    $type,
                    $run->status,
                    $run->started_at?->timezone('Europe/London')->format('d/m/Y H:i'),
                    $run->seen, $run->created, $run->updated, $run->unchanged, $run->drafted, $run->errors, $run->out_of_scope,
                ];

                if ($run->status !== 'succeeded' || $run->errors > 0) {
                    $problems["{$vendor->code} {$type} (run {$run->id})"] = array_slice($run->messages ?? [], 0, 10);
                }
            }
        }

        if ($rows === []) {
            $this->line('No vendor has been synchronised yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Vendor', 'Type', 'Status', 'Started', 'Seen', 'Created', 'Updated', 'Unchanged', 'Drafted', 'Errors', 'Out of scope'],
            $rows,
        );

        foreach ($problems as $label => $messages) {
            $this->newLine();
            $this->line($label.':');

            foreach ($messages as $message) {
                $this->line('  '.$message);
            }
        }

        return self::SUCCESS;
    }
}
