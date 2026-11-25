<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\PictogramMirror;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\VendorSync;

// The vendor's pictograms: the `icons` of a Safety Jogger payload become the product attribute `pictograms` (`name|Label`, in the
// vendor's order), and `vendorsync:pictograms` downloads each icon file the storefront shows (docs/vendorsync.md, Pictograms).

beforeEach(function (): void {
    VendorSync::boot();
});

/** The directory the icons are written to for these tests, emptied before and after. */
function pictogramTestDirectory(): string
{
    return public_path('images/pictograms');
}

/** A 1 x 1 PNG. */
function pictogramPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

/** Imports Ligero and returns its raw icon names. */
function importLigeroForPictograms(): void
{
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::import('SJ');
}

it('writes the vendor\'s icons as the pictograms attribute, in its order', function (): void {
    importLigeroForPictograms();

    expect(VendorSync::product('SJ', '284096')->attr('pictograms'))->toBe([
        'ce|CE',
        'safetytoecap|Safety toecap',
        'punctureresistant|Puncture resistant',
        'slipresistantoutsole|Slip resistant outsole',
        'oilandfuelresistantoutsole|Oil and fuel resistant outsole',
        'electrostaticdischarge|Electrostatic discharge (ESD)',
        'metalfree|Metalfree',
        'lightweight|Lightweight',
        'breathableupper|Breathable upper',
        'coldinsulated|Cold insulated (CI)',
        '3fitsystem|3-Fit system',
        'individualorthopedicsolutions|Individual Orthopedic Solution',
    ]);
});

it('drops a repeated icon, an unusable name, a blank label and a separator inside a label', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    $ligero['icons'] = [
        ['name' => 'ce', 'value' => 'CE'],
        ['name' => 'ce', 'value' => 'CE again'],
        ['name' => 'Safety Toecap', 'value' => 'Bad name'],
        ['name' => 'leather', 'value' => ''],
        ['name' => 'metalfree', 'value' => 'Metal|free'],
        ['name' => '  Lightweight ', 'value' => ' Lightweight '],
    ];

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::import('SJ');

    expect(VendorSync::product('SJ', '284096')->attr('pictograms'))->toBe(['ce|CE', 'metalfree|Metal free', 'lightweight|Lightweight']);
});

it('writes no pictograms for a payload without icons, and replaces them when the vendor changes the list', function (): void {
    importLigeroForPictograms();

    expect(VendorSync::product('SJ', '284096')->attr('pictograms'))->not->toBe([]);

    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    $ligero['icons'] = [['name' => 'leather', 'value' => 'Leather']];

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::import('SJ');

    expect(VendorSync::product('SJ', '284096')->attr('pictograms'))->toBe(['leather|Leather']);

    $ligero['icons'] = [];

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::import('SJ');

    expect(VendorSync::product('SJ', '284096')->attr('pictograms'))->toBeNull();
});

it('downloads the icons the products list that are not there yet, once each, and never replaces one', function (): void {
    importLigeroForPictograms();

    $directory = pictogramTestDirectory();
    $created = [];

    VendorSync::answer(fn (Request $request) => str_contains($request->url(), '/icon/industrial/') ? Http::response(pictogramPng(), 200, ['Content-Type' => 'image/png']) : null);

    try {
        $report = app(PictogramMirror::class)->mirror('SJ');

        foreach ($report['fetched'] as $name) {
            $created[] = $directory."/{$name}.png";
        }

        // Every icon of Ligero is wanted; those already committed are present (and are never replaced), the others are downloaded.
        expect($report['wanted'])->toBe(12)
            ->and($report['present'] + count($report['fetched']))->toBe(12)
            ->and($report['failed'])->toBe([])
            ->and(array_unique($report['fetched']))->toBe($report['fetched']);

        foreach ($report['fetched'] as $name) {
            Http::assertSent(fn (Request $request): bool => $request->url() === "https://www.safetyjogger.com/static/safetyjogger/icon/industrial/{$name}.png");
            expect(is_file($directory."/{$name}.png"))->toBeTrue();
        }

        // A second run has nothing left to download.
        $again = app(PictogramMirror::class)->mirror('SJ');

        expect($again['fetched'])->toBe([])->and($again['present'])->toBe(12);
    } finally {
        foreach ($created as $file) {
            @unlink($file);
        }
    }
});

it('reports an icon that cannot be downloaded or is not a PNG, and writes nothing for it', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    $ligero['icons'] = [
        ['name' => 'zz-missing-icon', 'value' => 'Missing'],
        ['name' => 'zz-html-icon', 'value' => 'Html'],
    ];

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::import('SJ');

    VendorSync::answer(fn (Request $request) => str_contains($request->url(), 'zz-missing-icon')
        ? Http::response('missing', 404)
        : (str_contains($request->url(), 'zz-html-icon') ? Http::response('<html>not a png</html>', 200, ['Content-Type' => 'text/html']) : null));

    $report = app(PictogramMirror::class)->mirror('SJ');

    expect($report['fetched'])->toBe([])
        ->and(array_keys($report['failed']))->toBe(['zz-missing-icon', 'zz-html-icon'])
        ->and($report['failed']['zz-missing-icon'])->toContain('HTTP 404')
        ->and(is_file(pictogramTestDirectory().'/zz-missing-icon.png'))->toBeFalse()
        ->and(is_file(pictogramTestDirectory().'/zz-html-icon.png'))->toBeFalse();
});

it('runs from the command and says what it downloaded', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    $ligero['icons'] = [['name' => 'zz-command-icon', 'value' => 'Command']];

    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);
    VendorSync::import('SJ');

    VendorSync::answer(fn (Request $request) => str_contains($request->url(), '/icon/industrial/') ? Http::response(pictogramPng(), 200, ['Content-Type' => 'image/png']) : null);

    try {
        $this->artisan('vendorsync:pictograms', ['vendor' => 'SJ'])
            ->expectsOutputToContain('SJ: 1 pictograms listed, 0 already there, 1 downloaded.')
            ->assertSuccessful();

        expect(is_file(pictogramTestDirectory().'/zz-command-icon.png'))->toBeTrue();
    } finally {
        @unlink(pictogramTestDirectory().'/zz-command-icon.png');
    }

    $this->artisan('vendorsync:pictograms', ['vendor' => 'XX'])->expectsOutputToContain("Unknown vendor 'XX'")->assertFailed();
});
