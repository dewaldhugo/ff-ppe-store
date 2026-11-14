<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

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

        $first = $rows[0];

        if ($this->isExcludedBrand($first['Brand'])) {
            return MappingResult::outOfScope('excluded_brand');
        }

        $messages = [];
        $facts = [];
        $draftReasons = [];
        $label = "Footsure product {$record->externalId} ({$first['Brand']} {$first['Short Product Name']})";

        $collection = $this->config['categories'][$first['Group Class']] ?? null;

        $facts[] = ['category', $first['Group Class'].' => '.($collection ?? 'UNMAPPED')];

        if ($collection === null) {
            $draftReasons[] = DraftReason::UNMAPPED_CATEGORY;
            $messages[] = "{$label}: Group Class '{$first['Group Class']}' is not mapped to a collection; product drafted.";
        }

        if ($first['Product Status'] === 'No More') {
            $draftReasons[] = DraftReason::VENDOR_DISCONTINUED;
        }

        $isSafety = str_contains($first['Safety Standard'], self::SAFETY_NORM);
        $separateCodes = $first['Safety Cat'] === self::NON_SAFETY ? [] : (preg_split('/\s+/', $first['Safety Cat'], -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if (collect($rows)->contains(fn (array $row): bool => $row['Waterproof'] === '1')) {
            $separateCodes[] = 'WR';
        }

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

        foreach ($this->withoutDuplicates($rows, $label, $messages) as $row) {
            $colour = $this->colour($row);

            if ($colour === null) {
                $draftReasons[] = DraftReason::NO_COLOUR;
                $messages[] = "{$label}: row {$row['Product Reference']} has no colour; product drafted.";

                // The contract needs a colour value. The vendor's own
                // placeholder is kept so the variant can still be created.
                $colour = self::NOT_APPLICABLE;
            }

            if ($row['Colour Status'] === 'Clearance') {
                $clearance = true;
            }

            $variant = $this->variant($row, $colour, $isSafety, $label, $messages);

            if ($variant === null) {
                continue;
            }

            $variants[] = $variant;

            foreach ($this->images($row, $colour) as $image) {
                $images[$image->url.'|'.$image->colour] ??= $image;
            }
        }

        if ($variants === []) {
            return MappingResult::error("{$label}: no variant can be imported.", $messages, $facts);
        }

        $brand = array_key_exists($first['Brand'], $this->config['brand_map'] ?? [])
            ? $this->config['brand_map'][$first['Brand']]
            : $first['Brand'];

        return MappingResult::product(new NormalisedProduct(
            vendorCode: self::VENDOR_CODE,
            vendorProductId: $record->externalId,
            productType: 'safety-footwear',
            brand: blank($brand) ? null : $brand,
            name: $first['Short Product Name'],
            title: $this->text($first['Long Product Name']),
            shortDescription: null,
            description: $this->text($first['InspirationalCopy']),
            keyPoints: $this->keyPoints($first),
            safetyClass: SafetyClass::normalise($first['Safety'], $separateCodes),
            features: $features,
            standards: $first['Safety Standard'] === self::NON_SAFETY || blank($first['Safety Standard']) ? [] : [$first['Safety Standard']],
            toecap: null,
            midsole: null,
            outsole: $this->text($first['SoleMaterial']),
            upper: $this->text($first['Upper Outer Material']),
            lining: $this->text($first['Lining Material']),
            cut: null,
            fastening: $this->text($first['Fastening']),
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
     */
    private function variant(array $row, string $colour, bool $isSafety, string $label, array &$messages): ?NormalisedVariant
    {
        $reference = $row['Product Reference'];
        $uk = Sizes::uk($row['UK Size']);

        if ($uk === null) {
            $messages[] = "{$label}: row {$reference} has an unreadable UK size '{$row['UK Size']}'; row skipped.";

            return null;
        }

        $vatBasisPoints = $this->vatBasisPoints($row['VAT']);

        if ($vatBasisPoints === null) {
            $messages[] = "{$label}: row {$reference} has an unknown VAT rate '{$row['VAT']}'; row skipped.";

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
            $messages[] = "{$label}: row {$reference} has no cost; variant not imported.";

            return null;
        }

        if ($price['reason'] === PriceCalculator::COST_AT_OR_ABOVE_RRP) {
            $messages[] = "{$label}: row {$reference} has a cost at or above its RRP; stock set to 0.";
        }

        $discontinued = $row['Colour Status'] === 'No More' || $row['Product Status'] === 'No More';
        $kids = $taxClass === 'zero-childrens';
        [$weight, $length, $width, $height] = $this->measurements($row);

        return new NormalisedVariant(
            vendorSku: $reference,
            sku: self::VENDOR_CODE.'-'.$reference,
            ean: $this->text($row['Barcode']),
            colour: $colour,
            colourHex: null,
            shoeSize: $kids ? Sizes::kidsName($uk) : $uk,
            sizeSort: $kids ? Sizes::kidsSort($uk) : Sizes::sort($uk),
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
     * Two rows of one product with the same colour and size cannot both be
     * variants. Keep the one with a barcode; skip and report the other.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $messages
     * @return list<array<string, string>>
     */
    private function withoutDuplicates(array $rows, string $label, array &$messages): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $key = $row['Family Product Reference'].'|'.($this->colour($row) ?? '').'|'.$row['UK Size'];

            if (! isset($kept[$key])) {
                $kept[$key] = $row;

                continue;
            }

            $keepNew = blank($kept[$key]['Barcode']) && filled($row['Barcode']);
            $skipped = $keepNew ? $kept[$key] : $row;

            if ($keepNew) {
                $kept[$key] = $row;
            }

            $messages[] = "{$label}: row {$skipped['Product Reference']} duplicates colour and size {$row['UK Size']}; row skipped.";
        }

        // Colourways of one product can also repeat a colour name.
        $byColourAndSize = [];

        foreach ($kept as $row) {
            $key = ($this->colour($row) ?? '').'|'.$row['UK Size'];

            if (! isset($byColourAndSize[$key])) {
                $byColourAndSize[$key] = $row;

                continue;
            }

            $keepNew = blank($byColourAndSize[$key]['Barcode']) && filled($row['Barcode']);
            $skipped = $keepNew ? $byColourAndSize[$key] : $row;

            if ($keepNew) {
                $byColourAndSize[$key] = $row;
            }

            $messages[] = "{$label}: row {$skipped['Product Reference']} duplicates colour and size {$row['UK Size']}; row skipped.";
        }

        return array_values($byColourAndSize);
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
