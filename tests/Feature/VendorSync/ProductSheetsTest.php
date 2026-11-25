<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\ProductSheetSync;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\VendorSync;

// The English product sheets of Safety Jogger products: one PDF per colour, stored in the product's `documents` media collection
// (`vendorsync:product-sheets`, docs/vendorsync.md, Product sheets). The product page links the sheet of the chosen colour.

beforeEach(function (): void {
    VendorSync::boot();
});

/** A PDF body that differs by its text. */
function sheetPdf(string $text = 'sheet'): string
{
    return "%PDF-1.4\n% {$text}\n%%EOF\n";
}

/**
 * Imports Ligero (colours Navy, Black and Orange; English sheets for NAV, BLK and ORA) and answers the sheet downloads.
 *
 * @param  array<string, mixed>  $change  Changes to the payload, applied before the import.
 */
function importLigeroWithSheets(?callable $change = null, ?callable $answer = null): VendorProduct
{
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');

    if ($change !== null) {
        $ligero = $change($ligero);
    }

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::answer($answer ?? fn (Request $request) => str_contains($request->url(), '/document/PRODUCT_SHEET/') ? Http::response(sheetPdf(basename($request->url())), 200, ['Content-Type' => 'application/pdf']) : null);
    VendorSync::import('SJ');

    return VendorProduct::query()->where('vendor_product_id', '284096')->sole();
}

/** @return array<string, ?string> The stored sheets of Ligero: source file name => colour. */
function storedSheets(): array
{
    $sheets = [];

    foreach (VendorSync::product('SJ', '284096')->getMedia('documents') as $media) {
        $sheets[basename((string) $media->getCustomProperty('source_url'))] = $media->getCustomProperty('colour');
    }

    ksort($sheets);

    return $sheets;
}

it('stores one English sheet per colour, with the colour name the images use', function (): void {
    $link = importLigeroWithSheets();

    $result = app(ProductSheetSync::class)->sync($link);

    expect($result->downloaded)->toBe(3)
        ->and($result->failed)->toBe([])
        ->and($result->unmatched)->toBe([])
        ->and(storedSheets())->toBe(['LIGERO_BLK_en.pdf' => 'Black', 'LIGERO_NAV_en.pdf' => 'Navy', 'LIGERO_ORA_en.pdf' => 'Orange']);

    $media = VendorSync::product('SJ', '284096')->getMedia('documents')->first();

    expect($media->getCustomProperty('type'))->toBe('product_sheet')
        ->and($media->getCustomProperty('language'))->toBe('en')
        ->and($media->getCustomProperty('sha256'))->toHaveLength(64)
        ->and($media->mime_type)->toBe('application/pdf')
        ->and($media->file_name)->toEndWith('-product-sheet.pdf');

    // The colours are the ones the product's images carry.
    VendorSync::syncImages(['284096']);

    $imageColours = VendorSync::product('SJ', '284096')->getMedia('images')->map(fn ($image) => $image->getCustomProperty('colour'))->unique()->sort()->values()->all();

    expect($imageColours)->toBe(['Black', 'Navy', 'Orange']);
});

it('takes only English product sheets: no other language, certificate or declaration of conformity', function (): void {
    $link = importLigeroWithSheets();

    app(ProductSheetSync::class)->sync($link);

    expect(storedSheets())->toHaveCount(3);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/CERTIFICATE/')
        || str_contains($request->url(), '/DeclarationofConformity/')
        || preg_match('#/PRODUCT_SHEET/[^/]+/(?!en/)[a-z]{2}/#', $request->url()) === 1);
});

it('does not download a sheet that is already stored, and fetches a new one', function (): void {
    $link = importLigeroWithSheets();

    app(ProductSheetSync::class)->sync($link);

    $again = app(ProductSheetSync::class)->sync($link);

    expect($again->downloaded)->toBe(0)->and($again->kept)->toBe(3);

    // One stored sheet is lost: only that one is downloaded again.
    $media = VendorSync::product('SJ', '284096')->getMedia('documents');
    $media->first(fn ($item) => str_contains((string) $item->getCustomProperty('source_url'), 'ORA'))->delete();

    $third = app(ProductSheetSync::class)->sync($link);

    expect($third->downloaded)->toBe(1)->and($third->kept)->toBe(2)->and(storedSheets())->toHaveCount(3);
});

