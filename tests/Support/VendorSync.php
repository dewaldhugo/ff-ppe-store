<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Import\AcceptedPrices;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunReport;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Lunar\Models\Product;
use Lunar\Models\ProductAssociation;
use Lunar\Models\ProductVariant;
use Lunar\Models\Url;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Helpers for the ingestion module's tests. No test ever calls a real
 * vendor: every HTTP request is faked and stray requests fail the test.
 */
final class VendorSync
{
    public const string SJ_BASE = 'https://sj.test/v1';

    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private static bool $faked = false;

    /**
     * Answers for other hosts (the pictogram icons, the product sheet PDFs), latest first, asked before a request counts as stray.
     *
     * @var list<callable(Request): mixed>
     */
    private static array $extra = [];

    /** @var list<array<mixed>> */
    private static array $responses = [[], [], [], [], [], []];

    /** @var list<string> Image URL fragments whose request hangs until it times out. */
    private static array $hanging = [];

    /** @var list<string> Image URL fragments that are served, but only after a delay. */
    private static array $slow = [];

    private static int $delaySeconds = 0;

    private static int $hangingRequests = 0;

    /** @var array<int, list<mixed>> Per product id: the answers to its successive GET requests. */
    private static array $sequences = [];

    /** @var array<int, int> Product detail GET requests seen, by product id. */
    private static array $productRequests = [];

    /**
     * Fake credentials, no real sleeping, no stray requests, and an empty
     * catalogue so that imports exercise the create path.
     */
    public static function boot(): void
    {
        config()->set('vendorsync.safety_jogger.base_url', self::SJ_BASE);
        config()->set('vendorsync.safety_jogger.username', 'tester@example.test');
        config()->set('vendorsync.safety_jogger.password', 'test-password');
        config()->set('vendorsync.safety_jogger.token', null);
        config()->set('vendorsync.alert_email', 'alerts@example.test');
        config()->set('vendorsync.reports_path', self::path('reports'));
        config()->set('vendorsync.footsure.incoming_path', self::path('incoming'));
        config()->set('vendorsync.footsure.archive_path', self::path('archive'));

        File::deleteDirectory(self::path(''));
        File::ensureDirectoryExists(self::path('incoming'));

        self::$faked = false;
        self::$extra = [];
        self::$responses = [[], [], [], [], [], []];
        self::$hanging = [];
        self::$slow = [];
        self::$delaySeconds = 0;
        self::$hangingRequests = 0;
        self::$sequences = [];
        self::$productRequests = [];

        Sleep::fake();
        Http::preventStrayRequests();

        self::clearCatalogue();
    }

    public static function path(string $name): string
    {
        return storage_path('framework/testing/vendorsync'.($name === '' ? '' : DIRECTORY_SEPARATOR.$name));
    }

