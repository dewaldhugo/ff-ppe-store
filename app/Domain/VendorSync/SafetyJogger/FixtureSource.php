<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use Generator;
use Illuminate\Support\Facades\File;

/**
 * Reads the recorded product detail responses in a folder instead of
 * calling the API. Local seeding only (VENDORSYNC_SEED_FROM_FIXTURES).
 * Streams one file at a time, trimmed exactly like the live source.
 */
final readonly class FixtureSource implements VendorSource
{
    public function __construct(private string $directory) {}

    public function fetch(): SourceBatch
    {
        $files = File::glob($this->directory.DIRECTORY_SEPARATOR.'product-[0-9]*.json');

        if ($files === []) {
            throw SourceAborted::because("No Safety Jogger product fixtures were found in {$this->directory}.");
        }

        return new SourceBatch(count($files), $this->records($files));
    }

    public function completed(): void {}

    /**
     * @param  list<string>  $files
     * @return Generator<int, RawRecord>
     */
    private function records(array $files): Generator
    {
        foreach ($files as $file) {
            $detail = SafetyJoggerSource::trim(
                json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
            );

            yield new RawRecord((string) $detail['id'], $detail, SafetyJoggerSource::hashable($detail));
        }
    }
}
