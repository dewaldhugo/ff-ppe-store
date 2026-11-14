<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Data\NormalisedImage;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Import\CatalogueWriter;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Brings one product's images in line with the vendor's image list, in the
 * shape the data contract defines: disk `products`, collection `images`,
 * custom properties `colour` and `role`, and each variant linked to its
 * colour's images with the `main` image as primary.
 *
 * The image list comes from the raw record, so no vendor call is needed to
 * know what to download.
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
     * @return array{changed: bool, downloaded: int, failed: list<string>}
     */
    public function sync(VendorProduct $link, bool $refresh = false): array
    {
        $result = ['changed' => false, 'downloaded' => 0, 'failed' => []];

        $plan = $this->plan($link);

        if ($plan === null) {
            return $result;
        }

        /** @var Product $product */
        $product = Product::query()->findOrFail($link->product_id);
        $collection = (string) config('lunar.media.collection');
        $planHash = hash('sha256', json_encode(array_map(fn (NormalisedImage $image): array => [$image->url, $image->colour, $image->role], $plan), JSON_THROW_ON_ERROR));

        // Re-download only when the URL set changed, or on a full refresh.
        if (! $refresh && $link->image_urls_hash === $planHash && $product->getMedia($collection)->isNotEmpty()) {
            return $result;
        }

        $managed = $product->getMedia($collection)
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

            $content = $this->downloader->download($image->url);

            if ($content === null) {
                $result['failed'][] = $image->url;

                // The product keeps its other images, and this one if it had it.
                if ($existing !== null) {
                    $ordered[$key] = $existing;
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

            $media = $product
                ->addMediaFromString($content)
                ->usingName($caption)
                ->usingFileName(Str::slug($caption.' '.$image->role.' '.($index + 1)).'.'.$this->extension($image->url))
                ->withCustomProperties([
                    'colour' => $image->colour,
                    'role' => $image->role,
                    'primary' => false,
                    'source_url' => $image->url,
                    'sha256' => $sha,
                ])
                ->toMediaCollection($collection);

            $existing?->delete();

            $ordered[$key] = $media;
            $result['downloaded']++;
            $result['changed'] = true;
        }

        // Images the vendor no longer lists. Images added in the admin
        // panel have no source URL and are left alone.
        foreach ($managed as $key => $media) {
            if (! isset($ordered[$key]) && $result['failed'] === []) {
                $media->delete();
                $result['changed'] = true;
            }
        }

        $ordered = array_values($ordered);

        if ($ordered !== []) {
            Media::setNewOrder(array_map(fn (Media $media): int => $media->id, $ordered));
            $this->markPrimary($ordered);
            $this->linkVariants($product->refresh(), $ordered);
        }

        $link->update([
            'image_urls_hash' => $result['failed'] === [] ? $planHash : null,
            'images_synced_at' => now(),
        ]);

        // A product stays draft until it has at least one image.
        if ($this->writer->refreshStatus($link->refresh())) {
            $result['changed'] = true;
        }

        if ($result['changed']) {
            $product->refresh()->searchable();
        }

        return $result;
    }

    /**
     * @return list<NormalisedImage>|null
     */
    private function plan(VendorProduct $link): ?array
    {
        $link->loadMissing('vendor');

        $raw = VendorRawRecord::query()
            ->where('vendor_id', $link->vendor_id)
            ->where('external_id', $link->vendor_product_id)
            ->first();

        if ($raw === null || ! $this->container->bound("vendorsync.mapper.{$link->vendor->code}")) {
            return null;
        }

        /** @var VendorMapper $mapper */
        $mapper = $this->container->make("vendorsync.mapper.{$link->vendor->code}");
        $product = $mapper->map(new RawRecord($link->vendor_product_id, $raw->payload))->product;

        if ($product === null) {
            return null;
        }

        $plan = [];

        foreach ($product->images as $image) {
            $plan[$this->key($image->url, $image->colour)] ??= $image;
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
