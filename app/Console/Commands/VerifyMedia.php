<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Read-only: reports published products whose images are missing. Run it
 * after any import. It writes nothing to the database or the disk.
 */
final class VerifyMedia extends Command
{
    /** Conversions every product image must have (data contract, Media). */
    public const array CONVERSIONS = ['thumb', 'medium', 'large'];

    private const int SAMPLE = 20;

    protected $signature = 'catalogue:verify-media {--check-files : Also check that the original and conversion files exist on disk}';

    protected $description = 'Report published products with missing images or missing generated conversions (read-only)';

    public function handle(): int
    {
        $checkFiles = (bool) $this->option('check-files');
        $collection = (string) config('lunar.media.collection', 'images');

        /** @var array<string, list<int>> $problems */
        $problems = [
            'no_original' => [],
            'missing_conversions' => [],
            'missing_original_file' => [],
            'missing_conversion_file' => [],
        ];
        $checked = 0;

        Product::query()
            ->where('status', 'published')
            ->with(['media' => fn ($query) => $query->where('collection_name', $collection)])
            ->chunkById(200, function ($products) use (&$problems, &$checked, $checkFiles): void {
                foreach ($products as $product) {
                    $checked++;
                    $found = [];

                    if ($product->media->isEmpty()) {
                        $found['no_original'] = true;
                    }

                    foreach ($product->media as $media) {
                        if ($this->conversionsMissing($media)) {
                            $found['missing_conversions'] = true;
                        }

                        if ($checkFiles) {
                            $found += $this->missingFiles($media);
                        }
                    }

                    foreach (array_keys($found) as $problem) {
                        $problems[$problem][] = (int) $product->id;
                    }
                }
            });

        $labels = [
            'no_original' => 'published products with no original image',
            'missing_conversions' => 'published products with an image missing a generated conversion ('.implode(', ', self::CONVERSIONS).')',
            'missing_original_file' => 'published products whose original image file is missing on disk',
            'missing_conversion_file' => 'published products whose conversion file is missing on disk',
        ];

        $this->line("Checked {$checked} published products".($checkFiles ? ', including files on disk.' : '. Run with --check-files to check the files on disk too.'));

        $missing = 0;

        foreach ($labels as $key => $label) {
            if (! $checkFiles && str_ends_with($key, '_file')) {
                continue;
            }

            $count = count($problems[$key]);

            if ($count === 0) {
                $this->info("OK: 0 {$label}.");

                continue;
            }

            $missing += $count;
            $this->error("{$count} {$label}. First ".min($count, self::SAMPLE).' product IDs: '.implode(', ', array_slice($problems[$key], 0, self::SAMPLE)));
        }

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function conversionsMissing(Media $media): bool
    {
        $generated = $media->generated_conversions ?? [];

        foreach (self::CONVERSIONS as $conversion) {
            if (empty($generated[$conversion])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function missingFiles(Media $media): array
    {
        $found = [];

        try {
            $original = Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
            $conversionsDisk = Storage::disk($media->conversions_disk ?: $media->disk);

            if (! $original) {
                $found['missing_original_file'] = true;
            }

            foreach (self::CONVERSIONS as $conversion) {
                if (! empty(($media->generated_conversions ?? [])[$conversion])
                    && ! $conversionsDisk->exists($media->getPathRelativeToRoot($conversion))) {
                    $found['missing_conversion_file'] = true;
                }
            }
        } catch (Throwable) {
            $found['missing_original_file'] = true;
        }

        return $found;
    }
}
