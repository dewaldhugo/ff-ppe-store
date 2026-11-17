<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

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
 * Maps the rows of one Footsure ProductID to a normalised product. Each
 * Family Product Reference is a colourway; each row is a colour and size
 * variant.
 */
final readonly class FootsureMapper implements VendorMapper
{
    public const string VENDOR_CODE = 'FS';

    private const string NOT_APPLICABLE = 'N/A';

    private const string NON_SAFETY = 'Non Safety';

    private const string SAFETY_NORM = 'EN ISO 20345';

    /** Either footwear norm: decides whether a product is footwear at all, for the leaf override below. */
    private const string FOOTWEAR_NORM_PATTERN = '/EN ISO 2034[57]/';

    /** A Group Class starting with one of these is a children's line, out of scope regardless of its wording. */
    private const string CHILDREN_GROUP_PATTERN = '/^(Childrens|Boys|Girls)\b/i';

    /** Outside those groups, a product whose own name says it is a children's line is out of scope too. */
    private const string CHILDREN_NAME_PATTERN = '/\b(junior|juniors|jnr|kids?|childrens?)\b/i';

    /** The one non-footwear Group Class whose UK and EU Size columns are real shoe sizes. */
    private const string INSOLES_GROUP_CLASS = 'Insoles';

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.footsure` config.
     */
    public function __construct(
        private array $config,
        private Features $features,
        private PriceCalculator $prices,
    ) {}

    public function map(RawRecord $record): MappingResult
    {
        /** @var list<array<string, string>> $rows */
        $rows = $record->payload['rows'] ?? [];

        if ($rows === []) {
            return MappingResult::error("Footsure product {$record->externalId} has no rows.");
        }

        [$rows, $damaged] = $this->repairedRows($rows);
        $first = $rows[0];

        if ($this->isExcludedBrand($first['Brand'])) {
            return MappingResult::outOfScope('excluded_brand');
        }

        if ($this->isChildrens($first)) {
            return MappingResult::outOfScope('children');
        }

        $messages = [];
        $facts = [];
        $draftReasons = [];
        $label = trim("{$first['Brand']} {$first['Short Product Name']}")." [{$record->externalId}]";

        foreach ($damaged as $field) {
            $facts[] = ['damaged_text', $field];
        }

        if ($damaged !== []) {
            $messages[] = "{$label}: damaged characters repaired in ".implode(', ', $damaged).'.';
        }

        $collection = $this->config['categories'][$first['Group Class']] ?? null;
        $isFootwear = $collection !== null && CategoryTree::isFootwear($collection);

        // A product in a footwear Group Class may still move between the
        // safety-footwear and non-safety-footwear leaves by its own EN ISO
        // rating: Footsure files some certified boots under a non-safety
        // Group Class, and some uncertified shoes under a safety one.
        if ($isFootwear) {
            $collection = $this->footwearLeaf($collection, $first['Safety Standard']);
        }

        // Insoles are the one non-footwear Group Class genuinely sized on
        // the shoe scale (its UK and EU Size columns are real shoe sizes),
        // so it is sized as footwear although its collection is not one.
        $shoeSized = $isFootwear || $first['Group Class'] === self::INSOLES_GROUP_CLASS;

        $facts[] = ['category', $first['Group Class'].' => '.($collection ?? 'UNMAPPED')];

        if ($collection === null) {
            $draftReasons[] = DraftReason::UNMAPPED_CATEGORY;
            $messages[] = "{$label}: Group Class '{$first['Group Class']}' is not mapped to a collection (draft reason unmapped_category).";
        }

        $isSafety = str_contains($first['Safety Standard'], self::SAFETY_NORM);
        $separateCodes = $first['Safety Cat'] === self::NON_SAFETY ? [] : (preg_split('/\s+/', $first['Safety Cat'], -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if (collect($rows)->contains(fn (array $row): bool => $row['Waterproof'] === '1')) {
            $separateCodes[] = 'WR';
        }

        // Footwear-only: a safety class and features only mean anything on
        // a certified shoe, boot or wellington.
        $separateCodes = $isFootwear ? $separateCodes : [];
        [$features, $unknownFeatures] = $this->features->normalise($separateCodes);

        foreach ($unknownFeatures as $code) {
            $facts[] = ['unknown_feature', $code];
        }

        if ($unknownFeatures !== []) {
            $messages[] = "{$label}: unknown feature codes dropped: ".implode(', ', $unknownFeatures).'.';
        }

        $country = null;

        if (filled($first['Country Of Origin'])) {
            $country = $this->config['countries'][$first['Country Of Origin']] ?? null;

            if ($country === null) {
                $facts[] = ['unknown_country', $first['Country Of Origin']];
                $messages[] = "{$label}: unknown country of origin '{$first['Country Of Origin']}'.";
            }
        }

        $variants = [];
        $images = [];
        $clearance = $first['Product Status'] === 'Clearance';

        $real = $this->withoutGhostRows($rows, $messages);

        // A product made only of placeholder rows has nothing to import. It is
        // not an error: the vendor lists it, but with no real item behind it.
        if ($real === []) {
            return MappingResult::outOfScope('placeholder_rows_only', $facts, SkuIssues::collapse($label, $messages));
        }

        foreach ($this->withoutDuplicates($real, $label, $messages, $shoeSized) as $row) {
            $colour = $this->colour($row);

            if ($colour === null) {
                $draftReasons[] = DraftReason::NO_COLOUR;
                $messages[] = [SkuIssues::NO_COLOUR, $row['Product Reference']];

                // The contract needs a colour value. The vendor's own
                // placeholder is kept so the variant can still be created.
                $colour = self::NOT_APPLICABLE;
            }

            if ($row['Colour Status'] === 'Clearance') {
                $clearance = true;
            }

            $variant = $shoeSized
                ? $this->variant($row, $colour, $isSafety, $label, $messages, $facts)
                : $this->garmentVariant($row, $colour, $label, $messages, $facts);

            if ($variant === null) {
                continue;
            }

            $variants[] = $variant;

            foreach ($this->images($row, $colour) as $image) {
                $images[$image->url.'|'.$image->colour] ??= $image;
            }
        }

        // Drafted for its sizes only when no valid variant remains.
        $noUsableSize = $variants === [] && collect($facts)->contains(fn (array $fact): bool => $fact[0] === 'unknown_size');

        if ($noUsableSize) {
            $draftReasons[] = DraftReason::UNKNOWN_SIZE;
        }

        // One report line per product and reason, not one per SKU.
        $costAboveRrp = SkuIssues::count($messages, SkuIssues::COST_ABOVE_RRP);
        $messages = SkuIssues::collapse($label, $messages);

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

        $brand = array_key_exists($first['Brand'], $this->config['brand_map'] ?? [])
            ? $this->config['brand_map'][$first['Brand']]
            : $first['Brand'];

        return MappingResult::product(new NormalisedProduct(
            vendorCode: self::VENDOR_CODE,
            vendorProductId: $record->externalId,
            label: $label,
            productType: $collection === null ? 'accessories' : CategoryTree::productType($collection),
            brand: blank($brand) ? null : $brand,
            name: $first['Short Product Name'],
            title: $this->text($first['Long Product Name']),
            shortDescription: null,
            description: $this->text($first['InspirationalCopy']),
            keyPoints: $this->keyPoints($first),
            safetyClass: $isFootwear ? SafetyClass::normalise($first['Safety'], $separateCodes) : null,
            features: $features,
            standards: $isFootwear && $first['Safety Standard'] !== self::NON_SAFETY && filled($first['Safety Standard']) ? [$first['Safety Standard']] : [],
            toecap: null,
            midsole: null,
            outsole: $isFootwear ? $this->text($first['SoleMaterial']) : null,
            upper: $isFootwear ? $this->text($first['Upper Outer Material']) : null,
            lining: $isFootwear ? $this->text($first['Lining Material']) : null,
            cut: null,
            fastening: $isFootwear ? $this->text($first['Fastening']) : null,
            industries: [],
            gender: $this->config['gender_map'][$first['Gender']] ?? null,
            countryOfOrigin: $country,
            videoUrl: null,
            certificates: [],
            collections: $collection === null ? [] : [$collection],
            clearance: $clearance,
            draftReasons: array_values(array_unique($draftReasons)),
            commodityCode: $this->text($first['Commodity Code']),
            relatedVendorProductIds: [],
            variants: $variants,
            images: array_values($images),
        ), $messages, $facts);
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $messages
     * @param  list<array{0: string, 1: string}>  $facts
     */
    private function variant(array $row, string $colour, bool $isSafety, string $label, array &$messages, array &$facts): ?NormalisedVariant
    {
        $reference = $row['Product Reference'];
        $uk = Sizes::uk($row['UK Size']);

        if ($uk === null) {
            $messages[] = [SkuIssues::UNKNOWN_SIZE, $reference, 'UK '.($row['UK Size'] === '' ? '(blank)' : $row['UK Size'])];
            $facts[] = ['unknown_size', $row['UK Size'] === '' ? '(blank)' : $row['UK Size']];

            return null;
        }

        $vatBasisPoints = $this->vatBasisPoints($row['VAT']);

        if ($vatBasisPoints === null) {
            $messages[] = [SkuIssues::UNKNOWN_VAT, $reference, "VAT '{$row['VAT']}'"];

            return null;
        }

        // The size scale comes from the EU size. A zero rated row without one
        // cannot be placed on a scale, so it is not imported.
        $eu = is_numeric($row['EU Size']) ? (float) $row['EU Size'] : null;

        if ($vatBasisPoints === 0 && $eu === null) {
            $messages[] = [SkuIssues::NO_EU_SIZE, $reference];
            $facts[] = ['unknown_size', 'no EU size at zero VAT'];

            return null;
        }

        // VAT 0: protective for safety footwear, otherwise children's.
        $taxClass = match (true) {
            $vatBasisPoints > 0 => 'standard',
            $isSafety => 'zero-protective',
            default => 'zero-childrens',
        };

        $cost = Money::toPence($row['Trade Price']);
        $srp = Money::toPence($row['SRP']);
        // SRP is VAT-inclusive at the row's own VAT rate.
        $price = $this->prices->calculate($cost, $srp === null ? null : Money::exVat($srp, $vatBasisPoints));

        if ($price['price'] === null) {
            $messages[] = [SkuIssues::NO_COST, $reference];

            return null;
        }

        if ($price['reason'] === PriceCalculator::COST_AT_OR_ABOVE_RRP) {
            $messages[] = [SkuIssues::COST_ABOVE_RRP, $reference];
        }

        $discontinued = $row['Colour Status'] === 'No More' || $row['Product Status'] === 'No More';
        $sizeName = ShoeSizes::name($uk, $eu);
        [$weight, $length, $width, $height] = $this->measurements($row);

        return new NormalisedVariant(
            vendorSku: $reference,
            sku: self::VENDOR_CODE.'-'.$reference,
            ean: $this->text($row['Barcode']),
            colour: $colour,
            colourHex: null,
            shoeSize: $sizeName,
            sizeSort: ShoeSizes::sort($sizeName),
            euSize: $this->text($row['EU Size']),
            vendorStock: max(0, (int) $row['Stock']),
            sellable: $price['sellable'] && ! $discontinued,
            price: $price['price'],
            comparePrice: $price['compare'],
            costPrice: $cost,
            taxClass: $taxClass,
            weightKg: $weight,
            lengthMm: $length,
            widthMm: $width,
            heightMm: $height,
            vendorProductName: $row['Long Product Name'],
            vendorColour: $this->text($row['Colour Extention']),
            vendorSize: $this->text($row['Size']),
        );
    }

    /**
     * A variant for everything outside the footwear groups: clothing,
     * gloves, eyewear and PPE. Always standard rate (the feed's own VAT
     * column is 20% for all but a handful of rows this store has no zero
     * rate for), sized on the garment scale, no EU size.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $messages
     * @param  list<array{0: string, 1: string}>  $facts
     */
    private function garmentVariant(array $row, string $colour, string $label, array &$messages, array &$facts): ?NormalisedVariant
    {
        $reference = $row['Product Reference'];

        $vatBasisPoints = $this->vatBasisPoints($row['VAT']);

        if ($vatBasisPoints === null) {
            $messages[] = [SkuIssues::UNKNOWN_VAT, $reference, "VAT '{$row['VAT']}'"];

            return null;
        }

        $cost = Money::toPence($row['Trade Price']);
        $srp = Money::toPence($row['SRP']);
        $price = $this->prices->calculate($cost, $srp === null ? null : Money::exVat($srp, $vatBasisPoints));

        if ($price['price'] === null) {
            $messages[] = [SkuIssues::NO_COST, $reference];

            return null;
        }

        if ($price['reason'] === PriceCalculator::COST_AT_OR_ABOVE_RRP) {
            $messages[] = [SkuIssues::COST_ABOVE_RRP, $reference];
        }

        $discontinued = $row['Colour Status'] === 'No More' || $row['Product Status'] === 'No More';
        $raw = $this->garmentSizeRaw($row);
        $size = GarmentSizes::name($raw) ?? GarmentSizes::ONE_SIZE_LABEL;
        [$weight, $length, $width, $height] = $this->measurements($row);

        return new NormalisedVariant(
            vendorSku: $reference,
            sku: self::VENDOR_CODE.'-'.$reference,
            ean: $this->text($row['Barcode']),
            colour: $colour,
            colourHex: null,
            shoeSize: $size,
            sizeSort: GarmentSizes::sort($size),
            euSize: null,
            vendorStock: max(0, (int) $row['Stock']),
            sellable: $price['sellable'] && ! $discontinued,
            price: $price['price'],
            comparePrice: $price['compare'],
            costPrice: $cost,
            taxClass: 'standard',
            weightKg: $weight,
            lengthMm: $length,
            widthMm: $width,
            heightMm: $height,
            vendorProductName: $row['Long Product Name'],
            vendorColour: $this->text($row['Colour Extention']),
            vendorSize: $raw === '' ? null : $raw,
            sizeOptionHandle: 'garment_size',
        );
    }

    /**
     * `Size` is the richest column (it alone carries waist and chest
     * measurements); `UK Size` and `EU Size` are read only when it is blank.
     *
     * @param  array<string, string>  $row
     */
    private function garmentSizeRaw(array $row): string
    {
        foreach ([$row['Size'], $row['UK Size'], $row['EU Size']] as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * A product in a footwear Group Class is reassigned by its own EN ISO
     * rating when the Group Class disagrees with it: a certified product
     * filed under a non-safety Group Class becomes safety footwear, and an
     * uncertified one filed under a safety Group Class becomes casual
     * footwear. A product whose Group Class already agrees is untouched.
     */
    private function footwearLeaf(string $collection, string $safetyStandard): string
    {
        $rated = preg_match(self::FOOTWEAR_NORM_PATTERN, $safetyStandard) === 1;
        $parent = CategoryTree::parents()[$collection] ?? null;

        if ($parent === 'safety-footwear' && ! $rated) {
            return 'casual-footwear';
        }

        if ($parent === 'non-safety-footwear' && $rated) {
            if ($collection === 'wellingtons') {
                return 'safety-wellingtons';
            }

            return str_contains($safetyStandard, 'EN ISO 20347') ? 'occupational-footwear' : 'safety-boots';
        }

        return $collection;
    }

    /**
     * Children's, boys' and girls' lines are out of scope: the Group Class
     * says so directly, or, for a handful of products filed elsewhere, the
     * product's own name does.
     *
     * @param  array<string, string>  $first
     */
    private function isChildrens(array $first): bool
    {
        return preg_match(self::CHILDREN_GROUP_PATTERN, $first['Group Class']) === 1
            || preg_match(self::CHILDREN_NAME_PATTERN, $first['Short Product Name'].' '.$first['Long Product Name']) === 1;
    }

    /**
     * Two rows of one product with the same colour and size cannot both be
     * variants. Keep the one with a barcode; skip and report the other.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $messages
     * @return list<array<string, string>>
     */
    private function withoutDuplicates(array $rows, string $label, array &$messages, bool $shoeSized): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $key = $row['Family Product Reference'].'|'.($this->colour($row) ?? '').'|'.$this->sizeName($row, $shoeSized);

            if (! isset($kept[$key])) {
                $kept[$key] = $row;

                continue;
            }

            $keepNew = blank($kept[$key]['Barcode']) && filled($row['Barcode']);
            $skipped = $keepNew ? $kept[$key] : $row;

            if ($keepNew) {
                $kept[$key] = $row;
            }

            $messages[] = [SkuIssues::DUPLICATE, $skipped['Product Reference'], ($this->colour($row) ?? 'no colour').' '.$this->sizeName($row, $shoeSized)];
        }

        // Colourways of one product can also repeat a colour name.
        $byColourAndSize = [];

        foreach ($kept as $row) {
            $key = ($this->colour($row) ?? '').'|'.$this->sizeName($row, $shoeSized);

            if (! isset($byColourAndSize[$key])) {
                $byColourAndSize[$key] = $row;

                continue;
            }

            $keepNew = blank($byColourAndSize[$key]['Barcode']) && filled($row['Barcode']);
            $skipped = $keepNew ? $byColourAndSize[$key] : $row;

            if ($keepNew) {
                $byColourAndSize[$key] = $row;
            }

            $messages[] = [SkuIssues::DUPLICATE, $skipped['Product Reference'], ($this->colour($row) ?? 'no colour').' '.$this->sizeName($row, $shoeSized)];
        }

        return array_values($byColourAndSize);
    }

    /**
     * The option value a row's size will have, for telling two rows of one
     * colour apart: an infant UK 6 and a junior UK 6 are different sizes on
     * the shoe scale; on the garment scale, whatever GarmentSizes makes of it.
     *
     * @param  array<string, string>  $row
     */
    private function sizeName(array $row, bool $shoeSized): string
    {
        if (! $shoeSized) {
            return GarmentSizes::name($this->garmentSizeRaw($row)) ?? GarmentSizes::ONE_SIZE_LABEL;
        }

        $uk = Sizes::uk($row['UK Size']) ?? $row['UK Size'];

        return ShoeSizes::name($uk, is_numeric($row['EU Size']) ? (float) $row['EU Size'] : null);
    }

    /**
     * Rows that are placeholders, not products: the vendor's placeholder
     * measurements (999 g, 333 x 222 x 111 mm) with no barcode, no EU size
     * and no stock. They are skipped and reported, one line per product.
     * A row with the same measurements that has a barcode or an EU size is a
     * real row and is kept.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<mixed>  $messages
     * @return list<array<string, string>>
     */
    private function withoutGhostRows(array $rows, array &$messages): array
    {
        $placeholder = $this->config['placeholder_measurements'] ?? [];
        $kept = [];

        foreach ($rows as $row) {
            $ghost = $placeholder !== []
                && $row['Weight'] === (string) $placeholder['weight']
                && $row['Length'] === (string) $placeholder['length']
                && $row['Width'] === (string) $placeholder['width']
                && $row['Height'] === (string) $placeholder['height']
                && blank($row['Barcode'])
                && blank($row['EU Size'])
                && (int) $row['Stock'] === 0;

            if ($ghost) {
                $messages[] = [SkuIssues::GHOST, $row['Product Reference']];

                continue;
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * The export has lost some characters before it reaches us: an
     * apostrophe in `Women's` arrives as U+FFFD replacement characters. A
     * run between a letter and an `s` is an apostrophe; any other run is
     * removed. Returns the repaired rows and the names of the fields that
     * were damaged.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{0: list<array<string, string>>, 1: list<string>}
     */
    private function repairedRows(array $rows): array
    {
        $damaged = [];

        foreach ($rows as $index => $row) {
            foreach ($row as $field => $value) {
                if (! str_contains($value, "\u{FFFD}")) {
                    continue;
                }

                $damaged[$field] = true;
                $value = (string) preg_replace('/(?<=\p{L})\x{FFFD}+(?=s\b)/u', "'", $value);
                $rows[$index][$field] = trim((string) preg_replace('/\x{FFFD}+/u', '', $value));
            }
        }

        return [$rows, array_map(strval(...), array_keys($damaged))];
    }

    /**
     * Title case of the vendor's colour description. The colour code is the
     * fallback. Null when the vendor gives neither.
     *
     * @param  array<string, string>  $row
     */
    private function colour(array $row): ?string
    {
        foreach ([$row['Colour Extention'], $row['Colour Code']] as $candidate) {
            if (filled($candidate) && strcasecmp($candidate, self::NOT_APPLICABLE) !== 0) {
                return Str::title(Str::lower($candidate));
            }
        }

        return null;
    }

    /**
     * Image1 is the main image, Image2 to Image8 are details.
     *
     * @param  array<string, string>  $row
     * @return list<NormalisedImage>
     */
    private function images(array $row, string $colour): array
    {
        $images = [];

        foreach (range(1, 8) as $index) {
            $url = $row["Image{$index}"] ?? '';

            if (preg_match('#^https?://#i', $url) === 1) {
                $images[] = new NormalisedImage($url, $colour, $index === 1 ? 'main' : 'detail');
            }
        }

        return $images;
    }

    /**
     * Weight in grams to kg, box dimensions in mm. Placeholder measurements
     * are not real data and become null.
     *
     * @param  array<string, string>  $row
     * @return array{?float, ?float, ?float, ?float}
     */
    private function measurements(array $row): array
    {
        $placeholder = $this->config['placeholder_measurements'] ?? [];

        $isPlaceholder = $placeholder !== []
            && $row['Weight'] === (string) $placeholder['weight']
            && $row['Length'] === (string) $placeholder['length']
            && $row['Width'] === (string) $placeholder['width']
            && $row['Height'] === (string) $placeholder['height'];

        if ($isPlaceholder) {
            return [null, null, null, null];
        }

        $number = fn (string $value): ?float => is_numeric($value) && (float) $value > 0 ? (float) $value : null;
        $grams = $number($row['Weight']);

        return [
            $grams === null ? null : $grams / 1000,
            $number($row['Length']),
            $number($row['Width']),
            $number($row['Height']),
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function keyPoints(array $row): array
    {
        $points = [];

        foreach (range(1, 10) as $index) {
            if (filled($row["Main Copy {$index}"] ?? null)) {
                $points[] = $row["Main Copy {$index}"];
            }
        }

        return $points;
    }

    private function vatBasisPoints(string $vat): ?int
    {
        if (! is_numeric($vat)) {
            return null;
        }

        $basisPoints = (int) round((float) $vat * 10000);

        return in_array($basisPoints, [0, 2000], true) ? $basisPoints : null;
    }

    private function isExcludedBrand(string $brand): bool
    {
        foreach ($this->config['excluded_brands'] ?? [] as $excluded) {
            if (filled($excluded) && Str::startsWith(Str::lower($brand), Str::lower($excluded))) {
                return true;
            }
        }

        return false;
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strcasecmp($value, self::NOT_APPLICABLE) === 0 ? null : $value;
    }
}