it('replaces a sheet whose file changed on a refresh, and keeps it when the file is the same', function (): void {
    $link = importLigeroWithSheets();

    app(ProductSheetSync::class)->sync($link);

    $before = VendorSync::product('SJ', '284096')->getMedia('documents')->pluck('id')->all();

    $same = app(ProductSheetSync::class)->sync($link, refresh: true);

    expect($same->downloaded)->toBe(0)->and($same->kept)->toBe(3)
        ->and(VendorSync::product('SJ', '284096')->getMedia('documents')->pluck('id')->all())->toBe($before);

    // The vendor reissues the Navy sheet.
    $reissued = importLigeroWithSheets(answer: fn (Request $request) => str_contains($request->url(), 'LIGERO_NAV_en.pdf')
        ? Http::response(sheetPdf('a new issue'), 200, ['Content-Type' => 'application/pdf'])
        : (str_contains($request->url(), '/document/PRODUCT_SHEET/') ? Http::response(sheetPdf(basename($request->url())), 200, ['Content-Type' => 'application/pdf']) : null));

    $changed = app(ProductSheetSync::class)->sync($reissued, refresh: true);

    expect($changed->downloaded)->toBe(1)->and($changed->kept)->toBe(2);
});

it('deletes a stored sheet the vendor no longer lists', function (): void {
    $link = importLigeroWithSheets();

    app(ProductSheetSync::class)->sync($link);

    expect(storedSheets())->toHaveCount(3);

    // The vendor drops the Orange sheet.
    $withoutOrange = importLigeroWithSheets(function (array $payload): array {
        $payload['documents'] = array_values(array_filter($payload['documents'], fn (array $document): bool => ! str_contains($document['url'], 'LIGERO_ORA_en')));

        return $payload;
    });

    $result = app(ProductSheetSync::class)->sync($withoutOrange);

    expect($result->removed)->toBe(1)
        ->and(storedSheets())->toBe(['LIGERO_BLK_en.pdf' => 'Black', 'LIGERO_NAV_en.pdf' => 'Navy']);
});

it('keeps the stored sheets when a download fails, and reports it', function (): void {
    $link = importLigeroWithSheets();

    app(ProductSheetSync::class)->sync($link);

    $withoutOrange = importLigeroWithSheets(function (array $payload): array {
        $payload['documents'] = array_values(array_filter($payload['documents'], fn (array $document): bool => ! str_contains($document['url'], 'LIGERO_ORA_en')));
        // A new Black sheet that cannot be downloaded.
        $payload['documents'][] = ['type' => 'PRODUCT_SHEET', 'label' => 'English', 'fileName' => 'LIGERO_BLK_en_v2.pdf', 'url' => 'https://ws.safetyjogger.com/document/PRODUCT_SHEET/LIGERO/en/LIGERO_BLK_en.pdf?v=2'];

        return $payload;
    }, fn (Request $request) => str_contains($request->url(), '?v=2') ? Http::response('missing', 404) : (str_contains($request->url(), '/document/PRODUCT_SHEET/') ? Http::response(sheetPdf(basename($request->url())), 200) : null));

    $result = app(ProductSheetSync::class)->sync($withoutOrange);

    // The failed one is listed, and nothing was deleted while it is failing.
    expect($result->failed)->toHaveCount(1)
        ->and($result->failed[0])->toContain('?v=2')->toContain('HTTP 404')
        ->and($result->removed)->toBe(0)
        ->and(storedSheets())->toHaveKey('LIGERO_ORA_en.pdf');
});

it('refuses a body that is not a PDF', function (): void {
    $link = importLigeroWithSheets(answer: fn (Request $request) => str_contains($request->url(), '/document/PRODUCT_SHEET/')
        ? Http::response('<html>error page</html>', 200, ['Content-Type' => 'text/html'])
        : null);

    $result = app(ProductSheetSync::class)->sync($link);

    expect($result->downloaded)->toBe(0)
        ->and($result->failed)->toHaveCount(3)
        ->and($result->failed[0])->toContain('the response is not a PDF')
        ->and(storedSheets())->toBe([]);
});

