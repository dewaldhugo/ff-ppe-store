<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * One product in contract terms, ready for the CatalogueWriter. Every
 * contract field is represented. Unknown values are null or empty, never
 * guessed.
 */
final readonly class NormalisedProduct
{
    /** Product attribute handles the vendor owns outright. */
    public const array VENDOR_OWNED = [
        'safety_class', 'features', 'standards', 'toecap', 'midsole', 'outsole', 'upper', 'lining',
        'cut', 'fastening', 'industries', 'gender', 'country_of_origin', 'video_url',
    ];

    /** Content fields: updated only when the vendor value changed and the field is not locked. */
    public const array CONTENT = ['name', 'title', 'short_description', 'description', 'key_points'];

    /**
     * @param  list<string>  $keyPoints
     * @param  list<string>  $features
     * @param  list<string>  $standards
     * @param  list<string>  $industries
     * @param  list<string>  $certificates
     * @param  list<string>  $collections  Contract collection handles in the categories group.
     * @param  list<string>  $draftReasons  Empty means the product may be published.
     * @param  list<string>  $relatedVendorProductIds
     * @param  list<NormalisedVariant>  $variants
     * @param  list<NormalisedImage>  $images
     */
    public function __construct(
        public string $vendorCode,
        public string $vendorProductId,
        // Contract product type handle.
        public string $productType,
        // Null means no customer-facing brand.
        public ?string $brand,
        public string $name,
        public ?string $title,
        public ?string $shortDescription,
        public ?string $description,
        public array $keyPoints,
        public ?string $safetyClass,
        public array $features,
        public array $standards,
        public ?string $toecap,
        public ?string $midsole,
        public ?string $outsole,
        public ?string $upper,
        public ?string $lining,
        public ?string $cut,
        public ?string $fastening,
        public array $industries,
        public ?string $gender,
        public ?string $countryOfOrigin,
        public ?string $videoUrl,
        public array $certificates,
        public array $collections,
        public bool $clearance,
        public array $draftReasons,
        public ?string $commodityCode,
        public array $relatedVendorProductIds,
        public array $variants,
        public array $images,
    ) {}

    /**
     * Attribute values by contract handle.
     *
     * @return array<string, string|list<string>|null>
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'short_description' => $this->shortDescription,
            'description' => $this->description,
            'key_points' => $this->keyPoints,
            'safety_class' => $this->safetyClass,
            'features' => $this->features,
            'standards' => $this->standards,
            'toecap' => $this->toecap,
            'midsole' => $this->midsole,
            'outsole' => $this->outsole,
            'upper' => $this->upper,
            'lining' => $this->lining,
            'cut' => $this->cut,
            'fastening' => $this->fastening,
            'industries' => $this->industries,
            'gender' => $this->gender,
            'country_of_origin' => $this->countryOfOrigin,
            'video_url' => $this->videoUrl,
            'certificates' => $this->certificates,
        ];
    }

    /**
     * Colours in order of first appearance, with their hex when known.
     *
     * @return array<string, ?string>
     */
    public function colours(): array
    {
        $colours = [];

        foreach ($this->variants as $variant) {
            $colours[$variant->colour] ??= $variant->colourHex;
        }

        return $colours;
    }
}
