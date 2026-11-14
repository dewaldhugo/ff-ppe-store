<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Options\SharedOptionValues;
use App\Domain\Catalogue\Urls\GenerateProductSlug;
use App\Domain\VendorSync\Data\NormalisedProduct;
use App\Domain\VendorSync\Data\NormalisedVariant;
use App\Domain\VendorSync\Exceptions\ProductNotWritable;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Support\Facades\DB;
use Lunar\Base\Enums\ProductAssociation as AssociationType;
use Lunar\FieldTypes\ListField;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Brand;
use Lunar\Models\Collection;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductAssociation;
use Lunar\Models\ProductVariant;

/**
 * The only class that writes catalogue data for the ingestion module. It
 * writes exactly the shape the data contract defines and the fixture seeder
 * produces, for any vendor: it knows nothing about vendor formats.
 *
 * Identity is (vendor, vendor_product_id) for products and
 * (vendor, vendor_sku) for variants, never a name or a slug. Nothing is ever
 * deleted.
 *
 * Callers wrap a run in withoutSyncingToSearch() and reindex afterwards.
 */
final class CatalogueWriter
{
    private const array TRANSLATED = ['name', 'title', 'short_description', 'description'];

    private const array LISTS = ['key_points', 'features', 'standards', 'industries', 'certificates'];

    private ?Language $language = null;

    private ?Currency $currency = null;

    private ?CustomerGroup $customerGroup = null;

    /** @var list<int>|null */
    private ?array $managedCollectionIds = null;

    public function __construct(
        private readonly GenerateProductSlug $generateSlug,
        private readonly SharedOptionValues $optionValues,
        private readonly FieldLocks $locks,
    ) {}

    public function write(Vendor $vendor, NormalisedProduct $data, string $payloadHash): WriteResult
    {
        return DB::transaction(function () use ($vendor, $data, $payloadHash): WriteResult {
            $link = VendorProduct::query()
                ->where('vendor_id', $vendor->id)
                ->where('vendor_product_id', $data->vendorProductId)
                ->first();

            return $link === null
                ? $this->create($vendor, $data, $payloadHash)
                : $this->update($vendor, $link, $data, $payloadHash);
        });
    }

    /**
     * Draft a product that has left the feed and take its variants off
     * sale. Returns false when it was already in that state.
     */
    public function discontinue(VendorProduct $link): bool
    {
        $reasons = $link->draft_reasons ?? [];

        if (in_array(DraftReason::MISSING_FROM_FEED, $reasons, true)) {
            return false;
        }

        DB::transaction(function () use ($link, $reasons): void {
            $link->update(['draft_reasons' => [...$reasons, DraftReason::MISSING_FROM_FEED]]);

            Product::query()->whereKey($link->product_id)->update(['status' => 'draft', 'updated_at' => now()]);

            $variantIds = ProductVariant::query()->where('product_id', $link->product_id)->pluck('id');

            VendorOffer::query()
                ->where('vendor_id', $link->vendor_id)
                ->whereIn('product_variant_id', $variantIds)
                ->update(['sellable' => false, 'updated_at' => now()]);

            ProductVariant::query()->whereIn('id', $variantIds)->update(['stock' => 0, 'updated_at' => now()]);
        });

        return true;
    }

    /**
     * Recompute the published or draft status, for example after the first
     * image arrives. Returns true when the status changed.
     */
    public function refreshStatus(VendorProduct $link): bool
    {
        $product = Product::query()->findOrFail($link->product_id);

        $reasons = array_values(array_diff($link->draft_reasons ?? [], [DraftReason::NO_IMAGE]));

        if (! $product->media()->exists()) {
            $reasons[] = DraftReason::NO_IMAGE;
        }

        $status = $reasons === [] ? 'published' : 'draft';

        if ($reasons !== ($link->draft_reasons ?? [])) {
            $link->update(['draft_reasons' => $reasons]);
        }

        if ($product->status === $status) {
            return false;
        }

        $product->update(['status' => $status]);

        return true;
    }

