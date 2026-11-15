<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\Catalogue\Options\ShoeSizes;
use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Data\MappingResult;
use App\Domain\VendorSync\Data\NormalisedImage;
use App\Domain\VendorSync\Data\NormalisedProduct;
use App\Domain\VendorSync\Data\NormalisedVariant;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Normalisers\Features;
use App\Domain\VendorSync\Normalisers\Money;
use App\Domain\VendorSync\Normalisers\PriceCalculator;
use App\Domain\VendorSync\Normalisers\SafetyClass;
use App\Domain\VendorSync\Normalisers\Sizes;
use App\Domain\VendorSync\Support\SkuIssues;
use Illuminate\Support\Str;

/**
 * Maps a Safety Jogger `GET /product/{id}` response to a normalised
 * product.
 */
final readonly class SafetyJoggerMapper implements VendorMapper
{
    public const string VENDOR_CODE = 'SJ';

    private const string SAFETY_NORM = 'EN ISO 20345';

    private const string OCCUPATIONAL_NORM = 'EN ISO 20347';

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     * @param  array<array-key, string>  $sizeChart  EU size => UK size.
     */
    public function __construct(
        private array $config,
        private array $sizeChart,
        private Features $features,
        private PriceCalculator $prices,
    ) {}

    public function map(RawRecord $record): MappingResult
    {
        $data = $record->payload;
        $vendorName = (string) ($data['name'] ?? '');
        $label = "{$vendorName} [{$record->externalId}]";

        // Scope: an article type on the list AND an EN ISO 20345 or 20347 norm.
        $articleTypes = $this->names($data['articletype'] ?? []);
        $facts = [];

        foreach ($articleTypes === [] ? ['(none)'] : $articleTypes as $type) {
            $facts[] = ['article_type', $type];
        }
        $articleType = collect($articleTypes)->first(fn (string $type): bool => in_array($type, $this->config['article_types'] ?? [], true));

        if ($articleType === null) {
            return MappingResult::outOfScope('article_type', $facts);
        }

        $norms = $this->values($data['norms'] ?? []);
        $family = $this->normFamily($norms);

        if ($family === null) {
            return MappingResult::outOfScope('no_en_iso_norm', $facts);
        }

        $brand = (string) ($data['brandDescription'] ?? '');

        if (collect($this->config['excluded_brands'] ?? [])->contains(fn (string $excluded): bool => Str::lower($excluded) === Str::lower($brand))) {
            return MappingResult::outOfScope('excluded_brand');
        }

        $messages = [];
        $draftReasons = [];

        $model = $this->names($data['model'] ?? [])[0] ?? null;
        $categories = $this->config['categories'][$family][$articleType] ?? [];
        $collection = $categories[$model ?? ''] ?? $categories['*'] ?? null;

        // Every distinct article type, model and norm family, with its collection.
        $facts[] = ['category', $articleType.' / '.($model ?? '(none)').' / '.$family.' => '.($collection ?? 'UNMAPPED')];

        if ($collection === null) {
            $draftReasons[] = DraftReason::UNMAPPED_CATEGORY;
            $messages[] = "{$label}: article type '{$articleType}' with model '".($model ?? 'none')."' ({$family}) is not mapped to a collection; product drafted.";
        }

        $cut = $model === null ? null : ($this->config['cut_map'][$model] ?? null);

        if ($model !== null && $cut === null) {
            $messages[] = "{$label}: model '{$model}' has no cut mapping.";
        }

        // Boots are zero rated only when they are safety footwear.
        $taxClass = $family === 'safety' && in_array($model, $this->config['boot_models'] ?? [], true)
            ? 'zero-protective'
            : 'standard';

        $codes = $this->values($data['features'] ?? []);

        foreach ($this->names($data['benefits'] ?? [], lower: false) as $benefit) {
            if (isset($this->config['benefit_map'][$benefit])) {
                $codes[] = $this->config['benefit_map'][$benefit];
            }
        }

        [$features, $unknownFeatures] = $this->features->normalise($codes);

        foreach ($unknownFeatures as $code) {
            $facts[] = ['unknown_feature', $code];
        }

        if ($unknownFeatures !== []) {
            $messages[] = "{$label}: unknown feature codes dropped: ".implode(', ', $unknownFeatures).'.';
        }

        $weight = $this->weight($data['sample-weight'] ?? []);
        $variants = [];
        $images = [];
        $related = [];

        foreach ($data['colors'] ?? [] as $colourData) {
            $vendorColour = trim((string) ($colourData['description'] ?? ''));
            $colour = $vendorColour === '' ? null : Str::title(Str::lower($vendorColour));

            if ($colour === null) {
                $messages[] = "{$label}: a colour has no description; its SKUs were skipped.";

                continue;
            }

            foreach ($colourData['alternatives'] ?? [] as $alternative) {
                if (filled($alternative['productId'] ?? null)) {
                    $related[(string) $alternative['productId']] = true;
                }
            }

            $hex = $this->hex($colourData['hexValue'] ?? null);
            $created = 0;

            foreach ($colourData['skus'] ?? [] as $sku) {
                $variant = $this->variant($sku, $vendorName, $vendorColour, $colour, $hex, $taxClass, $weight, $label, $messages, $draftReasons, $facts);

                if ($variant !== null) {
                    $variants[] = $variant;
                    $created++;
                }
            }

            if ($created > 0) {
                array_push($images, ...$this->images($colourData['images'] ?? [], $colour));
            }
        }

        // A product is drafted for its sizes only when no valid variant
        // remains. Otherwise the unusable SKUs are simply left out.
        $noUsableSize = $variants === [] && collect($facts)->contains(fn (array $fact): bool => $fact[0] === 'unknown_size');

        if ($noUsableSize) {
            $draftReasons[] = DraftReason::UNKNOWN_SIZE;
        }

        // One report line per product and reason, not one per SKU.
        $costAboveRrp = SkuIssues::count($messages, SkuIssues::COST_ABOVE_RRP);
        $messages = SkuIssues::collapse($label, $messages);

        // Nothing to sell at a price: either no variant can be imported at
        // all, or every one is held at stock 0 because its cost is at or
        // above its RRP. Draft it rather than publish it as permanently out
        // of stock.
        $noSellablePrice = ($variants === [] && ! $noUsableSize)
            || ($variants !== [] && $costAboveRrp === count($variants));

        if ($noSellablePrice) {
            $draftReasons[] = DraftReason::NO_SELLABLE_PRICE;
            $messages[] = "{$label}: nothing can be sold at a price; product drafted.";
        }

        return MappingResult::product(new NormalisedProduct(
            vendorCode: self::VENDOR_CODE,
            vendorProductId: $record->externalId,
            productType: 'safety-footwear',
            brand: $brand === '' ? null : $brand,
            name: $this->productName((string) ($data['commercialName'] ?? $vendorName) ?: $vendorName),
            title: $this->first($data['productTitles'] ?? []),
            shortDescription: $this->first($data['shortDescriptions'] ?? []),
            description: $this->first($data['product-long-description'] ?? []),
            keyPoints: $this->keyPoints($data['keyFeatures'] ?? []),
            safetyClass: SafetyClass::normalise($this->first($data['standards'] ?? [])),
            features: $features,
            // Stored verbatim, including amendments such as "+A1:2024".
            standards: $norms,
            toecap: $this->joined($data['toecap'] ?? []),
            midsole: $this->joined($data['midsole'] ?? []),
            outsole: $this->joined($data['outsole'] ?? []),
            upper: $this->joined($data['upper'] ?? []),
            lining: $this->joined($data['lining'] ?? []),
            cut: $cut,
            fastening: null,
            industries: $this->values($data['industries'] ?? []),
            // The vendor does not supply a gender.
            gender: 'Unisex',
            countryOfOrigin: $this->country($data['countryOfOrigin'] ?? null),
            videoUrl: $this->video($data['productVideos'] ?? []),
            // Certificate PDFs are not mirrored yet.
            certificates: [],
            collections: $collection === null ? [] : [$collection],
            clearance: false,
            draftReasons: array_values(array_unique($draftReasons)),
            commodityCode: $this->commodityCode($data['taricCodes'] ?? []),
            relatedVendorProductIds: array_map(strval(...), array_keys($related)),
            variants: $variants,
            images: $images,
        ), $messages, $facts);
    }

    /**
     * @param  array<string, mixed>  $sku
     * @param  list<string>  $messages
     * @param  list<string>  $draftReasons
     * @param  list<array{0: string, 1: string}>  $facts
     */
    private function variant(
        array $sku,
        string $vendorName,
        string $vendorColour,
        string $colour,
        ?string $hex,
        string $taxClass,
        ?float $weight,
        string $label,
        array &$messages,
        array &$draftReasons,
        array &$facts,
    ): ?NormalisedVariant {
        // Identity is the SKU id, never the EAN: the real feed has
        // duplicate EANs and SKUs without one.
        $skuId = (string) ($sku['id'] ?? '');

        if ($skuId === '') {
            return null;
        }

        if ((int) ($sku['pieces'] ?? 1) !== 1 || (int) ($sku['buyPer'] ?? 1) !== 1) {
            $messages[] = [SkuIssues::NOT_PER_PAIR, $skuId];

            return null;
        }

        $eu = trim((string) ($sku['sizeRange'] ?? ''));
        $uk = Sizes::fromChart($this->sizeChart, $eu);

        if ($uk === null) {
            $facts[] = ['unknown_size', $eu === '' ? '(blank)' : $eu];
            $messages[] = [SkuIssues::UNKNOWN_SIZE, $skuId, 'EU '.($eu === '' ? '(blank)' : $eu)];

            return null;
        }

        $cost = Money::toPence($sku['customerPrice']['value'] ?? null);
        $rrp = Money::toPence($sku['rrpPrice']['value'] ?? null);
        // RRP is VAT-inclusive at 20% whatever the tax class.
        $price = $this->prices->calculate(
            $cost,
            $rrp === null ? null : Money::exVat($rrp, (int) ($this->config['rrp_vat_basis_points'] ?? 2000)),
        );

        if ($price['price'] === null) {
            $messages[] = [SkuIssues::NO_COST, $skuId];

            return null;
        }

        if ($price['reason'] === PriceCalculator::COST_AT_OR_ABOVE_RRP) {
            $messages[] = [SkuIssues::COST_ABOVE_RRP, $skuId];
        }

        $ean = trim((string) ($sku['eanCode'] ?? ''));

        return new NormalisedVariant(
            vendorSku: $skuId,
            sku: self::VENDOR_CODE.'-'.$skuId,
            ean: $ean === '' ? null : $ean,
            colour: $colour,
            colourHex: $hex,
            shoeSize: $uk,
            sizeSort: ShoeSizes::sort($uk),
            euSize: $eu,
            vendorStock: max(0, (int) ($sku['stock'] ?? 0)),
            sellable: $price['sellable'],
            price: $price['price'],
            comparePrice: $price['compare'],
            costPrice: $cost,
            taxClass: $taxClass,
            weightKg: $weight,
            lengthMm: null,
            widthMm: null,
            heightMm: null,
            vendorProductName: $vendorName,
            vendorColour: $vendorColour,
            vendorSize: $eu,
        );
    }

    /**
     * Per colour: the first `hires` entry is the main image and the rest
     * are details; `outsole` is the outsole. Without `hires`, fall back to
     * `web`, then to `main`. The 360-degree frames are skipped.
     *
     * @return list<NormalisedImage>
     */
    private function images(mixed $images, string $colour): array
    {
        if (! is_array($images)) {
            return [];
        }

        $gallery = $this->urls($images['hires'] ?? []);
        $web = $this->urls($images['web'] ?? []);
        // The `web` list is the same gallery at 1280 pixels: WEB01 pairs
        // with HIRES, WEB02 with HR01, and so on. It is the fallback when a
        // high resolution file is too large to process.
        $fallbacks = $gallery === [] ? [] : $web;

        if ($gallery === []) {
            $gallery = $web;
        }

        if ($gallery === []) {
            $gallery = $this->urls([$images['main'] ?? null]);
        }

        $result = [];

        foreach ($gallery as $index => $url) {
            $result[] = new NormalisedImage($url, $colour, $index === 0 ? 'main' : 'detail', $fallbacks[$index] ?? null);
        }

        foreach ($this->urls([$images['outsole'] ?? null]) as $url) {
            $result[] = new NormalisedImage($url, $colour, 'outsole');
        }

        return $result;
    }

    /**
     * Only real URLs. An unauthenticated response carries timestamps here.
     *
     * @return list<string>
     */
    private function urls(mixed $candidates): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        return array_values(array_filter(
            $candidates,
            fn (mixed $url): bool => is_string($url) && preg_match('#^https?://#i', $url) === 1,
        ));
    }

    /**
     * @param  list<string>  $norms
     */
    private function normFamily(array $norms): ?string
    {
        $contains = fn (string $norm): bool => collect($norms)->contains(fn (string $value): bool => str_contains($value, $norm));

        return match (true) {
            $contains(self::SAFETY_NORM) => 'safety',
            $contains(self::OCCUPATIONAL_NORM) => 'occupational',
            default => null,
        };
    }

    /**
     * The vendor's commercial name exactly as supplied (`LIGERO`,
     * `BESTBOOT2`, `SX310`): trimmed, repeated whitespace collapsed, and no
     * change of case. Names are model codes, so any casing rule gets some
     * of them wrong.
     */
    private function productName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * @return list<string>
     */
    private function keyPoints(mixed $keyFeatures): array
    {
        $points = [];

        foreach (is_array($keyFeatures) ? $keyFeatures : [] as $feature) {
            $point = trim((string) (($feature['title'] ?? '') ?: ($feature['value'] ?? '')));

            if ($point !== '') {
                $points[] = $point;
            }
        }

        return $points;
    }

    /**
     * The `value` of each property, blanks removed.
     *
     * @return list<string>
     */
    private function values(mixed $properties): array
    {
        $values = [];

        foreach (is_array($properties) ? $properties : [] as $property) {
            $value = trim((string) ($property['value'] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The `name` (code) of each property.
     *
     * @return list<string>
     */
    private function names(mixed $properties, bool $lower = true): array
    {
        $names = [];

        foreach (is_array($properties) ? $properties : [] as $property) {
            $name = trim((string) ($property['name'] ?? ''));

            if ($name !== '') {
                $names[] = $lower ? Str::lower($name) : $name;
            }
        }

        return $names;
    }

    private function first(mixed $properties): ?string
    {
        return $this->values($properties)[0] ?? null;
    }

    private function joined(mixed $properties): ?string
    {
        $values = $this->values($properties);

        return $values === [] ? null : implode('/', $values);
    }

    /**
     * `sample-weight` is kilograms with a decimal comma, e.g. "0,439".
     */
    private function weight(mixed $properties): ?float
    {
        $value = str_replace(',', '.', (string) $this->first($properties));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function hex(mixed $hex): ?string
    {
        $hex = strtoupper(ltrim(trim((string) $hex), '#'));

        return preg_match('/^[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    private function country(mixed $country): ?string
    {
        $iso2 = strtoupper(trim((string) (is_array($country) ? ($country['iso2'] ?? '') : '')));

        return preg_match('/^[A-Z]{2}$/', $iso2) === 1 ? $iso2 : null;
    }

    private function video(mixed $videos): ?string
    {
        foreach (is_array($videos) ? $videos : [] as $video) {
            if (($video['language'] ?? null) === 'en' && filled($video['path'] ?? null)) {
                return (string) $video['path'];
            }
        }

        return null;
    }

    /**
     * The UK tariff code.
     */
    private function commodityCode(mixed $taricCodes): ?string
    {
        foreach (is_array($taricCodes) ? $taricCodes : [] as $entry) {
            if (($entry['country']['iso2'] ?? null) === 'GB' && filled($entry['taricCode'] ?? null)) {
                return (string) $entry['taricCode'];
            }
        }

        return null;
    }
}
