<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Data\NormalisedImage;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Import\CatalogueWriter;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Brings one product's images in line with the vendor's image list, in the
 * shape the data contract defines: disk `products`, collection `images`,
 * custom properties `colour` and `role`, and each variant linked to its
 * colour's images with the `main` image as primary.
 *
 * The image list comes from the raw record, so no vendor call is needed to
 * know what to download.
 *
 * Every image that cannot be saved is returned with its reason. Nothing is
 * swallowed: the caller decides whether the result is a failure.
 */
final readonly class ProductImageSync
{
    public function __construct(
        private Container $container,
        private ImageDownloader $downloader,
        private CatalogueWriter $writer,
        private ImageOptimiser $optimiser,
    ) {}

    /**
     * @param  bool  $refresh  Download every image again and replace the ones whose content changed.
     * @param  CarbonInterface|null  $deadline  The latest moment a download may still start with time to finish
     *                                          (the job's time budget). An image that cannot be tried in time is
     *                                          reported as not attempted. Null means no limit.
     */
    public function sync(VendorProduct $link, bool $refresh = false, ?CarbonInterface $deadline = null): ImageSyncResult
    {
        $result = new ImageSyncResult;
        $plan = $this->plan($link, $result);

        if ($plan === null) {
            return $result;
        }

        $result->planned = count($plan);

        /** @var Product $product */
        $product = Product::query()->findOrFail($link->product_id);
        $collection = (string) config('lunar.media.collection');
        $vendorCode = (string) $link->vendor?->code;
        $planHash = $this->hashOf($plan, $this->alignsProduct($vendorCode, $result->collections));
        $existingMedia = $product->getMedia($collection);

        // Re-download only when the URL set changed, or on a full refresh.
        if (! $refresh && $link->image_urls_hash === $planHash && $existingMedia->isNotEmpty()) {
            $result->upToDate = true;
            $result->stored = $existingMedia->count();

            return $result;
        }

        $managed = $existingMedia
            ->filter(fn (Media $media): bool => filled($media->getCustomProperty('source_url')))
            ->keyBy(fn (Media $media): string => $this->key($media->getCustomProperty('source_url'), $media->getCustomProperty('colour')));

        $ordered = [];
        $storedHero = $this->storedHero($managed, $plan[0]);

        foreach ($plan as $index => $image) {
            $key = $this->key($image->url, $image->colour);
            $existing = $managed->get($key);

            // An image stored before the WebP rule (or under other settings) is fetched and stored again.
            $aligned = $image->role === 'main' && $this->alignsProduct($vendorCode, $result->collections);
            $current = $existing !== null && $existing->getCustomProperty('optimised') === $this->optimiser->signature($aligned);

            if ($current && ! $refresh) {
                $ordered[$key] = $this->withRole($existing, $image);

                continue;
            }

            [$content, $downloadedFrom, $failure] = $this->fetch($image, $result, $deadline);

            if ($content === null) {
                $result->failed[] = "{$image->url}: {$failure}";

                // The product keeps its other images, and this one if it had it.
                if ($existing !== null) {
                    $ordered[$key] = $existing;
                } elseif ($index === 0) {
                    $this->holdHero($result, $storedHero, $ordered, "{$image->url}: {$failure}");
                }

                continue;
            }

            $sha = hash('sha256', $content);

            if ($current && $existing->getCustomProperty('sha256') === $sha) {
                $ordered[$key] = $this->withRole($existing, $image);

                continue;
            }

            // Identical content is never stored twice for one colour.
            $duplicate = collect($ordered)->first(fn (Media $media): bool => $media->getCustomProperty('sha256') === $sha
                && $media->getCustomProperty('colour') === $image->colour);

            if ($duplicate !== null) {
                continue;
            }

            $caption = trim(($product->brand?->name ?? '').' '.$product->translateAttribute('name').' '.$image->colour);

            try {
                // The original is shrunk to the maximum width and stored as WebP; `sha256` stays that of the download, so
                // an unchanged image is recognised without being converted again.
                $media = $product
                    ->addMediaFromString($this->optimiser->optimise($content, $aligned))
                    ->usingName($caption)
                    ->usingFileName(Str::slug($caption.' '.$image->role.' '.($index + 1)).'.webp')
                    ->withCustomProperties([
                        'colour' => $image->colour,
                        'role' => $image->role,
                        'primary' => false,
                        // The identity of the image in the vendor's list.
                        'source_url' => $image->url,
                        // Where the stored bytes came from (the fallback, when used).
                        'downloaded_url' => $downloadedFrom,
                        'sha256' => $sha,
                        // The format the stored file is in (ImageOptimiser::signature()).
                        'optimised' => $this->optimiser->signature($aligned),
                    ])
                    ->toMediaCollection($collection);
            } catch (Throwable $exception) {
                // For example a file over the size limit, or an image the
                // driver cannot convert. Reported, never swallowed.
                $result->failed[] = "{$image->url}: ".class_basename($exception).': '.$exception->getMessage();

                if ($existing !== null) {
                    $ordered[$key] = $existing;
                } elseif ($index === 0) {
                    $this->holdHero($result, $storedHero, $ordered, $result->failed[array_key_last($result->failed)]);
                }

                continue;
            } finally {
                unset($content);
            }

            $existing?->delete();

            $ordered[$key] = $media;
            $result->downloaded++;
            $result->changed = true;
        }

        // Images the vendor no longer lists. Images added in the admin
        // panel have no source URL and are left alone.
        foreach ($managed as $key => $media) {
            if (! isset($ordered[$key]) && $result->failed === []) {
                $media->delete();
                $result->changed = true;
            }
        }

        $ordered = array_values($ordered);
        $product->refresh();
        $result->stored = $product->getMedia($collection)->count();

        if ($ordered !== []) {
            Media::setNewOrder(array_map(fn (Media $media): int => $media->id, $ordered));
            $this->markPrimary($ordered);
            $this->linkVariants($product, $ordered);
        }

        if ($result->stored === 0) {
            // Nothing was saved: leave the product as it was, so the next
            // run starts from scratch.
            return $result;
        }

        // A partial result gets a different hash, so the next run
        // tries the missing images again without re-downloading the rest.
        $newHash = $result->failed === []
            ? $planHash
            : hash('sha256', $planHash.'|incomplete|'.implode('|', $result->failed));

        // The product must be sent to the search index when its images or
        // status changed, when this hash is new, and whenever it is still
        // draft or flagged `no_image`, because the status change below
        // would otherwise be missed by the index.
        $needsIndex = $result->changed
            || $newHash !== $link->image_urls_hash
            || $product->status !== 'published'
            || in_array(DraftReason::NO_IMAGE, $link->draft_reasons ?? [], true);

        // Write ahead: the hash is cleared BEFORE the status is changed and
        // the product is indexed, and written back only once indexing has
        // succeeded. Anything that stops the sync in between (an exception,
        // a timeout, a killed worker) leaves a hash that does not match, so
        // the next run takes this path again, reuses the stored images and
        // reindexes. Clearing it afterwards would not survive a kill, and
        // not advancing it would change nothing on a refresh, where the new
        // hash equals the stored one.
        $link->update([
            'image_urls_hash' => $needsIndex ? null : $newHash,
            'images_synced_at' => now(),
        ]);

        // With at least one image, `no_image` is removed and the product
        // is published when no other draft reason remains. Not while the
        // first image of the vendor's list is missing: that image is the
        // product's hero, and another image must not take its place on the
        // storefront. The product stays draft with `no_image`, and the next
        // run fetches only what is missing. Search syncing is off for this
        // save: the one index call below is the only one, so a failure
        // can be caught and reported.
        if (! $result->heroFailed && Product::withoutSyncingToSearch(fn (): bool => $this->writer->refreshStatus($link->refresh()))) {
            $result->changed = true;
        }

        if (! $needsIndex) {
            return $result;
        }

        // No database transaction is open here, so this call to the search
        // engine never runs inside one.
        try {
            $product->refresh()->searchable();
        } catch (Throwable $exception) {
            report($exception);

            // The images and the status are saved. The hash stays cleared, so
            // the next run reindexes the product without downloading again.
            $result->indexFailure = class_basename($exception).': '.$exception->getMessage();

            return $result;
        }

        $link->update(['image_urls_hash' => $newHash]);

        return $result;
    }

    /**
     * Brings the product's MAIN images (the first picture of each colour) to the current rules and touches nothing else. The
     * other pictures are not read, compared, downloaded or replaced, and the product's status, search index entry and image
     * hash are left as they are: a main image that is not on the current signature is downloaded again and replaces the
     * stored one in place, so the product keeps its order, its primary image and the colour links of its variants. A main image
     * that is not stored yet is not fetched here (that is a normal image run's work), and a failed download keeps the stored image.
     * The search index holds no image, so nothing is sent to it.
     *
     * @param  bool  $refresh  Download every main image again, whatever its signature.
     */
    public function syncMain(VendorProduct $link, bool $refresh = false, ?CarbonInterface $deadline = null): ImageSyncResult
    {
        $result = new ImageSyncResult;
        $plan = $this->plan($link, $result);

        if ($plan === null) {
            return $result;
        }

        /** @var Product $product */
        $product = Product::query()->findOrFail($link->product_id);
        $aligned = $this->alignsProduct((string) $link->vendor?->code, $result->collections);
        $signature = $this->optimiser->signature($aligned);
        $stored = $this->storedMains($link->product_id);

        foreach ($plan as $index => $image) {
            if ($image->role !== 'main') {
                continue;
            }

            $result->planned++;
            $existing = $stored->get($this->key($image->url, $image->colour));

            if ($existing === null) {
                $result->notes[] = basename($image->url).' ('.$image->colour.') is not stored yet; a normal image run fetches it';

                continue;
            }

            $result->stored++;

            if (! $refresh && $existing->getCustomProperty('optimised') === $signature) {
                continue;
            }

            [$content, $downloadedFrom, $failure] = $this->fetch($image, $result, $deadline);

            if ($content === null) {
                $result->failed[] = "{$image->url}: {$failure}";

                continue;
            }

            $caption = trim(($product->brand?->name ?? '').' '.$product->translateAttribute('name').' '.$image->colour);

            try {
                $media = $product
                    ->addMediaFromString($this->optimiser->optimise($content, $aligned))
                    ->usingName($caption)
                    ->usingFileName(Str::slug($caption.' '.$image->role.' '.($index + 1)).'.webp')
                    ->withCustomProperties([
                        'colour' => $image->colour,
                        'role' => $image->role,
                        // The stored image's own flag: it is still the product's primary image if it was.
                        'primary' => (bool) $existing->getCustomProperty('primary'),
                        'source_url' => $image->url,
                        'downloaded_url' => $downloadedFrom,
                        'sha256' => hash('sha256', $content),
                        'optimised' => $signature,
                    ])
                    ->toMediaCollection((string) config('lunar.media.collection'));
            } catch (Throwable $exception) {
                $result->failed[] = "{$image->url}: ".class_basename($exception).': '.$exception->getMessage();

                continue;
            } finally {
                unset($content);
            }

            // The new file takes the old one's place: its position, and the variants' links to it.
            $media->update(['order_column' => $existing->order_column]);
            DB::table('lunar_media_product_variant')->where('media_id', $existing->id)->update(['media_id' => $media->id]);
            $existing->delete();

            $result->downloaded++;
            $result->changed = true;
        }

        return $result;
    }

    /**
     * Whether the main images this product has stored are on the current rules, without reading its other pictures: true when
     * every stored main image of the plan has the current signature (one not stored yet is not this check's concern), and null
     * when the plan is blocked, so that a job is queued to report it.
     */
    public function mainImagesCurrent(VendorProduct $link): ?bool
    {
        $result = new ImageSyncResult;
        $plan = $this->plan($link, $result);

        if ($plan === null) {
            return null;
        }

        $signature = $this->optimiser->signature($this->alignsProduct((string) $link->vendor?->code, $result->collections));
        $stored = $this->storedMains($link->product_id);

        foreach ($plan as $image) {
            $existing = $image->role === 'main' ? $stored->get($this->key($image->url, $image->colour)) : null;

            if ($existing !== null && $existing->getCustomProperty('optimised') !== $signature) {
                return false;
            }
        }

        return true;
    }

    /**
     * The product's stored main images, keyed like the plan's: one query for those and no other picture of the product.
     *
     * @return Collection<string, Media>
     */
    private function storedMains(int $productId): Collection
    {
        return Media::query()
            ->where('model_type', (new Product)->getMorphClass())
            ->where('model_id', $productId)
            ->where('collection_name', (string) config('lunar.media.collection'))
            ->where('custom_properties->role', 'main')
            ->get()
            ->filter(fn (Media $media): bool => filled($media->getCustomProperty('source_url')))
            ->keyBy(fn (Media $media): string => $this->key($media->getCustomProperty('source_url'), $media->getCustomProperty('colour')));
    }

    /**
     * The hash a product's image state must equal for its images to be up to
     * date: the one `sync()` compares with `vendor_products.image_urls_hash`.
     * Null when the plan is blocked (no mapper, no stored payload, no images),
     * which a job reports as a failure.
     *
     * The image dispatcher calls this to leave unchanged products out of the
     * queue, so it and `sync()` cannot disagree about what "unchanged" means.
     */
    public function planHash(VendorProduct $link): ?string
    {
        $result = new ImageSyncResult;
        $plan = $this->plan($link, $result);

        return $plan === null ? null : $this->hashOf($plan, $this->alignsProduct((string) $link->vendor?->code, $result->collections));
    }

    /**
     * @param  list<NormalisedImage>  $plan
     */
    private function hashOf(array $plan, bool $aligned): string
    {
        return hash('sha256', json_encode([
            $this->optimiser->signature(),
            // A vendor whose main images are put on a baseline: a change to that rule has its products fetch them again.
            $this->optimiser->signature(aligned: $aligned),
            array_map(fn (NormalisedImage $image): array => [$image->url, $image->colour, $image->role], $plan),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The image the product already shows as its hero for the plan's first
     * colour: a stored image with the same colour and role as the first image
     * of the list, whatever URL it came from. It is what a hero whose URL
     * changed was stored as, and it is absent on a product that never had its
     * hero (the other images of a first run have other roles).
     *
     * @param  Collection<string, Media>  $managed
     */
    private function storedHero(Collection $managed, NormalisedImage $hero): ?Media
    {
        return $managed->first(fn (Media $media): bool => $media->getCustomProperty('colour') === $hero->colour
            && $media->getCustomProperty('role') === $hero->role);
    }

    /**
     * The first image of the list could not be saved and the product has no
     * image of its own for it. Without a stored hero that holds the product
     * back. With one (the hero's URL changed and the new one failed) the old
     * image stays, first and primary, and the product is left as it was.
     *
     * @param  array<string, Media>  $ordered
     */
    private function holdHero(ImageSyncResult $result, ?Media $storedHero, array &$ordered, string $entry): void
    {
        if ($storedHero === null) {
            $result->heroFailed = true;

            return;
        }

        $ordered[$this->key($storedHero->getCustomProperty('source_url'), $storedHero->getCustomProperty('colour'))] = $storedHero;
        $result->heroKept = true;
        $result->heroRefreshFailure = $entry;
    }

    /**
     * Download an image, falling back to its smaller rendition when the
     * original is too large to process.
     *
     * @return array{0: ?string, 1: string, 2: ?string} Bytes, the URL they came from, and the reason when there are none.
     */
    private function fetch(NormalisedImage $image, ImageSyncResult $result, ?CarbonInterface $deadline): array
    {
        $shortfall = $this->budgetShortfall($image->url, $deadline);

        if ($shortfall !== null) {
            $result->notAttempted++;

            return [null, $image->url, $shortfall];
        }

        $download = $this->downloader->download($image->url);

        if (! $download->succeeded()) {
            $result->lockTimeouts += $download->lockTimedOut ? 1 : 0;

            return [null, $image->url, $download->failure];
        }

        $content = (string) $download->body;
        $tooLarge = $this->tooLarge($content);

        if ($tooLarge === null) {
            return [$content, $image->url, null];
        }

        unset($content);

        if ($image->fallbackUrl === null) {
            return [null, $image->url, "{$tooLarge}, and the vendor lists no smaller rendition"];
        }

        $shortfall = $this->budgetShortfall($image->fallbackUrl, $deadline);

        if ($shortfall !== null) {
            $result->notAttempted++;

            return [null, $image->url, "{$tooLarge}, and the smaller rendition {$image->fallbackUrl} was {$shortfall}"];
        }

        $fallback = $this->downloader->download($image->fallbackUrl);

        if (! $fallback->succeeded()) {
            $result->lockTimeouts += $fallback->lockTimedOut ? 1 : 0;

            return [null, $image->url, "{$tooLarge}, and the smaller rendition {$image->fallbackUrl} could not be downloaded ({$fallback->failure})"];
        }

        $content = (string) $fallback->body;

        if ($this->tooLarge($content) !== null) {
            return [null, $image->url, "{$tooLarge}, and so is the smaller rendition {$image->fallbackUrl}"];
        }

        $result->notes[] = basename($image->url).": {$tooLarge}; used ".basename($image->fallbackUrl).' instead';

        return [$content, $image->fallbackUrl, null];
    }

    /**
     * Why a download must not start, or null when it can. A download starts
     * only when the time left in the job covers the longest it can take, so
     * a hanging host cannot push the job past its timeout and lose its report.
     */
    private function budgetShortfall(string $url, ?CarbonInterface $deadline): ?string
    {
        if ($deadline === null) {
            return null;
        }

        $left = max(0, $deadline->getTimestamp() - now()->getTimestamp());
        $needed = $this->downloader->worstCaseDownloadSeconds($url);

        return $left >= $needed
            ? null
            : "not attempted: job time budget exhausted ({$left}s left, a download can take up to {$needed}s)";
    }

    /**
     * Why the image cannot be processed within the worker's memory, or
     * null when it can. Reads the dimensions from the header only.
     */
    private function tooLarge(string $content): ?string
    {
        $bytes = strlen($content);
        $maxBytes = (int) config('vendorsync.images.max_file_bytes', 0);

        if ($maxBytes > 0 && $bytes > $maxBytes) {
            return sprintf('the file is %.1f MB (limit %.0f MB)', $bytes / 1048576, $maxBytes / 1048576);
        }

        $maxMegapixels = (float) config('vendorsync.images.max_megapixels', 0);

        if ($maxMegapixels <= 0) {
            return null;
        }

        $size = @getimagesizefromstring($content);
        $megapixels = $size === false ? 0.0 : $size[0] * $size[1] / 1_000_000;

        if ($megapixels > $maxMegapixels) {
            return sprintf('the image is %d x %d (%.1f megapixels, limit %.1f)', $size[0], $size[1], $megapixels, $maxMegapixels);
        }

        return null;
    }

    /**
     * The image list, or null with the reason in `$result->blocked`.
     *
     * @return list<NormalisedImage>|null
     */
    private function plan(VendorProduct $link, ImageSyncResult $result): ?array
    {
        $link->loadMissing('vendor');
        $code = (string) $link->vendor?->code;

        if (! $this->container->bound("vendorsync.mapper.{$code}")) {
            $result->blocked = "no mapper is registered for vendor '{$code}'";

            return null;
        }

        $raw = VendorRawRecord::query()
            ->where('vendor_id', $link->vendor_id)
            ->where('external_id', $link->vendor_product_id)
            ->first();

        if ($raw === null) {
            $result->blocked = 'no stored vendor payload; run the catalogue import first';

            return null;
        }

        /** @var VendorMapper $mapper */
        $mapper = $this->container->make("vendorsync.mapper.{$code}");
        $mapped = $mapper->map(new RawRecord($link->vendor_product_id, $raw->payload));

        if ($mapped->product === null) {
            $result->blocked = 'the stored vendor payload no longer maps to a product ('.($mapped->outOfScope ?? $mapped->error ?? 'unknown reason').')';

            return null;
        }

        $plan = [];

        $result->collections = $mapped->product->collections;

        foreach ($mapped->product->images as $image) {
            $plan[$this->key($image->url, $image->colour)] ??= $image;
        }

        if ($plan === []) {
            $result->blocked = 'the vendor payload lists no images for this product';

            return null;
        }

        return array_values($plan);
    }

    private function withRole(Media $media, NormalisedImage $image): Media
    {
        if ($media->getCustomProperty('role') !== $image->role) {
            $media->setCustomProperty('role', $image->role)->save();
        }

        return $media;
    }

    /**
     * The first main image is the product thumbnail.
     *
     * @param  list<Media>  $ordered
     */
    private function markPrimary(array $ordered): void
    {
        $primary = collect($ordered)->first(fn (Media $media): bool => $media->getCustomProperty('role') === 'main') ?? $ordered[0];

        foreach ($ordered as $media) {
            $isPrimary = $media->is($primary);

            if ((bool) $media->getCustomProperty('primary') !== $isPrimary) {
                $media->setCustomProperty('primary', $isPrimary)->save();
            }
        }
    }

    /**
     * Each variant gets its colour's images, the main image first and
     * primary.
     *
     * @param  list<Media>  $ordered
     */
    private function linkVariants(Product $product, array $ordered): void
    {
        $byColour = collect($ordered)
            ->sortBy(fn (Media $media): int => $media->getCustomProperty('role') === 'main' ? 0 : 1)
            ->groupBy(fn (Media $media): string => (string) $media->getCustomProperty('colour'));

        foreach ($product->variants()->with('values.option')->get() as $variant) {
            $colour = $variant->values->firstWhere('option.handle', 'colour')?->translate('name');
            $images = $byColour->get((string) $colour);

            if ($images === null) {
                continue;
            }

            $sync = [];

            foreach ($images->values() as $position => $media) {
                $sync[$media->id] = ['primary' => $position === 0, 'position' => $position + 1];
            }

            $variant->images()->sync($sync);
        }
    }

    /**
     * Whether the main images of this product are put on the common baseline: its vendor has it switched on
     * (`vendorsync.images.align.vendors`) and the product is in one of the categories it is limited to
     * (`vendorsync.images.align.collections`, safety footwear; a top level category means all the categories under it, and an empty list means every category).
     *
     * @param  list<string>  $collections  The product's category handles.
     */
    private function alignsProduct(string $vendorCode, array $collections): bool
    {
        if (! in_array($vendorCode, $this->alignedVendors(), true)) {
            return false;
        }

        $limit = array_values(array_map(strval(...), (array) config('vendorsync.images.align.collections', [])));

        // A top level category such as safety-footwear means all the leaf categories under it: products are in leaves only.
        $leaves = array_values(array_unique(array_merge(...array_map(CategoryTree::leavesUnder(...), $limit ?: ['']))));

        return $limit === [] || array_intersect($collections, $leaves) !== [];
    }

    /**
     * @return list<string>
     */
    private function alignedVendors(): array
    {
        return array_values(array_map(strval(...), (array) config('vendorsync.images.align.vendors', [])));
    }

    private function key(mixed $url, mixed $colour): string
    {
        return $url.'|'.$colour;
    }
}