    public static function fixture(string $relative): string
    {
        return base_path('tests/Fixtures/VendorSync/'.$relative);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(string $relative): array
    {
        return json_decode((string) file_get_contents(self::fixture($relative)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Remove the products the fixture seeder created, inside the test's
     * transaction. Tests only: the importer itself never deletes.
     */
    public static function clearCatalogue(): void
    {
        $product = new Product;
        $variant = new ProductVariant;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ([
                'vendor_offers', 'vendor_products', 'vendor_raw_records', 'vendor_field_locks', 'sync_runs',
                $variant->prices()->getRelated()->getTable(),
                $variant->values()->getTable(),
                $variant->images()->getTable(),
                $product->collections()->getTable(),
                $product->productOptions()->getTable(),
                (new ProductAssociation)->getTable(),
                $variant->getTable(),
            ] as $table) {
                DB::table($table)->delete();
            }

            Url::query()->where('element_type', $product->getMorphClass())->delete();
            Media::query()->where('model_type', $product->getMorphClass())->delete();
            DB::table($product->getTable())->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Fake the Safety Jogger API and both vendors' image hosts.
     *
     * @param  list<array<string, mixed>>  $list  The `GET /product` response.
     * @param  array<int, array<string, mixed>>  $details  `GET /product/{id}` responses by id.
     * @param  list<array<string, mixed>>  $stock  The `GET /stock` response.
     * @param  list<string>  $brokenImages  Image URL fragments that return 404.
     * @param  array<int|string, string|array{0: int, 1: int}>  $hugeImages  Image URL fragments that return a PNG header only (not decodable): a plain fragment is
     *                                                                       3692 x 3291, like a vendor original, and `fragment => [width, height]` sets the size.
     * @param  array<string, array{0: int, 1: int}>  $realImages  `fragment => [width, height]`: a real, decodable flat-colour PNG of that size.
     */
    public static function fakeHttp(array $list = [], array $details = [], array $stock = [], array $brokenImages = [], array $hugeImages = [], array $realImages = []): void
    {
        // Laravel stacks fakes and the first one wins, so the fake is
        // registered once per test and reads the latest responses.
        self::$responses = [$list, $details, $stock, $brokenImages, $hugeImages, $realImages];

        if (self::$faked) {
            return;
        }

        self::$faked = true;

        Http::fake(function (Request $request) {
            [$list, $details, $stock, $brokenImages, $hugeImages, $realImages] = self::$responses;

            $url = $request->url();

            if (str_starts_with($url, self::SJ_BASE.'/')) {
                $path = substr($url, strlen(self::SJ_BASE) + 1);

                return match (true) {
                    $path === 'login' && $request->method() === 'POST' => Http::response([
                        'username' => 'tester@example.test',
                        'hash' => 'test-hash',
                        'expirationDate' => now()->addDays(30)->toIso8601String(),
                    ]),
                    $path === 'product' && $request->method() === 'GET' => Http::response($list),
                    $path === 'stock' && $request->method() === 'GET' => Http::response($stock),
                    preg_match('#^product/(\d+)$#', $path, $match) === 1 && $request->method() === 'GET' => self::productReply((int) $match[1], $details),
                    default => Http::response(['error' => 'not allowed'], 405),
                };
            }

            if (preg_match('#^https?://(ws\.safetyjogger\.com/image|ts-images\.gardinerbros\.co\.uk)/#', $url) === 1) {
                // Time passes on the test clock only: sleeping must be synced
                // with Carbon (`Sleep::fake(syncWithCarbon: true)`).
                foreach (self::$hanging as $fragment) {
                    if (str_contains($url, $fragment)) {
                        self::$hangingRequests++;
                        Carbon::setTestNow(Carbon::now()->addSeconds(self::$delaySeconds));

                        throw new ConnectionException('cURL error 28: Operation timed out after '.(self::$delaySeconds * 1000).' milliseconds');
                    }
                }

                foreach (self::$slow as $fragment) {
                    if (str_contains($url, $fragment)) {
                        Carbon::setTestNow(Carbon::now()->addSeconds(self::$delaySeconds));
                    }
                }

                foreach ($brokenImages as $fragment) {
                    if (str_contains($url, $fragment)) {
                        return Http::response('missing', 404);
                    }
                }

                foreach ($hugeImages as $key => $value) {
                    [$fragment, $width, $height] = is_array($value) ? [(string) $key, $value[0], $value[1]] : [$value, 3692, 3291];

                    if (str_contains($url, $fragment)) {
                        return Http::response(self::pngHeader($width, $height).basename($url), 200, ['Content-Type' => 'image/png']);
                    }
                }

                foreach ($realImages as $fragment => [$width, $height]) {
                    if (str_contains($url, (string) $fragment)) {
                        return Http::response(self::flatPng($width, $height).basename($url), 200, ['Content-Type' => 'image/png']);
                    }
                }

                // A valid PNG, unique per file so nothing is deduplicated.
                return Http::response(base64_decode(self::PNG).basename($url), 200, ['Content-Type' => 'image/png']);
            }

            foreach (self::$extra as $answer) {
                $response = $answer($request);

                if ($response !== null) {
                    return $response;
                }
            }

            return Http::response('unexpected request', 500);
        });
    }

    /**
     * Add an answer for requests the vendor fake does not know (a callable that returns a response, or null to pass).
     * Call it after `fakeHttp()`.
     *
     * @param  callable(Request): mixed  $answer
     */
    public static function answer(callable $answer): void
    {
        // The latest answer is asked first, so a test can change what a host says part way through.
        array_unshift(self::$extra, $answer);
    }

    /**
     * Answer successive `GET /product/{id}` requests with successive answers
     * (the last one repeats). An answer is a detail array, or a `reply()` to
     * control the body, status and response headers.
     *
     * @param  list<mixed>  $answers
     */
    public static function sequence(int $id, array $answers): void
    {
        self::$sequences[$id] = $answers;
        // The request count starts again, so a test can run an import twice.
        self::$productRequests[$id] = 0;
    }

    /**
     * A product detail answer with its own body (an array is sent as JSON, a
     * string as it is), status and response headers.
     *
     * @param  array<string, string>  $headers
     * @return array{__reply: array{body: mixed, status: int, headers: array<string, string>}}
     */
    public static function reply(mixed $body, int $status = 200, array $headers = []): array
    {
        return ['__reply' => ['body' => $body, 'status' => $status, 'headers' => $headers]];
    }

    /** How many `GET /product/{id}` requests the fake has answered for a product. */
    public static function productRequests(int $id): int
    {
        return self::$productRequests[$id] ?? 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private static function productReply(int $id, array $details): mixed
    {
        $hits = self::$productRequests[$id] = (self::$productRequests[$id] ?? 0) + 1;

        $answer = isset(self::$sequences[$id])
            ? self::$sequences[$id][min($hits, count(self::$sequences[$id])) - 1]
            : ($details[$id] ?? null);

        if ($answer === null) {
            return Http::response(['error' => 'not found'], 404);
        }

        if (is_array($answer) && isset($answer['__reply'])) {
            return Http::response($answer['__reply']['body'], $answer['__reply']['status'], $answer['__reply']['headers']);
        }

        return Http::response($answer);
    }

    /**
     * Image requests whose URL contains a fragment hang for `$seconds` of
     * test-clock time and then fail with a connection timeout, as a host that
     * accepts the connection and never answers does.
     *
     * @param  list<string>  $fragments
     */
    public static function hangImages(array $fragments, int $seconds = 60): void
    {
        self::$hanging = $fragments;
        self::$delaySeconds = $seconds;
    }

    /**
     * Image requests whose URL contains a fragment take `$seconds` of
     * test-clock time and are then served normally.
     *
     * @param  list<string>  $fragments
     */
    public static function slowImages(array $fragments, int $seconds): void
    {
        self::$slow = $fragments;
        self::$delaySeconds = $seconds;
    }

    /** How many image requests hung (a request that throws is not recorded by Http::fake). */
    public static function hangingRequests(): int
    {
        return self::$hangingRequests;
    }

    /**
     * The first bytes of a PNG that declares the given dimensions. Enough
     * to read its size from; it is never decoded.
     */
    /**
     * A real PNG of one flat colour: a few kilobytes on the wire, but it
     * decodes to width x height pixels, so converting it costs the memory of
     * an image that size. Needs the GD extension, as the app does.
     */
    public static function flatPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 90, 160));

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    public static function pngHeader(int $width, int $height): string
    {
        return hex2bin('89504e470d0a1a0a0000000d49484452').pack('NN', $width, $height).hex2bin('0806000000').pack('N', 0);
    }

    /**
     * A minimal product detail for list items that have no recorded detail.
     *
     * @param  list<string>  $norms
     * @return array<string, mixed>
     */
    public static function detail(int $id, string $name, string $brand, string $articleType, array $norms, ?float $cost = 20.0, float $rrp = 60.0, string $model = 'low', string $size = '42'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'commercialName' => $name,
            'brandDescription' => $brand,
            'articletype' => [['name' => strtolower($articleType), 'value' => $articleType]],
            'model' => [['name' => $model, 'value' => ucfirst($model)]],
            'norms' => array_map(fn (string $norm): array => ['name' => 'N', 'value' => $norm], $norms),
            'standards' => [['name' => 'S3', 'value' => 'S3']],
            'colors' => [[
                'id' => $id * 10,
                'description' => 'black',
                'hexValue' => '000000',
                'skus' => [[
                    'id' => $id * 100,
                    'pieces' => 1,
                    'buyPer' => 1,
                    'eanCode' => '50000'.$id,
                    'sizeRange' => $size,
                    'stock' => 10,
                    'customerPrice' => $cost === null ? null : ['currency' => 'GBP', 'value' => $cost],
                    'rrpPrice' => ['currency' => 'GBP', 'value' => $rrp],
                ]],
                'images' => ['hires' => ["https://ws.safetyjogger.com/image/web/X/HIRES/{$name}-BLK-HIRES.PNG"]],
            ]],
        ];
    }

    /**
     * Copy a CSV into the Footsure incoming folder, optionally changing it.
     *
     * @param  callable(list<array<string, string>>): list<array<string, string>>|null  $change
     */
    public static function footsureFile(?callable $change = null, ?string $header = null, string $name = 'footsure.csv'): string
    {
        $lines = file(self::fixture('Footsure/footsure.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $columns = str_getcsv((string) array_shift($lines), ',', '"', '');
        $rows = array_map(fn (string $line): array => array_combine($columns, str_getcsv($line, ',', '"', '')), $lines);

        if ($change !== null) {
            $rows = $change($rows);
        }

        $target = self::path('incoming').DIRECTORY_SEPARATOR.$name;
        $handle = fopen($target, 'w');
        fwrite($handle, ($header ?? implode(',', $columns))."\r\n");

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '', "\r\n");
        }

        fclose($handle);

        return $target;
    }

    public static function import(string $vendor, bool $dryRun = false, bool $force = false, ?AcceptedPrices $accepted = null): RunReport
    {
        return app(CatalogueImporter::class)->run($vendor, $dryRun, $force, accepted: $accepted ?? AcceptedPrices::none());
    }

    /**
     * Run the image sync inline, for the given vendor product IDs or for
     * every imported product. Conversions make this slow: name the products.
     *
     * @param  list<string>|null  $vendorProductIds
     */
    public static function syncImages(?array $vendorProductIds = null, bool $refresh = false): void
    {
        $links = VendorProduct::query()
            ->when($vendorProductIds !== null, fn ($query) => $query->whereIn('vendor_product_id', $vendorProductIds))
            ->get();

        foreach ($links as $link) {
            app(ProductImageSync::class)->sync($link, $refresh);
        }
    }

    public static function product(string $vendorCode, string $vendorProductId): Product
    {
        $link = VendorProduct::query()
            ->where('vendor_product_id', $vendorProductId)
            ->whereHas('vendor', fn ($query) => $query->where('code', $vendorCode))
            ->sole();

        return Product::query()->findOrFail($link->product_id);
    }
}
