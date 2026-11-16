<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Data\NormalisedImage;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Import\CatalogueWriter;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
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
        $planHash = hash('sha256', json_encode(array_map(fn (NormalisedImage $image): array => [$image->url, $image->colour, $image->role], $plan), JSON_THROW_ON_ERROR));
        $existingMedia = $product->getMedia($collection);

        // Re-download only when the URL set changed, or on a full refresh.
        if (! $refresh && $link->image_urls_hash === $planHash && $existingMedia->isNotEmpty()) {
            $result->upToDate = true;
            $result->stored = $existingMedia->count();

            return $result;
        }

        // Vendor originals can exceed Media Library's default of 10 MB.
        config(['media-library.max_file_size' => max(
            (int) config('media-library.max_file_size'),
            (int) config('vendorsync.images.max_file_bytes', 20 * 1024 * 1024),
        )]);

        $managed = $existingMedia
            ->filter(fn (Media $media): bool => filled($media->getCustomProperty('source_url')))
            ->keyBy(fn (Media $media): string => $this->key($media->getCustomProperty('source_url'), $media->getCustomProperty('colour')));

        $ordered = [];

        foreach ($plan as $index => $image) {
            $key = $this->key($image->url, $image->colour);
            $existing = $managed->get($key);

            if ($existing !== null && ! $refresh) {
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
                    $result->heroFailed = true;
                }

                continue;
            }

            $sha = hash('sha256', $content);

            if ($existing !== null && $existing->getCustomProperty('sha256') === $sha) {
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
                $media = $product
                    ->addMediaFromString($content)
                    ->usingName($caption)
                    ->usingFileName(Str::slug($caption.' '.$image->role.' '.($index + 1)).'.'.$this->extension($downloadedFrom))
                    ->withCustomProperties([
                        'colour' => $image->colour,
                        'role' => $image->role,
                        'primary' => false,
                        // The identity of the image in the vendor's list.
                        'source_url' => $image->url,
                        // Where the stored bytes came from (the fallback, when used).
                        'downloaded_url' => $downloadedFrom,
                        'sha256' => $sha,
                    ])
                    ->toMediaCollection($collection);
            } catch (Throwable $exception) {
                // For example a file over the size limit, or an image the
                // driver cannot convert. Reported, never swallowed.
                $result->failed[] = "{$image->url}: ".class_basename($exception).': '.$exception->getMessage();

                if ($existing !== null) {
                    $ordered[$key] = $existing;
                } elseif ($index === 0) {
                    $result->heroFailed = true;
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

        $link->update([
            // A partial result gets a different hash, so the next run
            // tries the missing images again without re-downloading the rest.
            'image_urls_hash' => $result->failed === []
                ? $planHash
                : hash('sha256', $planHash.'|incomplete|'.implode('|', $result->failed)),
            'images_synced_at' => now(),
        ]);

        // With at least one image, `no_image` is removed and the product
        // is published when no other draft reason remains. Not while the
        // first image of the vendor's list is missing: that image is the
        // product's hero, and another image must not take its place on the
        // storefront. The product stays draft with `no_image`, and the next
        // run fetches only what is missing.
        if (! $result->heroFailed && $this->writer->refreshStatus($link->refresh())) {
            $result->changed = true;
        }

        if ($result->changed) {
            $product->refresh()->searchable();
        }

        return $result;
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
        $maxBytes = (int) config('vendorsync.images.max_file_bytes', 20 * 1024 * 1024);

        if ($bytes > $maxBytes) {
            return sprintf('the file is %.1f MB (limit %.0f MB)', $bytes / 1048576, $maxBytes / 1048576);
        }

        $size = @getimagesizefromstring($content);
        $megapixels = $size === false ? 0.0 : $size[0] * $size[1] / 1_000_000;
        $maxMegapixels = (float) config('vendorsync.images.max_megapixels', 5.0);

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

    private function key(mixed $url, mixed $colour): string
    {
        return $url.'|'.$colour;
    }

    private function extension(string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'jpg';
    }
}
