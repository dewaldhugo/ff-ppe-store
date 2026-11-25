<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Brands\LogoTrimmer;
use Illuminate\Console\Command;

/**
 * Writes a trimmed copy of every brand logo to public/images/brands/trimmed, for the product page. The originals are
 * never changed. Run it after adding a logo to public/images/brands, and commit the result.
 */
final class TrimBrandLogos extends Command
{
    public const string DIRECTORY = 'images/brands/trimmed';

    protected $signature = 'catalogue:trim-brand-logos';

    protected $description = 'Write trimmed copies of the brand logos (public/images/brands) for the product page';

    public function handle(LogoTrimmer $trimmer): int
    {
        $written = 0;

        foreach (glob(public_path('images/brands/*.png')) ?: [] as $source) {
            $target = public_path(self::DIRECTORY.'/'.basename($source));

            if ($trimmer->trim($source, $target)) {
                $written++;
            } else {
                $this->warn(basename($source).' has nothing visible in it, skipped.');
            }
        }

        $this->info("Wrote {$written} trimmed logos to public/".self::DIRECTORY.'.');

        return self::SUCCESS;
    }
}
