<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\Catalogue\Options\GarmentSizes;
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

    /** Reported for a price that has a value but no currency. */
    public const string BLANK_CURRENCY = PriceCurrency::BLANK;

    private SafetyJoggerScope $scope;

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     * @param  array<array-key, string>  $sizeChart  EU size => UK size.
     */
    public function __construct(
        private array $config,
        private array $sizeChart,
        private Features $features,
        private PriceCalculator $prices,
    ) {
        $this->scope = new SafetyJoggerScope($config);
    }

    /**
     * Article types that need no norm to be in scope, and the Lunar product
     * type their collection leaf does not otherwise decide (an unmapped
     * sub-category still needs a product type to be written at all).
     */
    private const array NON_FOOTWEAR_PRODUCT_TYPE = [
        'workwear' => 'workwear',
        'gloves' => 'gloves',
        'head_protection' => 'head-protection',
        'fall_protection' => 'ppe',
        'overshoes' => 'ppe',
        'accessories' => 'accessories',
    ];

    public function map(RawRecord $record): MappingResult
    {
        $data = $record->payload;
        $vendorName = (string) ($data['name'] ?? '');
        $label = "{$vendorName} [{$record->externalId}]";

        // Scope: an article type on the list. `shoes` additionally needs an
        // EN ISO 20345 or 20347 norm (SafetyJoggerScope); every other listed
        // article type is in scope on its own.
        $articleTypes = $this->names($data['articletype'] ?? []);
        $facts = [];

        foreach ($articleTypes === [] ? ['(none)'] : $articleTypes as $type) {
            $facts[] = ['article_type', $type];
        }
        $articleType = $this->scope->articleType($data);

        if ($articleType === null) {
            return MappingResult::outOfScope('article_type', $facts);
        }

        $brand = (string) ($data['brandDescription'] ?? '');

        if (collect($this->config['excluded_brands'] ?? [])->contains(fn (string $excluded): bool => Str::lower($excluded) === Str::lower($brand))) {
            return MappingResult::outOfScope('excluded_brand');
        }

        if ($articleType !== SafetyJoggerScope::FOOTWEAR_ARTICLE_TYPE) {
            return $this->mapNonFootwear($record, $data, $articleType, $brand, $label, $facts);
        }

        $norms = $this->values($data['norms'] ?? []);
        $family = $this->scope->family($norms);

        if ($family === null) {
            return MappingResult::outOfScope('no_en_iso_norm', $facts);
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
            $messages[] = "{$label}: article type '{$articleType}' with model '".($model ?? 'none')."' ({$family}) is not mapped to a collection (draft reason unmapped_category).";
        }

        // A "Winterbox" bundle (a boot plus socks, gloves and a beanie under
        // one SKU): the vendor's own name starts with PROMO. Drafted for
        // review rather than published as a single boot.
        if (Str::startsWith(Str::upper($vendorName), 'PROMO')) {
            $draftReasons[] = DraftReason::BUNDLE;
            $messages[] = "{$label}: the vendor's name starts with PROMO, which looks like a bundle, not a single product (draft reason bundle).";
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
        $nonGbp = SkuIssues::count($messages, SkuIssues::NON_GBP);
        $messages = SkuIssues::collapse($label, $messages);

        // Any SKU priced in another currency drafts the whole product until
        // the vendor supplies a GBP price. Nothing is converted.
        if ($nonGbp > 0) {
            $draftReasons[] = DraftReason::NON_GBP_PRICE;
        }

        // No variant can be imported at all: nothing to sell at a price.
        if ($variants === [] && ! $noUsableSize) {
            $draftReasons[] = DraftReason::NO_SELLABLE_PRICE;
            $messages[] = "{$label}: nothing can be sold at a price (draft reason no_sellable_price).";
        }

        // Every variant is held at stock 0 because its cost is at or above
        // its RRP. Draft it for review rather than publish it as permanently
        // out of stock. One such variant among others does not draft it.
        if ($variants !== [] && $costAboveRrp === count($variants)) {
            $draftReasons[] = DraftReason::COST_AT_OR_ABOVE_RRP;
            $messages[] = "{$label}: every variant has a cost at or above its RRP; product drafted for data review.";
        }

        return MappingResult::product(new NormalisedProduct(
            vendorCode: self::VENDOR_CODE,
            vendorProductId: $record->externalId,
            label: $label,
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
            pictograms: $this->pictograms($data['icons'] ?? []),
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
     * Workwear, gloves, head protection, fall protection, overshoes and
     * accessories: none of it is sized on the shoe scale, none of it carries
     * an EN ISO 20345/20347 norm, and none of the footwear-only attributes
     * (safety class, toecap, cut, ...) apply.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{0: string, 1: string}>  $facts
     */
    private function mapNonFootwear(RawRecord $record, array $data, string $articleType, string $brand, string $label, array $facts): MappingResult
    {
        $messages = [];
        $draftReasons = [];

        [$collection, $categoryFact] = $this->nonFootwearCollection($data, $articleType);
        $facts[] = ['category', $categoryFact];

        if ($collection === null) {
            $draftReasons[] = DraftReason::UNMAPPED_CATEGORY;
            $messages[] = "{$label}: {$categoryFact} is not mapped to a collection (draft reason unmapped_category).";
        }

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
                $variant = $this->garmentVariant($sku, (string) ($data['name'] ?? ''), $vendorColour, $colour, $hex, $weight, $label, $messages, $facts);

                if ($variant !== null) {
                    $variants[] = $variant;
                    $created++;
                }
            }

            if ($created > 0) {
                array_push($images, ...$this->images($colourData['images'] ?? [], $colour));
            }
        }

        $noUsableSize = $variants === [] && collect($facts)->contains(fn (array $fact): bool => $fact[0] === 'unknown_size');

        if ($noUsableSize) {
            $draftReasons[] = DraftReason::UNKNOWN_SIZE;
        }

        $costAboveRrp = SkuIssues::count($messages, SkuIssues::COST_ABOVE_RRP);
        $nonGbp = SkuIssues::count($messages, SkuIssues::NON_GBP);
        $messages = SkuIssues::collapse($label, $messages);

        if ($nonGbp > 0) {
            $draftReasons[] = DraftReason::NON_GBP_PRICE;
        }

        if ($variants === [] && ! $noUsableSize) {
            $draftReasons[] = DraftReason::NO_SELLABLE_PRICE;
            $messages[] = "{$label}: nothing can be sold at a price (draft reason no_sellable_price).";
        }

        if ($variants !== [] && $costAboveRrp === count($variants)) {
            $draftReasons[] = DraftReason::COST_AT_OR_ABOVE_RRP;
            $messages[] = "{$label}: every variant has a cost at or above its RRP; product drafted for data review.";
        }

        $vendorName = (string) ($data['name'] ?? '');

        return MappingResult::product(new NormalisedProduct(
            vendorCode: self::VENDOR_CODE,
            vendorProductId: $record->externalId,
            label: $label,
            productType: $collection === null ? (self::NON_FOOTWEAR_PRODUCT_TYPE[$articleType] ?? 'accessories') : CategoryTree::productType($collection),
            brand: $brand === '' ? null : $brand,
            name: $this->productName((string) ($data['commercialName'] ?? $vendorName) ?: $vendorName),
            title: $this->first($data['productTitles'] ?? []),
            shortDescription: $this->first($data['shortDescriptions'] ?? []),
            description: $this->first($data['product-long-description'] ?? []),
            keyPoints: $this->keyPoints($data['keyFeatures'] ?? []),
            safetyClass: null,
            features: $features,
            standards: [],
            toecap: null,
            midsole: null,
            outsole: null,
            upper: null,
            lining: null,
            cut: null,
            fastening: null,
            industries: $this->values($data['industries'] ?? []),
            gender: 'Unisex',
            countryOfOrigin: $this->country($data['countryOfOrigin'] ?? null),
            videoUrl: $this->video($data['productVideos'] ?? []),
            certificates: [],
            pictograms: $this->pictograms($data['icons'] ?? []),
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
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: string} Collection handle (null when
     *                                      unmapped) and the fact line that
     *                                      names what was looked up.
     */
    private function nonFootwearCollection(array $data, string $articleType): array
    {
        if ($articleType === 'workwear') {
            $code = $this->names($data['workwear_product_category'] ?? [])[0] ?? null;
            $collection = $code === null ? null : ($this->config['workwear_categories'][$code] ?? null);

            return [$collection, "workwear category '".($code ?? 'none')."' => ".($collection ?? 'UNMAPPED')];
        }

        if ($articleType === 'head_protection') {
            $code = $this->names($data['headprotection_product_subcategory'] ?? [])[0] ?? null;
            $collection = $code === null ? null : ($this->config['head_protection_categories'][$code] ?? null);

            return [$collection, "head protection subcategory '".($code ?? 'none')."' => ".($collection ?? 'UNMAPPED')];
        }

        if ($articleType === 'accessories') {
            $name = Str::upper((string) ($data['name'] ?? ''));
            $collection = match (true) {
                str_contains($name, 'FIT'), str_contains($name, 'FOAM') => 'insoles-laces-shoe-care',
                str_contains($name, 'WATCH'), str_contains($name, 'BEANIE') => 'hats-beanies-scarves',
                default => 'other-accessories',
            };

            // Always resolves: a name-based rule, not a lookup table, because
            // the vendor gives this article type no categorisation field.
            return [$collection, "accessories (by name) => {$collection}"];
        }

        // gloves, fall_protection, overshoes: exactly one destination each.
        $collection = $this->config['simple_categories'][$articleType] ?? null;

        return [$collection, "article type '{$articleType}' => ".($collection ?? 'UNMAPPED')];
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

        // A price in any currency other than GBP is never converted and
        // never stored: the variant is imported unpriced and unsellable.
        // A price with no currency at all is treated the same (fail closed).
        $currency = PriceCurrency::issue($sku);

        if ($currency !== null) {
            $facts[] = ['non_gbp_currency', $currency];
            $messages[] = [SkuIssues::NON_GBP, $skuId, $currency];
            $cost = null;
            $price = ['price' => null, 'compare' => null, 'sellable' => false, 'reason' => null];
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
            nonGbpCurrency: $currency,
        );
    }

    /**
     * A variant for everything that is not sized on the shoe scale: garment
     * sizing, always standard rate, no EU size. Multi-unit SKUs (a pack or a
     * box, `pieces` or `buyPer` not 1) are skipped and reported exactly as a
     * footwear SKU of the same shape is, pending the vendor confirming
     * whether such a price is for one unit or the whole pack.
     *
     * @param  list<string>  $messages
     * @param  list<array{0: string, 1: string}>  $facts
     */
    private function garmentVariant(
        array $sku,
        string $vendorName,
        string $vendorColour,
        string $colour,
        ?string $hex,
        ?float $weight,
        string $label,
        array &$messages,
        array &$facts,
    ): ?NormalisedVariant {
        $skuId = (string) ($sku['id'] ?? '');

        if ($skuId === '') {
            return null;
        }

        if ((int) ($sku['pieces'] ?? 1) !== 1 || (int) ($sku['buyPer'] ?? 1) !== 1) {
            $messages[] = [SkuIssues::NOT_PER_PAIR, $skuId];

            return null;
        }

        $raw = trim((string) ($sku['sizeRange'] ?? ''));
        $size = GarmentSizes::name($raw) ?? GarmentSizes::ONE_SIZE_LABEL;

        $cost = Money::toPence($sku['customerPrice']['value'] ?? null);
        $rrp = Money::toPence($sku['rrpPrice']['value'] ?? null);
        $price = $this->prices->calculate(
            $cost,
            $rrp === null ? null : Money::exVat($rrp, (int) ($this->config['rrp_vat_basis_points'] ?? 2000)),
        );

        if ($price['price'] === null) {
            $messages[] = [SkuIssues::NO_COST, $skuId];

            return null;
        }

        $currency = PriceCurrency::issue($sku);

        if ($currency !== null) {
            $facts[] = ['non_gbp_currency', $currency];
            $messages[] = [SkuIssues::NON_GBP, $skuId, $currency];
            $cost = null;
            $price = ['price' => null, 'compare' => null, 'sellable' => false, 'reason' => null];
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
            shoeSize: $size,
            sizeSort: GarmentSizes::sort($size),
            euSize: null,
            vendorStock: max(0, (int) ($sku['stock'] ?? 0)),
            sellable: $price['sellable'],
            price: $price['price'],
            comparePrice: $price['compare'],
            costPrice: $cost,
            taxClass: 'standard',
            weightKg: $weight,
            lengthMm: null,
            widthMm: null,
            heightMm: null,
            vendorProductName: $vendorName,
            vendorColour: $vendorColour,
            vendorSize: $raw === '' ? null : $raw,
            sizeOptionHandle: 'garment_size',
            nonGbpCurrency: $currency,
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
     * The vendor's pictograms as `name|Label` entries, in the order it lists them (the order its own page shows them in; the
     * `priority` field is not that order). `name` is the icon's file name, which PictogramMirror downloads. A repeat, an entry with
     * no usable name or no label, and the separator itself inside a label are dropped.
     *
     * @return list<string>
     */
    private function pictograms(mixed $icons): array
    {
        $entries = [];

        foreach (is_array($icons) ? $icons : [] as $icon) {
            $name = strtolower(trim((string) ($icon['name'] ?? '')));
            $label = trim(str_replace('|', ' ', (string) ($icon['value'] ?? '')));

            if (preg_match('/^[a-z0-9_-]+$/', $name) === 1 && $label !== '') {
                $entries[$name] ??= "{$name}|{$label}";
            }
        }

        return array_values($entries);
    }

    /**
     * The `value` of each property, blanks removed.
     *
     * @return list<string>
     */
    private function values(mixed $properties): array
    {
        return SafetyJoggerScope::values($properties);
    }

    /**
     * The `name` (code) of each property.
     *
     * @return list<string>
     */
    private function names(mixed $properties, bool $lower = true): array
    {
        return SafetyJoggerScope::names($properties, $lower);
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
