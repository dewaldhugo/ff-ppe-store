<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

use App\Domain\Catalogue\Urls\Redirects\RedirectRecorder;
use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Merges the products that Footsure's size band split already left in the
 * database: one Lunar product per ProductID becomes one Lunar product per
 * line, with several `vendor_products` rows pointing at it.
 *
 * `plan()` reads only (the stored vendor payloads and the catalogue) and
 * finds the lines with `SplitDetector`, the same code the importer uses on
 * the feed. `merge()` applies one line and writes nothing outside one
 * database transaction, then updates the search index.
 *
 * What a merge does, for a line whose ProductIDs are on products A (the
 * lowest Lunar ID, kept), B and C:
 *
 * - A keeps its ID, its slug and all its content. B and C are soft deleted.
 * - Their variants move to A. Variant IDs, SKUs, prices, vendor offers and
 *   stock are untouched, so carts, orders and order lines still point at the
 *   same variants. Nothing in `lunar_orders` or `lunar_order_lines` is
 *   written, and the vendor snapshot in each order line's meta is not
 *   touched.
 * - Their images move to A (the files stay where they are: they are stored
 *   by media ID). An image whose content is the same as one A already has,
 *   for the same colour, is dropped, and the variants that used it use A's.
 * - Their vendor product links point at A. Every link gets A's sync state
 *   and a cleared payload hash, so the next catalogue run rewrites the
 *   product from all the rows together.
 * - A is added to any collection B or C was in that A is not (a range
 *   collection set in the admin). Their other collection rows go.
 * - Cross-sell associations and favourites that pointed at B or C point at A
 *   (a duplicate or a self reference is dropped). Field locks move too.
 * - B's and C's slugs get a 301 to A's (`url_redirects`), and their URL rows
 *   stay, so a slug is never given to another product.
 * - B and C are removed from the search index and A is sent to it again.
 */
final class SplitMerger
{
    public function __construct(
        private readonly Container $container,
        private readonly SplitDetector $detector,
        private readonly RedirectRecorder $redirects,
    ) {}

    /**
     * @param  string|null  $onlyVendorProductId  Limit to the line this vendor product ID belongs to.
     */
    public function plan(Vendor $vendor, ?string $onlyVendorProductId = null): MergePlan
    {
        /** @var VendorMapper $mapper */
        $mapper = $this->container->make("vendorsync.mapper.{$vendor->code}");

        $signatures = [];
        $seen = [];

        foreach (VendorRawRecord::query()->where('vendor_id', $vendor->id)->select(['id', 'external_id', 'payload'])->lazyById(100) as $raw) {
            // A line listed under several IDs is stored under each; read by the
            // ProductID column, so every ProductID is mapped once, on its own.
            $byId = [];

            foreach ($raw->payload['rows'] ?? [] as $row) {
                $byId[(string) ($row['ProductID'] ?? $raw->external_id)][] = $row;
            }

            foreach ($byId as $id => $rows) {
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $mapped = $mapper->map(new RawRecord((string) $id, ['rows' => $rows]));
                $signature = $mapped->product === null ? null : ProductSignature::from($mapped->product);

                if ($signature !== null) {
                    $signatures[] = $signature;
                }
            }
        }

        $detection = $this->detector->detect($signatures);
        $groups = [];
        $merged = 0;

        foreach ($detection->groups as $ids) {
            if ($onlyVendorProductId !== null && ! in_array($onlyVendorProductId, $ids, true)) {
                continue;
            }

            $group = $this->group($vendor, $ids);

            if ($group === null) {
                $merged++;

                continue;
            }

            $groups[] = $group;
        }

        return new MergePlan($groups, $detection->kept, $merged, count($signatures));
    }

