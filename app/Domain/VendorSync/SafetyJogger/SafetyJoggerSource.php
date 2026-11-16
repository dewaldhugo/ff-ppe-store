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
 *
 * Details are streamed: one is fetched, trimmed and yielded at a time, and
 * nothing is kept once the consumer moves on. The product count comes from
 * the list, before any detail is fetched.
 */
final readonly class SafetyJoggerSource implements VendorSource
{
    /** Document types kept in English only. */
    private const array ENGLISH_ONLY_DOCUMENTS = ['DeclarationofConformity', 'PRODUCT_SHEET'];

    private ProductFetcher $fetcher;

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     * @param  ProductFetcher|null  $fetcher  Fetches each detail, asking again when the answer cannot be used.
     */
    public function __construct(
        private ApiClient $client,
        private array $config,
        ?ProductFetcher $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? new ProductFetcher($client, $config);
    }

    public function fetch(): SourceBatch
    {
        try {
            $products = $this->client->products();
        } catch (SafetyJoggerApiException $exception) {
            throw SourceAborted::because($exception->getMessage());
        }

        // Only the id and the brand of each list item are needed. The rest
        // of the list (property ids, price ranges) is released here.
        $items = [];

        foreach ($products as $item) {
            $items[] = [(int) ($item['id'] ?? 0), (string) ($item['brandDescription'] ?? '')];
        }

        $total = count($products);
        unset($products);

        return new SourceBatch($total, $this->records($items));
    }

    public function completed(): void {}

    /**
     * Reduce a product detail to what is stored. See RawStore for the full
     * list of what is trimmed and why.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function trim(array $detail): array
    {
        // Tariff codes: one entry per country (about 230). Only the UK's is used.
        if (isset($detail['taricCodes']) && is_array($detail['taricCodes'])) {
            $detail['taricCodes'] = array_values(array_filter(
                $detail['taricCodes'],
                fn (mixed $entry): bool => is_array($entry) && ($entry['country']['iso2'] ?? null) === 'GB',
            ));

            foreach ($detail['taricCodes'] as $index => $entry) {
                $detail['taricCodes'][$index] = [
                    'country' => ['iso2' => 'GB'],
                    'taricCode' => $entry['taricCode'] ?? null,
                ];
            }
        }

        // Declarations of conformity and product sheets: English only.
        if (isset($detail['documents']) && is_array($detail['documents'])) {
            $detail['documents'] = array_values(array_filter(
                $detail['documents'],
                fn (mixed $document): bool => is_array($document) && (
                    ! in_array($document['type'] ?? null, self::ENGLISH_ONLY_DOCUMENTS, true)
                    || self::isEnglish($document)
                ),
            ));
        }

        return $detail;
    }

    /**
     * The trimmed detail without the fields that are not catalogue data:
     * stock (owned by the 15 minute stock feed, so a product is not
     * "changed" because it sold a pair), the catalogue ranking, and the
     * 360-degree frames (kept in the stored payload, but not imported).
     *
     * @param  array<string, mixed>  $detail  A trimmed detail.
     * @return array<string, mixed>
     */
    public static function hashable(array $detail): array
    {
        foreach ($detail['colors'] ?? [] as $colourIndex => $colour) {
            unset($detail['colors'][$colourIndex]['images']['360']);

            foreach ($colour['skus'] ?? [] as $skuIndex => $sku) {
                unset(
                    $detail['colors'][$colourIndex]['skus'][$skuIndex]['stock'],
                    $detail['colors'][$colourIndex]['skus'][$skuIndex]['stockType'],
                );
            }
        }

        unset($detail['priority']);

        return $detail;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function isEnglish(array $document): bool
    {
        return strcasecmp(trim((string) ($document['label'] ?? '')), 'English') === 0
            || preg_match('/_en\.pdf$/i', (string) ($document['fileName'] ?? '')) === 1
            || preg_match('#/en/[^/]+$#i', (string) ($document['url'] ?? '')) === 1;
    }

    /**
     * @param  list<array{0: int, 1: string}>  $items  Product id and brand.
     * @return Generator<int, RawRecord>
     */
    private function records(array $items): Generator
    {
        foreach ($items as [$id, $brand]) {
            if ($id < 1) {
                continue;
            }

            if ($this->isExcludedBrand($brand)) {
                yield new RawRecord((string) $id, outOfScope: 'excluded_brand');

                continue;
            }

            $fetched = $this->fetcher->fetch($id);

            if ($fetched['detail'] === null) {
                yield new RawRecord((string) $id, error: $fetched['error'], fetch: $fetched['outcome']);

                continue;
            }

            $detail = self::trim($fetched['detail']);
            $record = new RawRecord((string) $id, $detail, self::hashable($detail), fetch: $fetched['outcome']);
            unset($fetched);
            unset($detail);

            yield $record;

            // Released before the next detail is fetched.
            unset($record);
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