    /**
     * Turn the vendor's related product IDs into native cross-sell
     * associations, for the related products that exist. Run once at the
     * end of a catalogue run, so products created later in the same run
     * are found.
     */
    public function associate(Vendor $vendor): void
    {
        $links = VendorProduct::query()->where('vendor_id', $vendor->id)->get(['product_id', 'vendor_product_id', 'related_ids']);
        $productIds = $links->pluck('product_id', 'vendor_product_id');

        $existing = ProductAssociation::query()
            ->whereIn('product_parent_id', $productIds->values())
            ->where('type', AssociationType::CROSS_SELL->value)
            ->get(['product_parent_id', 'product_target_id'])
            ->mapWithKeys(fn (ProductAssociation $association): array => [
                $association->product_parent_id.':'.$association->product_target_id => true,
            ]);

        foreach ($links as $link) {
            foreach ($link->related_ids ?? [] as $relatedId) {
                $targetId = $productIds[(string) $relatedId] ?? null;

                if ($targetId === null || $targetId === $link->product_id || isset($existing[$link->product_id.':'.$targetId])) {
                    continue;
                }

                ProductAssociation::query()->create([
                    'product_parent_id' => $link->product_id,
                    'product_target_id' => $targetId,
                    'type' => AssociationType::CROSS_SELL->value,
                ]);

                $existing[$link->product_id.':'.$targetId] = true;
            }
        }
    }

    private function create(Vendor $vendor, NormalisedProduct $data, string $payloadHash): WriteResult
    {
        $messages = [];
        $variants = $this->withoutCrossVendorDuplicates($vendor, $data, $messages);

        if ($variants === []) {
            throw new ProductNotWritable(trim('No variant can be created. '.implode(' ', $messages)));
        }

        // A new product has no image yet, so it starts as draft.
        $reasons = [...$data->draftReasons, DraftReason::NO_IMAGE];

        $product = Product::query()->create([
            'product_type_id' => CatalogueKeys::productType($data->productType)->id,
            'status' => 'draft',
            'brand_id' => $this->brandId($data->brand),
            'attribute_data' => $this->attributeData($data),
        ]);

        // The slug is set once, here, and never changed afterwards.
        $product->urls()->create([
            'slug' => $this->generateSlug->handle((string) $data->brand, $data->name),
            'default' => true,
            'language_id' => $this->language()->id,
        ]);

        VendorProduct::query()->create([
            'product_id' => $product->id,
            'vendor_id' => $vendor->id,
            'vendor_product_id' => $data->vendorProductId,
            'commodity_code' => $data->commodityCode,
            'payload_hash' => $payloadHash,
            'content_hashes' => $this->contentHashes($data),
            'draft_reasons' => $reasons,
            'related_ids' => $data->relatedVendorProductIds,
            'missed_runs' => 0,
        ]);

        $this->syncCollections($product, $data);

        $product->productOptions()->attach([
            $this->optionValues->option('colour')->id => ['position' => 1],
            $this->optionValues->option('shoe_size')->id => ['position' => 2],
        ]);

        $this->colourValues($data);

        foreach ($variants as $variant) {
            $this->createVariant($product, $vendor, $variant);
        }

        return new WriteResult($product->id, true, 'draft', $reasons, $messages);
    }

    private function update(Vendor $vendor, VendorProduct $link, NormalisedProduct $data, string $payloadHash): WriteResult
    {
        $messages = [];

        /** @var Product $product */
        $product = Product::query()->findOrFail($link->product_id);
        $locked = $this->locks->for($product->id);

        [$attributeData, $contentHashes] = $this->mergedAttributeData($product, $link, $data, $locked);

        $reasons = $data->draftReasons;

        if (! $product->media()->exists()) {
            $reasons[] = DraftReason::NO_IMAGE;
        }

        $status = $reasons === [] ? 'published' : 'draft';

        $product->fill([
            'product_type_id' => CatalogueKeys::productType($data->productType)->id,
            'brand_id' => $this->brandId($data->brand),
            'status' => $status,
        ]);

        if ($attributeData !== null) {
            $product->attribute_data = $attributeData;
        }

        if ($product->isDirty()) {
            $product->save();
        }

        $this->syncCollections($product, $data);
        $this->colourValues($data);

        $offers = VendorOffer::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('product_variant_id', ProductVariant::query()->where('product_id', $product->id)->select('id'))
            ->get()
            ->keyBy('vendor_sku');

        $seen = [];

        foreach ($data->variants as $variant) {
            $seen[$variant->vendorSku] = true;
            $offer = $offers->get($variant->vendorSku);

            if ($offer !== null) {
                $this->updateVariant($vendor, $variant, $offer);

                continue;
            }

            if ($this->isCrossVendorDuplicate($vendor, $variant)) {
                $messages[] = $this->duplicateMessage($variant);

                continue;
            }

            $this->createVariant($product, $vendor, $variant);
        }

        // A SKU the vendor no longer lists is taken off sale, never deleted.
        foreach ($offers as $vendorSku => $offer) {
            if (isset($seen[$vendorSku]) || ! $offer->sellable) {
                continue;
            }

            $offer->update(['sellable' => false]);
            ProductVariant::query()->whereKey($offer->product_variant_id)->update(['stock' => 0, 'updated_at' => now()]);
            $messages[] = "SKU {$vendorSku} is no longer listed by the vendor and was set to stock 0.";
        }

        $link->update([
            'commodity_code' => $data->commodityCode,
            'payload_hash' => $payloadHash,
            'content_hashes' => $contentHashes,
            'draft_reasons' => $reasons,
            'related_ids' => $data->relatedVendorProductIds,
            'missed_runs' => 0,
        ]);

        return new WriteResult($product->id, false, $status, $reasons, $messages);
    }