    /**
     * Applies one line. Returns what happened; a line that cannot be merged
     * is left exactly as it was and says why.
     */
    public function merge(Vendor $vendor, MergeGroup $group): MergeResult
    {
        if ($group->skipReason !== null) {
            return MergeResult::skipped($group, $group->skipReason);
        }

        $counts = ['variants' => 0, 'images' => 0, 'images_dropped' => 0, 'favourites' => 0, 'associations' => 0, 'redirects' => 0, 'locks' => 0];

        try {
            Product::withoutSyncingToSearch(function () use ($vendor, $group, &$counts): void {
                DB::transaction(function () use ($vendor, $group, &$counts): void {
                    $this->apply($vendor, $group, $counts);
                });
            });
        } catch (Throwable $exception) {
            report($exception);

            return MergeResult::skipped($group, 'not merged, rolled back: '.class_basename($exception).': '.$exception->getMessage());
        }

        // After the commit, never inside it.
        $warning = null;

        try {
            Product::query()->whereKey($group->keepProductId)->first()?->searchable();

            foreach ($group->removedProductIds() as $productId) {
                Product::withTrashed()->whereKey($productId)->first()?->unsearchable();
            }
        } catch (Throwable $exception) {
            report($exception);
            $warning = 'merged, but the search index was not updated ('.class_basename($exception).': '.$exception->getMessage().'): run php artisan scout:import "Lunar\\Models\\Product" and catalogue:verify-index';
        }

        return MergeResult::merged($group, $counts, $warning);
    }

