<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use App\Domain\Catalogue\Media\ProductMediaDefinitions;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Support\Str;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Brings one Safety Jogger product's English product sheets in line with the vendor's list: one PDF per colour, stored in the
 * product's media collection `documents` (disk `products`) with custom properties `type` = product_sheet, `language` = en and
 * `colour` (the colour option value name, as the product's images carry it). The product page links the sheet of the colour the
 * customer has chosen (data contract, Media). Only English product sheets: no certificates, declarations of conformity or other
 * languages.
 *
 * The list comes from the stored payload, so no vendor API call is needed. A sheet's colour is the code in its file name
 * (`LIGERO_NAV_en.pdf` is the colour with code `NAV`), looked up in the payload's colours; the colour's name is made as
 * SafetyJoggerMapper makes it. A product with a single English sheet whose file name names no colour has that sheet for every
 * colour (no `colour` property). Any other sheet that cannot be matched is reported, not guessed.
 *
 * A sheet already stored under the same URL is kept without a download, unless `$refresh` is set, and then it is replaced only
 * when the file's content changed. A stored sheet the vendor no longer lists is deleted, but only when every download succeeded.
 * Sheets added by hand in the admin panel have no source URL and are left alone.
 */
final readonly class ProductSheetSync
{
    public const string TYPE = 'product_sheet';

    public function __construct(private ImageDownloader $downloader) {}

    public function sync(VendorProduct $link, bool $refresh = false): ProductSheetResult
    {
        $result = new ProductSheetResult;

        $raw = VendorRawRecord::query()
            ->where('vendor_id', $link->vendor_id)
            ->where('external_id', $link->vendor_product_id)
            ->first();

        if ($raw === null) {
            $result->blocked = 'no stored vendor payload; run the catalogue import first';

            return $result;
        }

        $product = Product::query()->find($link->product_id);

        if ($product === null) {
            $result->blocked = 'the Lunar product no longer exists';

            return $result;
        }

        $plan = $this->plan((array) $raw->payload, $result);

        // Product sheets can be larger than Media Library's default of 10 MB; there is no limit here.
        config(['media-library.max_file_size' => max((int) config('media-library.max_file_size'), 1024 ** 3)]);

        $stored = $product->getMedia(ProductMediaDefinitions::DOCUMENTS)
            ->filter(fn (Media $media): bool => $media->getCustomProperty('type') === self::TYPE && filled($media->getCustomProperty('source_url')))
            ->keyBy(fn (Media $media): string => (string) $media->getCustomProperty('source_url'));

        $wanted = [];

        foreach ($plan as $url => $colour) {
            $wanted[$url] = true;
            $existing = $stored->get($url);

            if ($existing !== null && ! $refresh) {
                $this->setColour($existing, $colour);
                $result->kept++;

                continue;
            }

            $download = $this->downloader->downloadPdf($url);

            if (! $download->succeeded()) {
                $result->failed[] = "{$url}: {$download->failure}";

                continue;
            }

            $content = (string) $download->body;
            $sha = hash('sha256', $content);

            if ($existing !== null && $existing->getCustomProperty('sha256') === $sha) {
                $this->setColour($existing, $colour);
                $result->kept++;

                continue;
            }

            try {
                $caption = trim(($product->brand?->name ?? '').' '.$product->translateAttribute('name').' '.($colour ?? '').' product sheet');

                $product
                    ->addMediaFromString($content)
                    ->usingName($caption)
                    ->usingFileName(Str::slug($caption).'.pdf')
                    ->withCustomProperties([
                        'type' => self::TYPE,
                        'language' => 'en',
                        'colour' => $colour,
                        'source_url' => $url,
                        'sha256' => $sha,
                    ])
                    ->toMediaCollection(ProductMediaDefinitions::DOCUMENTS);
            } catch (Throwable $exception) {
                $result->failed[] = "{$url}: ".class_basename($exception).': '.$exception->getMessage();

                continue;
            } finally {
                unset($content);
            }

            $existing?->delete();
            $result->downloaded++;
        }

        // Sheets the vendor no longer lists, once everything it does list is in place.
        if ($result->failed === []) {
            foreach ($stored as $url => $media) {
                if (! isset($wanted[$url])) {
                    $media->delete();
                    $result->removed++;
                }
            }
        }

        return $result;
    }

    /**
     * The English product sheets to store, as URL => colour name (null for a sheet that covers every colour).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, ?string>
     */
    private function plan(array $payload, ProductSheetResult $result): array
    {
        $colours = [];

        foreach ((array) ($payload['colors'] ?? []) as $colour) {
            $code = strtoupper(trim((string) ($colour['code'] ?? '')));
            $description = trim((string) ($colour['description'] ?? ''));

            // The name SafetyJoggerMapper gives the colour option value.
            if ($code !== '' && $description !== '') {
                $colours[$code] = Str::title(Str::lower($description));
            }
        }

        $urls = [];

        foreach ((array) ($payload['documents'] ?? []) as $document) {
            $url = trim((string) ($document['url'] ?? ''));

            if (($document['type'] ?? null) === 'PRODUCT_SHEET' && $url !== '' && $this->isEnglish($document, $url)) {
                $urls[$url] = true;
            }
        }

        $urls = array_keys($urls);
        $plan = [];

        foreach ($urls as $url) {
            $code = preg_match('/_([A-Za-z0-9]+)_en\.pdf$/i', basename((string) parse_url($url, PHP_URL_PATH)), $match) === 1 ? strtoupper($match[1]) : null;

            if ($code !== null && isset($colours[$code])) {
                $plan[$url] = $colours[$code];
            } elseif (count($urls) === 1) {
                // The only sheet, and it names no colour of the product: it covers them all.
                $plan[$url] = null;
            } else {
                $result->unmatched[] = $url;
            }
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isEnglish(array $document, string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return strcasecmp(trim((string) ($document['label'] ?? '')), 'English') === 0
            || preg_match('/_en\.pdf$/i', $path) === 1
            || str_contains($path, '/en/');
    }

    private function setColour(Media $media, ?string $colour): void
    {
        if ($media->getCustomProperty('colour') !== $colour) {
            $media->setCustomProperty('colour', $colour)->save();
        }
    }
}
