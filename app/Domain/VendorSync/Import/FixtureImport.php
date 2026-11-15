<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Footsure\FootsureSource;
use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\SafetyJogger\FixtureSource;
use RuntimeException;

/**
 * Imports the recorded vendor fixtures through the real pipeline, for local
 * development (VENDORSYNC_SEED_FROM_FIXTURES=true). The Safety Jogger API
 * is not called. Images are downloaded from the vendors' public image
 * hosts so the store shows real pictures.
 */
final readonly class FixtureImport
{
    public function __construct(
        private CatalogueImporter $importer,
        private ProductImageSync $images,
    ) {}

    /**
     * @return list<RunReport>
     */
    public function run(bool $withImages = true): array
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Vendor fixtures may only be imported in the local environment.');
        }

        $reports = [
            $this->importer->run('SJ', source: new FixtureSource((string) config('vendorsync.safety_jogger.fixtures_path'))),
            // The recorded export is old by design: skip the staleness check.
            $this->importer->run('FS', source: new FootsureSource(
                config('vendorsync.footsure'),
                onlyFile: (string) config('vendorsync.footsure.fixture_file'),
                checkAge: false,
            )),
        ];

        if ($withImages) {
            foreach (VendorProduct::query()->orderBy('id')->get() as $link) {
                $this->images->sync($link);
            }
        }

        return $reports;
    }
}