    /**
     * @param  list<string>  $ids  The line's vendor product IDs, ascending.
     * @return MergeGroup|null Null when the line is already one product.
     */
    private function group(Vendor $vendor, array $ids): ?MergeGroup
    {
        $links = VendorProduct::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('vendor_product_id', $ids)
            ->get(['id', 'product_id', 'vendor_product_id']);

        $productIds = $links->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        if ($productIds->count() < 2) {
            return null;
        }

        $keep = $productIds->first();
        $products = Product::withTrashed()->with('urls')->whereIn('id', $productIds)->get()->keyBy('id');
        $skip = null;

        if ($products->count() !== $productIds->count() || $products->contains(fn (Product $product): bool => $product->trashed())) {
            $skip = 'a product of the line is deleted';
        }

        $variants = ProductVariant::query()
            ->with('values')
            ->whereIn('product_id', $productIds)
            ->get();

        $options = DB::table('lunar_product_product_option')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'product_option_id'])
            ->groupBy('product_id')
            ->map(fn ($rows): string => $rows->pluck('product_option_id')->sort()->implode(','));

        if ($skip === null && $options->unique()->count() > 1) {
            $skip = 'the products are sold by different options';
        }

        // The same colour and size on two products would leave two variants a
        // customer cannot tell apart.
        $pairs = [];

        foreach ($variants as $variant) {
            $key = $variant->values->pluck('id')->sort()->implode(',');

            if ($skip === null && isset($pairs[$key]) && $pairs[$key] !== $variant->product_id) {
                $skip = "variants {$pairs[$key]} and {$variant->product_id} share a colour and size";
            }

            $pairs[$key] = $variant->product_id;
        }

        $variantIds = $variants->where('product_id', '!=', $keep)->pluck('id')->all();
        $mediaCollection = (string) config('lunar.media.collection');
        $mediaOf = fn (int $productId) => Media::query()
            ->where('model_type', (new Product)->getMorphClass())
            ->where('model_id', $productId)
            ->where('collection_name', $mediaCollection);

        $others = [];

        foreach ($productIds->slice(1) as $productId) {
            $product = $products->get($productId);

            $others[] = [
                'product_id' => $productId,
                'slug' => $this->slug($product),
                'status' => (string) $product?->status,
                'vendor_product_ids' => $links->where('product_id', $productId)->pluck('vendor_product_id')->map(strval(...))->values()->all(),
                'variants' => $variants->where('product_id', $productId)->count(),
                'images' => $mediaOf($productId)->count(),
            ];
        }

        $keepProduct = $products->get($keep);
        $label = trim(($keepProduct?->brand?->name ?? '').' '.($keepProduct?->translateAttribute('name') ?? ''));

        return new MergeGroup(
            ids: $ids,
            label: $label,
            keepProductId: $keep,
            keepSlug: $this->slug($keepProduct),
            keepStatus: (string) $keepProduct?->status,
            keepVariants: $variants->where('product_id', $keep)->count(),
            keepImages: $mediaOf($keep)->count(),
            others: $others,
            orderLines: $variantIds === [] ? 0 : DB::table('lunar_order_lines')->where('purchasable_type', (new ProductVariant)->getMorphClass())->whereIn('purchasable_id', $variantIds)->count(),
            cartLines: $variantIds === [] ? 0 : DB::table('lunar_cart_lines')->where('purchasable_type', (new ProductVariant)->getMorphClass())->whereIn('purchasable_id', $variantIds)->count(),
            favourites: DB::table('favourites')->whereIn('product_id', $productIds->slice(1))->count(),
            skipReason: $skip,
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function apply(Vendor $vendor, MergeGroup $group, array &$counts): void
    {
        $keep = $group->keepProductId;
        $removed = $group->removedProductIds();
        $now = now();
        $morph = (new Product)->getMorphClass();

        // The variants, as they are: same IDs, SKUs, prices and offers.
        $counts['variants'] = ProductVariant::query()->whereIn('product_id', $removed)->update(['product_id' => $keep, 'updated_at' => $now]);

        $this->moveImages($keep, $removed, $morph, $counts);

        // Collections: the kept product joins any the others were in.
        $have = DB::table('lunar_collection_product')->where('product_id', $keep)->pluck('collection_id')->all();

        foreach (DB::table('lunar_collection_product')->whereIn('product_id', $removed)->orderBy('product_id')->get() as $row) {
            if (! in_array($row->collection_id, $have, true)) {
                DB::table('lunar_collection_product')->insert([
                    'collection_id' => $row->collection_id,
                    'product_id' => $keep,
                    'position' => (int) DB::table('lunar_collection_product')->where('collection_id', $row->collection_id)->count() + 1,
                ]);
                $have[] = $row->collection_id;
            }
        }

        DB::table('lunar_collection_product')->whereIn('product_id', $removed)->delete();

        $this->moveAssociations($keep, $removed, $counts);

        // Favourites: one row per customer and product.
        $favourited = DB::table('favourites')->where('product_id', $keep)->pluck('user_id')->all();

        foreach (DB::table('favourites')->whereIn('product_id', $removed)->orderBy('id')->get() as $row) {
            if (in_array($row->user_id, $favourited, true)) {
                DB::table('favourites')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('favourites')->where('id', $row->id)->update(['product_id' => $keep]);
            $favourited[] = $row->user_id;
            $counts['favourites']++;
        }

        // Field locks: a field locked on any product stays locked.
        $locked = DB::table('vendor_field_locks')->where('product_id', $keep)->pluck('field')->all();

        foreach (DB::table('vendor_field_locks')->whereIn('product_id', $removed)->get() as $row) {
            if (in_array($row->field, $locked, true)) {
                DB::table('vendor_field_locks')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('vendor_field_locks')->where('id', $row->id)->update(['product_id' => $keep]);
            $locked[] = $row->field;
            $counts['locks']++;
        }

        // The links: all on the kept product, all with its sync state, and a
        // cleared payload and image hash, so the next runs rewrite it.
        $source = VendorProduct::query()->where('vendor_id', $vendor->id)->where('product_id', $keep)->orderBy('id')->firstOrFail();
        $links = VendorProduct::query()->where('vendor_id', $vendor->id)->whereIn('vendor_product_id', $group->ids)->get();

        foreach ($links as $link) {
            $link->update([
                'product_id' => $keep,
                'commodity_code' => $source->commodity_code,
                'content_hashes' => $source->content_hashes,
                'draft_reasons' => array_values(array_diff($source->draft_reasons ?? [], [DraftReason::MISSING_FROM_FEED])),
                'related_ids' => $source->related_ids,
                'missed_runs' => 0,
                'payload_hash' => null,
                'image_urls_hash' => null,
            ]);
        }

        // The old slugs go to the kept product's, and stay reserved.
        foreach ($group->others as $other) {
            foreach ($this->urls($other['product_id']) as $slug) {
                $this->redirects->record(route('product', $slug, false), route('product', $group->keepSlug, false));
                $counts['redirects']++;
            }
        }

        // Soft deleted, never removed: the rows stay for the record.
        foreach ($removed as $productId) {
            Product::query()->whereKey($productId)->first()?->delete();
        }
    }

    /**
     * @param  list<int>  $removed
     * @param  array<string, int>  $counts
     */
    private function moveImages(int $keep, array $removed, string $morph, array &$counts): void
    {
        $collection = (string) config('lunar.media.collection');

        // Kept product's images first, then the others in product order.
        $order = Media::query()->where('model_type', $morph)->where('model_id', $keep)->where('collection_name', $collection)->orderBy('order_column')->orderBy('id')->get();

        $moved = Media::query()
            ->where('model_type', $morph)
            ->whereIn('model_id', $removed)
            ->where('collection_name', $collection)
            ->orderBy('model_id')
            ->orderBy('order_column')
            ->orderBy('id')
            ->get();

        // The same content for the same colour is stored once: the variants
        // that used the duplicate use the one kept.
        $first = [];

        foreach ($order as $media) {
            $first[$media->getCustomProperty('colour').'|'.$media->getCustomProperty('sha256')] ??= $media->id;
        }

        $survivors = $order->pluck('id')->all();

        foreach ($moved as $media) {
            $key = $media->getCustomProperty('colour').'|'.$media->getCustomProperty('sha256');

            if (filled($media->getCustomProperty('sha256')) && isset($first[$key])) {
                $this->repointVariantLinks($media->id, $first[$key]);
                $media->delete();
                $counts['images_dropped']++;

                continue;
            }

            $first[$key] = $media->id;
            $survivors[] = $media->id;
            $counts['images']++;
        }

        Media::query()->whereIn('id', $survivors)->update(['model_id' => $keep, 'updated_at' => now()]);

        $this->singlePrimary($keep, $morph, $collection);

        if ($survivors !== []) {
            Media::setNewOrder($survivors);
        }

        // The documents and videos go with the product too.
        Media::query()
            ->where('model_type', $morph)
            ->whereIn('model_id', $removed)
            ->where('collection_name', '!=', $collection)
            ->update(['model_id' => $keep, 'updated_at' => now()]);
    }

    private function repointVariantLinks(int $fromMediaId, int $toMediaId): void
    {
        $linked = DB::table('lunar_media_product_variant')->where('media_id', $toMediaId)->pluck('product_variant_id')->all();

        foreach (DB::table('lunar_media_product_variant')->where('media_id', $fromMediaId)->get() as $row) {
            if (! in_array($row->product_variant_id, $linked, true)) {
                DB::table('lunar_media_product_variant')->where('id', $row->id)->update(['media_id' => $toMediaId]);
                $linked[] = $row->product_variant_id;
            }
        }
    }

    /**
     * Each product has one primary image: the kept product's own, when it
     * has one still.
     */
    private function singlePrimary(int $keep, string $morph, string $collection): void
    {
        $media = Media::query()->where('model_type', $morph)->where('model_id', $keep)->where('collection_name', $collection)->orderBy('order_column')->orderBy('id')->get();
        $primary = $media->first(fn (Media $item): bool => $item->getCustomProperty('primary') === true);

        foreach ($media as $item) {
            $shouldBe = $primary !== null && $item->is($primary);

            if (($item->getCustomProperty('primary') === true) !== $shouldBe) {
                $item->setCustomProperty('primary', $shouldBe)->saveQuietly();
            }
        }
    }

    /**
     * @param  list<int>  $removed
     * @param  array<string, int>  $counts
     */
    private function moveAssociations(int $keep, array $removed, array &$counts): void
    {
        $table = DB::table('lunar_product_associations');
        $touched = (clone $table)->whereIn('product_parent_id', $removed)->orWhereIn('product_target_id', $removed)->get();

        $existing = [];

        foreach (DB::table('lunar_product_associations')->where('product_parent_id', $keep)->orWhere('product_target_id', $keep)->get() as $row) {
            $existing[$row->product_parent_id.'|'.$row->product_target_id.'|'.$row->type] = true;
        }

        foreach ($touched as $row) {
            $parent = in_array($row->product_parent_id, $removed, true) ? $keep : $row->product_parent_id;
            $target = in_array($row->product_target_id, $removed, true) ? $keep : $row->product_target_id;
            $key = $parent.'|'.$target.'|'.$row->type;

            DB::table('lunar_product_associations')->where('id', $row->id)->delete();

            // Not to itself, and not twice.
            if ($parent === $target || isset($existing[$key])) {
                continue;
            }

            DB::table('lunar_product_associations')->insert([
                'product_parent_id' => $parent,
                'product_target_id' => $target,
                'type' => $row->type,
                'created_at' => $row->created_at,
                'updated_at' => now(),
            ]);

            $existing[$key] = true;
            $counts['associations']++;
        }
    }

    /**
     * @return list<string>
     */
    private function urls(int $productId): array
    {
        return DB::table('lunar_urls')
            ->where('element_type', (new Product)->getMorphClass())
            ->where('element_id', $productId)
            ->pluck('slug')
            ->map(strval(...))
            ->all();
    }

    private function slug(?Product $product): string
    {
        $urls = $product?->urls ?? collect();

        return (string) ($urls->firstWhere('default', true)?->slug ?? $urls->first()?->slug);
    }
}
