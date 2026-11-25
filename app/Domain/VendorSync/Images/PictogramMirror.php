<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\Catalogue\Display\ProductPictograms;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorRawRecord;

/**
 * Makes sure every pictogram a vendor's products list has its icon file in public/images/pictograms/{name}.png, where the
 * storefront looks for it (ProductPictograms). The names come from the `icons` of the stored vendor payloads, so no vendor API
 * call is needed to know what is wanted. A file that is already there is never replaced, and each icon is fetched once, however
 * many products use it, through the same throttled downloader as the product images.
 *
 * The vendor draws its icons in a set at `vendorsync.images.pictogram_url` (`{name}` is replaced by the icon's name). The files are
 * small PNGs and are stored as they are, not as WebP: they are artwork, not product photos.
 */
final readonly class PictogramMirror
{
    /** Used when the config has no `pictogram_url` (a config cached before it was added). */
    private const string DEFAULT_URL = 'https://www.safetyjogger.com/static/safetyjogger/icon/industrial/{name}.png';

    public function __construct(private ImageDownloader $downloader) {}

    /**
     * @return array{wanted: int, present: int, fetched: list<string>, failed: array<string, string>}
     */
    public function mirror(string $vendorCode): array
    {
        $vendor = Vendor::query()->where('code', $vendorCode)->first();
        $names = $vendor === null ? [] : $this->names($vendor);

        $report = ['wanted' => count($names), 'present' => 0, 'fetched' => [], 'failed' => []];
        $directory = public_path(ProductPictograms::DIRECTORY);

        foreach ($names as $name) {
            $file = "{$directory}/{$name}.png";

            if (is_file($file)) {
                $report['present']++;

                continue;
            }

            $download = $this->downloader->download($this->url($name));

            if (! $download->succeeded()) {
                $report['failed'][$name] = (string) $download->failure;

                continue;
            }

            $body = (string) $download->body;

            if ((@getimagesizefromstring($body)['mime'] ?? null) !== 'image/png') {
                $report['failed'][$name] = 'the response is not a PNG';

                continue;
            }

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($file, $body);
            $report['fetched'][] = $name;
        }

        return $report;
    }

    /**
     * The distinct icon names in the vendor's stored payloads, in the order first met.
     *
     * @return list<string>
     */
    private function names(Vendor $vendor): array
    {
        $names = [];

        VendorRawRecord::query()
            ->where('vendor_id', $vendor->id)
            ->select(['id', 'payload'])
            ->chunkById(200, function ($records) use (&$names): void {
                foreach ($records as $record) {
                    foreach ((array) ($record->payload['icons'] ?? []) as $icon) {
                        $name = strtolower(trim((string) ($icon['name'] ?? '')));

                        if (preg_match('/^[a-z0-9_-]+$/', $name) === 1) {
                            $names[$name] = true;
                        }
                    }
                }
            });

        return array_keys($names);
    }

    private function url(string $name): string
    {
        return str_replace('{name}', $name, (string) config('vendorsync.images.pictogram_url', self::DEFAULT_URL));
    }
}