it('gives a single sheet that names no colour to every colour, and reports one it cannot match among several', function (): void {
    $single = importLigeroWithSheets(function (array $payload): array {
        $payload['documents'] = [['type' => 'PRODUCT_SHEET', 'label' => 'English', 'fileName' => 'LIGERO_en.pdf', 'url' => 'https://ws.safetyjogger.com/document/PRODUCT_SHEET/LIGERO/en/LIGERO_en.pdf']];

        return $payload;
    });

    app(ProductSheetSync::class)->sync($single);

    expect(storedSheets())->toBe(['LIGERO_en.pdf' => null]);

    $several = importLigeroWithSheets(function (array $payload): array {
        $payload['documents'] = [
            ['type' => 'PRODUCT_SHEET', 'label' => 'English', 'fileName' => 'a', 'url' => 'https://ws.safetyjogger.com/document/PRODUCT_SHEET/LIGERO/en/LIGERO_NAV_en.pdf'],
            ['type' => 'PRODUCT_SHEET', 'label' => 'English', 'fileName' => 'b', 'url' => 'https://ws.safetyjogger.com/document/PRODUCT_SHEET/LIGERO/en/LIGERO_XXX_en.pdf'],
        ];

        return $payload;
    });

    $result = app(ProductSheetSync::class)->sync($several);

    expect($result->unmatched)->toBe(['https://ws.safetyjogger.com/document/PRODUCT_SHEET/LIGERO/en/LIGERO_XXX_en.pdf'])
        ->and(storedSheets())->toHaveKey('LIGERO_NAV_en.pdf')
        ->and(storedSheets())->not->toHaveKey('LIGERO_XXX_en.pdf');
});

it('leaves a sheet added by hand in the admin panel alone', function (): void {
    $link = importLigeroWithSheets();
    $product = VendorSync::product('SJ', '284096');
    $file = sys_get_temp_dir().'/by-hand-'.uniqid().'.pdf';
    file_put_contents($file, sheetPdf('by hand'));

    $product->addMedia($file)->withCustomProperties(['type' => 'product_sheet', 'colour' => 'Navy'])->toMediaCollection('documents');

    app(ProductSheetSync::class)->sync($link);

    // Three from the vendor, one by hand.
    expect($product->refresh()->getMedia('documents'))->toHaveCount(4);

    // The vendor drops every sheet: the vendor's three go, the hand-made one stays.
    $none = importLigeroWithSheets(function (array $payload): array {
        $payload['documents'] = [];

        return $payload;
    });

    app(ProductSheetSync::class)->sync($none);

    expect($product->refresh()->getMedia('documents'))->toHaveCount(1);
});

it('shows the stored sheet of the chosen colour on the product page', function (): void {
    $link = importLigeroWithSheets();

    app(ProductSheetSync::class)->sync($link);
    VendorSync::syncImages(['284096']);

    $product = VendorSync::product('SJ', '284096');
    $slug = $product->defaultUrl->slug;

    $navy = $this->get(route('product', $slug).'?colour=navy')->assertOk()->getContent();
    $black = $this->get(route('product', $slug).'?colour=black')->assertOk()->getContent();

    preg_match('#<p[^>]*data-product-sheet.*?</p>#s', $navy, $navyLink);
    preg_match('#<p[^>]*data-product-sheet.*?</p>#s', $black, $blackLink);

    expect($navyLink[0] ?? '')->toContain('Product sheet, Navy (PDF)')
        ->and($blackLink[0] ?? '')->toContain('Product sheet, Black (PDF)')
        ->and($navyLink[0] ?? '')->not->toBe($blackLink[0] ?? '');
});

it('runs from the command, one product or all, and reports what it did', function (): void {
    importLigeroWithSheets();

    $this->artisan('vendorsync:product-sheets', ['vendor' => 'SJ'])
        ->expectsOutputToContain('SJ: 1 products, 3 product sheets downloaded, 0 already stored, 0 removed.')
        ->assertSuccessful();

    $this->artisan('vendorsync:product-sheets', ['vendor' => 'SJ', '--product' => '284096'])
        ->expectsOutputToContain('SJ: 1 products, 0 product sheets downloaded, 3 already stored, 0 removed.')
        ->assertSuccessful();

    $this->artisan('vendorsync:product-sheets', ['vendor' => 'SJ', '--product' => '999'])
        ->expectsOutputToContain("SJ has no imported product with vendor product ID '999'.")
        ->assertFailed();

    $this->artisan('vendorsync:product-sheets', ['vendor' => 'XX'])->expectsOutputToContain("Unknown vendor 'XX'")->assertFailed();
});
