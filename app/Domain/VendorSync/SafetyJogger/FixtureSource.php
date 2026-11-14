<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use Illuminate\Support\Facades\File;

/**
 * Reads the recorded product detail responses in a folder instead of
 * calling the API. Local seeding only (VENDORSYNC_SEED_FROM_FIXTURES).
 */
final readonly class FixtureSource implements VendorSource
{
    public function __construct(private string $directory) {}

    public function fetch(): SourceBatch
    {
        $records = [];

        foreach (File::glob($this->directory.DIRECTORY_SEPARATOR.'product-[0-9]*.json') as $file) {
            $detail = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $records[] = new RawRecord((string) $detail['id'], $detail, SafetyJoggerSource::hashable($detail));
        }

        if ($records === []) {
            throw SourceAborted::because("No Safety Jogger product fixtures were found in {$this->directory}.");
        }

        return new SourceBatch(count($records), $records);
    }

    public function completed(): void {}
}
