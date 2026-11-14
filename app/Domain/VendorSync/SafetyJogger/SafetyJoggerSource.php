<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SafetyJoggerApiException;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use Generator;
use Illuminate\Support\Str;

/**
 * Safety Jogger catalogue: `GET /product`, then `GET /product/{id}` for
 * each product. The list has no article type or norms, so scope can only be
 * decided from the detail. Whole brands on the exclusion list are skipped
 * without fetching their details.
 */
final readonly class SafetyJoggerSource implements VendorSource
{
    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     */
    public function __construct(
        private ApiClient $client,
        private array $config,
    ) {}

    public function fetch(): SourceBatch
    {
        try {
            $products = $this->client->products();
        } catch (SafetyJoggerApiException $exception) {
            throw SourceAborted::because($exception->getMessage());
        }

        return new SourceBatch(count($products), $this->records($products));
    }

    public function completed(): void {}

    /**
     * Stock is owned by the 15 minute stock feed, so it is left out of the
     * hash: a product is not "changed" because it sold a pair.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function hashable(array $detail): array
    {
        foreach ($detail['colors'] ?? [] as $colourIndex => $colour) {
            foreach ($colour['skus'] ?? [] as $skuIndex => $sku) {
                unset(
                    $detail['colors'][$colourIndex]['skus'][$skuIndex]['stock'],
                    $detail['colors'][$colourIndex]['skus'][$skuIndex]['stockType'],
                );
            }
        }

        // The catalogue ranking moves around and is not imported.
        unset($detail['priority']);

        return $detail;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return Generator<int, RawRecord>
     */
    private function records(array $products): Generator
    {
        foreach ($products as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            if ($this->isExcludedBrand((string) ($item['brandDescription'] ?? ''))) {
                yield new RawRecord((string) $id, outOfScope: 'excluded_brand');

                continue;
            }

            try {
                $detail = $this->client->product($id);
            } catch (SafetyJoggerApiException $exception) {
                yield new RawRecord((string) $id, error: $exception->getMessage());

                continue;
            }

            yield new RawRecord((string) $id, $detail, self::hashable($detail));
        }
    }

    private function isExcludedBrand(string $brand): bool
    {
        foreach ($this->config['excluded_brands'] ?? [] as $excluded) {
            if (filled($excluded) && Str::lower($brand) === Str::lower($excluded)) {
                return true;
            }
        }

        return false;
    }
}