    private function createVariant(Product $product, Vendor $vendor, NormalisedVariant $data): void
    {
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $data->sku,
            'stock' => $this->sellableStock($vendor, $data->vendorStock, $data->sellable),
            'backorder' => 0,
            'purchasable' => 'in_stock',
            'shippable' => true,
            'unit_quantity' => 1,
            ...$this->variantColumns($data),
        ]);

        $variant->values()->attach($this->optionValueIds($data));

        Price::query()->create([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'currency_id' => $this->currency()->id,
            'customer_group_id' => $this->customerGroup()->id,
            'price' => $data->price,
            'compare_price' => $data->comparePrice,
            'min_quantity' => 1,
        ]);

        VendorOffer::query()->create([
            'product_variant_id' => $variant->id,
            'vendor_id' => $vendor->id,
            'vendor_sku' => $data->vendorSku,
            'stock' => $data->vendorStock,
            'last_seen_at' => now(),
            ...$this->offerColumns($data),
        ]);
    }

    private function updateVariant(Vendor $vendor, NormalisedVariant $data, VendorOffer $offer): void
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->findOrFail($offer->product_variant_id);
        $catalogueOwnsStock = $this->catalogueOwnsStock($vendor);

        $variant->fill($this->variantColumns($data, $variant));

        if ($catalogueOwnsStock) {
            $variant->stock = $this->sellableStock($vendor, $data->vendorStock, $data->sellable);
        } elseif (! $data->sellable) {
            $variant->stock = 0;
        } elseif (! $offer->sellable) {
            // Back on sale: restore from the last figure the stock feed gave.
            $variant->stock = $this->sellableStock($vendor, $offer->stock, true);
        }

        if ($variant->isDirty()) {
            $variant->save();
        }

        $valueIds = $this->optionValueIds($data);
        $currentIds = $variant->values()->pluck($variant->values()->getRelated()->getQualifiedKeyName())->all();

        if (array_diff($valueIds, $currentIds) !== [] || array_diff($currentIds, $valueIds) !== []) {
            $variant->values()->sync($valueIds);
        }

        $price = Price::query()
            ->where('priceable_type', $variant->getMorphClass())
            ->where('priceable_id', $variant->id)
            ->where('customer_group_id', $this->customerGroup()->id)
            ->where('currency_id', $this->currency()->id)
            ->where('min_quantity', 1)
            ->first();

        if ($price === null) {
            Price::query()->create([
                'priceable_type' => $variant->getMorphClass(),
                'priceable_id' => $variant->id,
                'currency_id' => $this->currency()->id,
                'customer_group_id' => $this->customerGroup()->id,
                'price' => $data->price,
                'compare_price' => $data->comparePrice,
                'min_quantity' => 1,
            ]);
        } elseif ($price->getRawOriginal('price') !== $data->price || $price->getRawOriginal('compare_price') !== $data->comparePrice) {
            $price->update(['price' => $data->price, 'compare_price' => $data->comparePrice]);
        }

        $offer->fill([
            'last_seen_at' => now(),
            ...$this->offerColumns($data),
        ]);

        if ($catalogueOwnsStock) {
            $offer->stock = $data->vendorStock;
        }

        $offer->save();
    }

    /**
     * Columns the vendor owns on a variant. On update, an attribute_data
     * that would not change is left out so the row is not touched.
     *
     * @return array<string, mixed>
     */
    private function variantColumns(NormalisedVariant $data, ?ProductVariant $existing = null): array
    {
        $columns = [
            'tax_class_id' => CatalogueKeys::taxClass($data->taxClass)->id,
            'ean' => $data->ean,
            'weight_value' => $data->weightKg,
            'weight_unit' => 'kg',
            'length_value' => $data->lengthMm,
            'length_unit' => 'mm',
            'width_value' => $data->widthMm,
            'width_unit' => 'mm',
            'height_value' => $data->heightMm,
            'height_unit' => 'mm',
        ];

        if ($existing === null || (string) $existing->attr('eu_size') !== (string) $data->euSize) {
            $columns['attribute_data'] = filled($data->euSize) ? ['eu_size' => new Text($data->euSize)] : [];
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function offerColumns(NormalisedVariant $data): array
    {
        return [
            'vendor_product_name' => $data->vendorProductName,
            'vendor_colour' => $data->vendorColour,
            'vendor_size' => $data->vendorSize,
            'ean' => $data->ean,
            // Straight from the vendor. Cost is never derived from price.
            'cost_price' => $data->costPrice,
            'sellable' => $data->sellable,
        ];
    }

    /**
     * @return list<int>
     */
    private function optionValueIds(NormalisedVariant $data): array
    {
        return [
            $this->optionValues->value('colour', $data->colour, ['hex' => $data->colourHex])->id,
            $this->optionValues->value(
                'shoe_size',
                $data->shoeSize,
                ['sort' => $data->sizeSort],
                max(0, (int) round($data->sizeSort * 10)),
            )->id,
        ];
    }

    /**
     * Create colour values in the vendor's order, so positions follow it.
     */
    private function colourValues(NormalisedProduct $data): void
    {
        $position = 0;

        foreach ($data->colours() as $name => $hex) {
            $this->optionValues->value('colour', (string) $name, ['hex' => $hex], ++$position);
        }
    }

    private function sellableStock(Vendor $vendor, int $vendorStock, bool $sellable): int
    {
        if (! $sellable) {
            return 0;
        }

        return max(0, $vendorStock - (int) config("vendorsync.vendors.{$vendor->code}.stock_buffer", 0));
    }

    private function catalogueOwnsStock(Vendor $vendor): bool
    {
        return config("vendorsync.vendors.{$vendor->code}.stock_authority", 'catalogue') === 'catalogue';
    }

    /**
     * If a new variant's EAN already belongs to another vendor's variant it
     * is not created: reported instead. Merging is a later phase.
     *
     * @param  list<string>  $messages
     * @return list<NormalisedVariant>
     */
    private function withoutCrossVendorDuplicates(Vendor $vendor, NormalisedProduct $data, array &$messages): array
    {
        $variants = [];

        foreach ($data->variants as $variant) {
            if ($this->isCrossVendorDuplicate($vendor, $variant)) {
                $messages[] = $this->duplicateMessage($variant);

                continue;
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    private function isCrossVendorDuplicate(Vendor $vendor, NormalisedVariant $variant): bool
    {
        return filled($variant->ean) && VendorOffer::query()
            ->where('ean', $variant->ean)
            ->where('vendor_id', '!=', $vendor->id)
            ->exists();
    }

    private function duplicateMessage(NormalisedVariant $variant): string
    {
        return "SKU {$variant->sku} was skipped: EAN {$variant->ean} already belongs to another vendor's variant.";
    }

    private function brandId(?string $brand): ?int
    {
        return blank($brand) ? null : Brand::query()->firstOrCreate(['name' => $brand])->id;
    }

    /**
     * The product's collections in the categories group, and `clearance`,
     * follow the vendor data. Other ranges are managed in the admin panel
     * and are left alone.
     */
    private function syncCollections(Product $product, NormalisedProduct $data): void
    {
        $desired = array_map(fn (string $handle): int => CatalogueKeys::collection($handle)->id, $data->collections);

        if ($data->clearance) {
            $desired[] = CatalogueKeys::collection('clearance')->id;
        }

        $current = $product->collections()
            ->whereIn($product->collections()->getRelated()->getQualifiedKeyName(), $this->managedCollectionIds())
            ->pluck($product->collections()->getRelated()->getQualifiedKeyName())
            ->all();

        $remove = array_diff($current, $desired);

        if ($remove !== []) {
            $product->collections()->detach($remove);
        }

        foreach (array_diff($desired, $current) as $collectionId) {
            $collection = Collection::query()->findOrFail($collectionId);
            $collection->products()->attach($product->id, [
                'position' => $collection->products()->count() + 1,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function managedCollectionIds(): array
    {
        return $this->managedCollectionIds ??= [
            ...Collection::query()
                ->whereHas('group', fn ($query) => $query->where('handle', 'categories'))
                ->pluck('id')
                ->all(),
            CatalogueKeys::collection('clearance')->id,
        ];
    }

    /**
     * Null and empty values are left out rather than stored as blanks.
     *
     * @return array<string, mixed>
     */
    private function attributeData(NormalisedProduct $data): array
    {
        $attributes = [];

        foreach ($data->attributes() as $handle => $value) {
            if (filled($value)) {
                $attributes[$handle] = $this->field($handle, $value);
            }
        }

        return $attributes;
    }

    /**
     * Vendor-owned fields are overwritten. Content fields are written only
     * when the vendor value changed since the last import, so an edit made
     * in the admin panel survives until the vendor changes its own copy. A
     * locked field is never overwritten.
     *
     * @param  list<string>  $locked
     * @return array{0: ?array<string, mixed>, 1: array<string, string>} New attribute data (null when unchanged) and content hashes.
     */
    private function mergedAttributeData(Product $product, VendorProduct $link, NormalisedProduct $data, array $locked): array
    {
        $attributes = $product->attribute_data?->all() ?? [];
        $values = $data->attributes();
        $hashes = $link->content_hashes ?? [];
        $newHashes = $this->contentHashes($data);
        $dirty = false;

        $write = [...NormalisedProduct::VENDOR_OWNED];

        foreach (NormalisedProduct::CONTENT as $handle) {
            if (in_array($handle, $locked, true)) {
                // Keep the old hash, so the vendor value is applied if the
                // lock is ever removed.
                $newHashes[$handle] = $hashes[$handle] ?? '';

                continue;
            }

            if (($hashes[$handle] ?? null) !== $newHashes[$handle]) {
                $write[] = $handle;
            }
        }

        foreach ($write as $handle) {
            if (in_array($handle, $locked, true)) {
                continue;
            }

            $value = $values[$handle];
            $current = $product->translateAttribute($handle);

            if ($this->same($current, $value)) {
                continue;
            }

            if (filled($value)) {
                $attributes[$handle] = $this->field($handle, $value);
            } else {
                unset($attributes[$handle]);
            }

            $dirty = true;
        }

        return [$dirty ? $attributes : null, $newHashes];
    }

    private function same(mixed $current, mixed $new): bool
    {
        if (blank($current) && blank($new)) {
            return true;
        }

        if (is_array($new)) {
            return is_array($current) && array_values($current) === array_values($new);
        }

        return is_scalar($current) && (string) $current === (string) $new;
    }

    /**
     * @return array<string, string>
     */
    private function contentHashes(NormalisedProduct $data): array
    {
        $values = $data->attributes();
        $hashes = [];

        foreach (NormalisedProduct::CONTENT as $handle) {
            $hashes[$handle] = hash('sha256', json_encode($values[$handle], JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }

    /**
     * @param  string|list<string>  $value
     */
    private function field(string $handle, string|array $value): TranslatedText|ListField|Text
    {
        if (in_array($handle, self::TRANSLATED, true)) {
            return new TranslatedText(['en' => new Text((string) $value)]);
        }

        if (in_array($handle, self::LISTS, true)) {
            return new ListField(array_values((array) $value));
        }

        return new Text((string) $value);
    }

    private function language(): Language
    {
        return $this->language ??= Language::getDefault();
    }

    private function currency(): Currency
    {
        return $this->currency ??= Currency::getDefault();
    }

    private function customerGroup(): CustomerGroup
    {
        return $this->customerGroup ??= CustomerGroup::query()->where('handle', 'retail')->sole();
    }
}
