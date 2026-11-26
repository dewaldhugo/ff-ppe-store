<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Console;

use App\Domain\VendorSync\Footsure\MergeGroup;
use App\Domain\VendorSync\Footsure\MergePlan;
use App\Domain\VendorSync\Footsure\MergeResult;
use App\Domain\VendorSync\Footsure\SplitMerger;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MergeSplitsCommand extends Command
{
    protected $signature = 'vendorsync:merge-splits
        {vendor=FS : Vendor code (only FS lists one product line under several IDs)}
        {--dry-run : Report what would be merged and change nothing}
        {--product= : Only the line this vendor product ID belongs to (as shown in reports)}';

    protected $description = 'Merge the products a vendor split into several IDs by size band into one product each';

    public function handle(SplitMerger $merger, RunRecorder $runs): int
    {
        $code = strtoupper((string) $this->argument('vendor'));
        $vendor = Vendor::query()->where('code', $code)->first();

        if ($code !== 'FS' || $vendor === null || ! app()->bound("vendorsync.mapper.{$code}")) {
            $this->error("There is nothing to merge for '{$code}': only Footsure lists one product line under several IDs.");

            return self::FAILURE;
        }

        $only = filled($this->option('product')) ? trim((string) $this->option('product')) : null;
        $dryRun = (bool) $this->option('dry-run');

        // Not while a catalogue run is writing the same products.
        $lock = $dryRun ? null : $runs->lock($vendor, CatalogueImporter::TYPE);

        if (! $dryRun && $lock === null) {
            $this->error("A catalogue run for {$code} is running. Try again when it has finished.");

            return self::FAILURE;
        }

        try {
            $plan = $merger->plan($vendor, $only);

            if ($only !== null && $plan->groups === [] && $plan->alreadyMerged === 0) {
                $this->warn("Vendor product {$only} is not part of a product line the vendor split into several IDs.");
            }

            $this->describe($plan, $dryRun);

            $results = [];

            if (! $dryRun) {
                foreach ($plan->mergeable() as $group) {
                    $result = $merger->merge($vendor, $group);
                    $results[] = $result;
                    $this->line($this->resultLine($result));
                }

                $this->summary($results, $plan);
            }

            $path = $this->save($code, $plan, $results, $dryRun);
            $this->line("Report: {$path}");
        } finally {
            $lock?->release();
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Nothing was changed. Run again without --dry-run to merge, then `vendorsync:catalogue FS` and `vendorsync:images FS`, and check with `catalogue:verify-index`.');
        }

        return self::SUCCESS;
    }

    private function describe(MergePlan $plan, bool $dryRun): void
    {
        $mergeable = $plan->mergeable();
        $skipped = array_filter($plan->groups, fn (MergeGroup $group): bool => $group->skipReason !== null);

        $this->line(sprintf(
            '%s %d product lines of %d products %s one product each: %d products removed. %d lines are already one product.',
            $dryRun ? 'Dry run:' : 'Merging:',
            count($mergeable),
            count($mergeable) + $plan->productsRemoved(),
            $dryRun ? 'would become' : 'become',
            $plan->productsRemoved(),
            $plan->alreadyMerged,
        ));

        $this->newLine();

        foreach ($mergeable as $group) {
            $this->line(sprintf(
                '%s: keep product %d (/products/%s, %s, %d variants), merge in %s -> %d variants',
                $group->label,
                $group->keepProductId,
                $group->keepSlug,
                $group->keepStatus,
                $group->keepVariants,
                implode(', ', array_map(
                    fn (array $other): string => sprintf('%d (%s, %s, %d variants, vendor IDs %s)', $other['product_id'], $other['slug'], $other['status'], $other['variants'], implode('/', $other['vendor_product_ids'])),
                    $group->others,
                )),
                $group->variants(),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'Moved with them: %d order lines and %d cart lines already point at moved variants (they keep pointing at the same variants, nothing in orders is written); %d favourites on removed products.',
            array_sum(array_map(fn (MergeGroup $group): int => $group->orderLines, $mergeable)),
            array_sum(array_map(fn (MergeGroup $group): int => $group->cartLines, $mergeable)),
            array_sum(array_map(fn (MergeGroup $group): int => $group->favourites, $mergeable)),
        ));

        foreach ($skipped as $group) {
            $this->warn("Not merged: {$group->label} [".implode(', ', $group->ids)."]: {$group->skipReason}");
        }

        if ($plan->kept !== []) {
            $this->newLine();
            $this->line(count($plan->kept).' other lines share a brand and name but are left apart (full list in the report):');

            foreach ($plan->keptByReason() as $reason => $count) {
                $this->line("  {$count} x {$reason}");
            }
        }
    }

    private function resultLine(MergeResult $result): string
    {
        $group = $result->group;

        if (! $result->merged) {
            return "NOT MERGED {$group->label} [".implode(', ', $group->ids)."]: {$result->note}";
        }

        return sprintf(
            'Merged %s into product %d: %d variants moved, %d images moved, %d duplicate images dropped, %d favourites, %d redirects%s',
            $group->label,
            $group->keepProductId,
            $result->counts['variants'],
            $result->counts['images'],
            $result->counts['images_dropped'],
            $result->counts['favourites'],
            $result->counts['redirects'],
            $result->note === null ? '' : " (WARNING: {$result->note})",
        );
    }

    /**
     * @param  list<MergeResult>  $results
     */
    private function summary(array $results, MergePlan $plan): void
    {
        $merged = array_filter($results, fn (MergeResult $result): bool => $result->merged);

        $this->newLine();
        $this->line(sprintf(
            '%d of %d lines merged, %d products removed. Now run: php artisan vendorsync:catalogue FS (rewrites each merged product from all its rows), then php artisan vendorsync:images FS (checks its images), then php artisan catalogue:verify-index.',
            count($merged),
            count($plan->mergeable()),
            array_sum(array_map(fn (MergeResult $result): int => count($result->group->others), $merged)),
        ));
    }

    /**
     * @param  list<MergeResult>  $results
     */
    private function save(string $code, MergePlan $plan, array $results, bool $dryRun): string
    {
        $directory = (string) config('vendorsync.reports_path');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.sprintf('%s-merge-splits-%s-%s.json', strtolower($code), $dryRun ? 'dry-run' : 'run', now()->format('Ymd-His'));

        File::put($path, json_encode([
            'dry_run' => $dryRun,
            'products_read' => $plan->products,
            'lines_to_merge' => count($plan->mergeable()),
            'products_removed' => $plan->productsRemoved(),
            'already_merged_lines' => $plan->alreadyMerged,
            'groups' => array_map(fn (MergeGroup $group): array => $group->toArray(), $plan->groups),
            'results' => array_map(fn (MergeResult $result): array => [
                'line' => $result->group->label,
                'merged' => $result->merged,
                'counts' => $result->counts,
                'note' => $result->note,
            ], $results),
            'left_apart' => $plan->kept,
            'left_apart_by_reason' => $plan->keptByReason(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
