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
        $summaries = [];

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

                if ($type === 'images' && ($summary = $this->imageSummary($run->stats ?? [])) !== null) {
                    $summaries["{$vendor->code} images (run {$run->id})"] = $summary;
                }

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

        // Only the first few messages are listed below, so the image counts
        // are stated here: a run can succeed and still leave images missing.
        foreach ($summaries as $label => $summary) {
            $this->newLine();
            $this->line("{$label}: {$summary}");
        }

        foreach ($problems as $label => $messages) {
            $this->newLine();
            $this->line($label.':');

            foreach ($messages as $message) {
                $this->line('  '.$message);
            }
        }

        return self::SUCCESS;
    }

    /**
     * One line about the images a run could not save, or null when none failed.
     *
     * @param  array<string, mixed>  $stats
     */
    private function imageSummary(array $stats): ?string
    {
        $partial = (int) ($stats['partial_jobs'] ?? 0);
        $hero = (int) ($stats['hero_failed_jobs'] ?? 0);
        $images = (int) ($stats['images_failed'] ?? 0);
        $jobs = (int) ($stats['failed_jobs'] ?? 0);

        if ($partial + $images + $jobs === 0) {
            return null;
        }

        return sprintf(
            '%d partial, %d with the first image missing (left draft); %d images failed (%d lock timeouts, %d not attempted); %d failed jobs',
            $partial,
            $hero,
            $images,
            (int) ($stats['lock_timeouts'] ?? 0),
            (int) ($stats['not_attempted'] ?? 0),
            $jobs,
        );
    }
}
