<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunReport;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Http\Client\Request;
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

    /** @var array{0: array<mixed>, 1: array<mixed>, 2: array<mixed>, 3: array<mixed>} */
    private static array $responses = [[], [], [], []];

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
        self::$responses = [[], [], [], []];

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
     */
    public static function fakeHttp(array $list = [], array $details = [], array $stock = [], array $brokenImages = []): void
    {
        // Laravel stacks fakes and the first one wins, so the fake is
        // registered once per test and reads the latest responses.
        self::$responses = [$list, $details, $stock, $brokenImages];

        if (self::$faked) {
            return;
        }

        self::$faked = true;

        Http::fake(function (Request $request) {
            [$list, $details, $stock, $brokenImages] = self::$responses;

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
                    preg_match('#^product/(\d+)$#', $path, $match) === 1 && $request->method() === 'GET' => isset($details[(int) $match[1]])
                        ? Http::response($details[(int) $match[1]])
                        : Http::response(['error' => 'not found'], 404),
                    default => Http::response(['error' => 'not allowed'], 405),
                };
            }

            if (preg_match('#^https?://(ws\.safetyjogger\.com/image|ts-images\.gardinerbros\.co\.uk)/#', $url) === 1) {
                foreach ($brokenImages as $fragment) {
                    if (str_contains($url, $fragment)) {
                        return Http::response('missing', 404);
                    }
                }

                // A valid PNG, unique per file so nothing is deduplicated.
                return Http::response(base64_decode(self::PNG).basename($url), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('unexpected request', 500);
        });
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

    public static function import(string $vendor, bool $dryRun = false, bool $force = false): RunReport
    {
        return app(CatalogueImporter::class)->run($vendor, $dryRun, $force);
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
