<?php

declare(strict_types=1);

use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use App\Domain\VendorSync\Footsure\FootsureSource;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lunar\Models\ProductVariant;
use Tests\Support\VendorSync;

/*
 * The rows of one Footsure product are not next to each other in the real
 * file (69 products appear in 283 separate runs). The source indexes the
 * byte offset of every row by ProductID, then reads one product at a time.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
});

function sourceFor(string $path): FootsureSource
{
    return new FootsureSource(config('vendorsync.footsure'), $path, checkAge: false);
}

/**
 * @return list<RawRecord>
 */
function readAll(FootsureSource $source): array
{
    return iterator_to_array($source->fetch()->records, false);
}

/**
 * A snapshot of what was imported, independent of the order products were
 * created in.
 *
 * @return array<string, array<string, mixed>>
 */
function importedSnapshot(): array
{
    $snapshot = [];

    foreach (VendorOffer::query()->orderBy('vendor_sku')->get() as $offer) {
        $variant = ProductVariant::query()->find($offer->product_variant_id);
        $product = $variant->product;
        $link = VendorProduct::query()->where('product_id', $product->id)->sole();

        $snapshot[$offer->vendor_sku] = [
            'product' => $link->vendor_product_id,
            'sku' => $variant->sku,
            'stock' => $variant->stock,
            'price' => $variant->prices->sole()->getRawOriginal('price'),
            'colour' => $variant->values->firstWhere('option.handle', 'colour')->translate('name'),
            'size' => $variant->values->firstWhere('option.handle', 'shoe_size')->translate('name'),
            'status' => $product->status,
            'name' => $product->translateAttribute('name'),
        ];
    }

    return $snapshot;
}

it('imports the same whether the rows of a product are together or scattered', function (): void {
    $grouped = fn (array $rows): array => $rows;
    $scattered = function (array $rows): array {
        // Round robin across products, so no two rows of a product are adjacent.
        $byProduct = [];

        foreach ($rows as $row) {
            $byProduct[$row['ProductID']][] = $row;
        }

        $out = [];

        while ($byProduct !== []) {
            foreach ($byProduct as $id => $list) {
                $out[] = array_shift($byProduct[$id]);

                if ($byProduct[$id] === []) {
                    unset($byProduct[$id]);
                }
            }
        }

        return $out;
    };

    VendorSync::footsureFile($grouped);
    VendorSync::import('FS');
    $expected = importedSnapshot();
    $products = VendorProduct::query()->count();

    VendorSync::clearCatalogue();

    VendorSync::footsureFile($scattered);

    $pids = array_column(array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), file(VendorSync::path('incoming').'/footsure.csv')), 3);
    $runs = 1 + count(array_filter(array_keys($pids), fn (int $i): bool => $i > 1 && $pids[$i] !== $pids[$i - 1]));

    $report = VendorSync::import('FS');

    expect($runs)->toBeGreaterThan(100)
        ->and($report->status)->toBe('succeeded')
        ->and($report->seen)->toBe(32)
        ->and(VendorProduct::query()->count())->toBe($products)
        ->and(importedSnapshot())->toEqual($expected);
});

it('indexes rows without loading them and reports the product count before reading products', function (): void {
    $path = VendorSync::footsureFile(fn (array $rows): array => array_reverse($rows));

    $batch = sourceFor($path)->fetch();

    expect($batch->total)->toBe(32);

    $first = null;

    foreach ($batch->records as $record) {
        $first = $record;

        break;
    }

    // A product's rows are complete, whatever their order in the file.
    expect($first)->toBeInstanceOf(RawRecord::class)
        ->and(collect($first->payload['rows'])->pluck('ProductID')->unique()->all())->toBe([$first->externalId])
        ->and($first->hashable['rows'][0])->not->toHaveKey('File_Create_Date');
});

it('reads rows with line breaks and commas inside quoted fields, and a byte order mark', function (): void {
    $path = VendorSync::footsureFile(function (array $rows): array {
        $rows[0]['InspirationalCopy'] = "First line, with a comma\nSecond line \"quoted\"";
        $rows[0]['Short Product Name'] = 'Name, with comma';

        return array_slice($rows, 0, 12);
    });

    file_put_contents($path, "\xEF\xBB\xBF".file_get_contents($path));

    $records = readAll(sourceFor($path));
    $row = collect($records)->flatMap(fn (RawRecord $record): array => $record->payload['rows'])->firstWhere('Short Product Name', 'Name, with comma');

    expect($row['InspirationalCopy'])->toBe("First line, with a comma\nSecond line \"quoted\"")
        // Every row after the one with the line break is still found.
        ->and(collect($records)->sum(fn (RawRecord $record): int => count($record->payload['rows'])))->toBe(12);
});

it('reports rows without a ProductID instead of grouping them together', function (): void {
    $path = VendorSync::footsureFile(function (array $rows): array {
        $rows = array_slice($rows, 0, 10);
        $rows[3]['ProductID'] = '';
        $rows[7]['ProductID'] = '  ';

        return $rows;
    });

    $batch = sourceFor($path)->fetch();
    $records = iterator_to_array($batch->records, false);
    $last = end($records);

    expect($last->error)->toBe('2 rows have no ProductID and were not imported.')
        ->and($last->payload)->toBe([])
        ->and($batch->total)->toBe(count($records));
});

it('aborts on a file with a header only, a short row or a missing column', function (): void {
    $header = trim((string) fgets(fopen(VendorSync::fixture('Footsure/footsure.csv'), 'r')));

    VendorSync::footsureFile(fn (array $rows): array => [], header: $header);
    expect(fn () => sourceFor(VendorSync::path('incoming').'/footsure.csv')->fetch())
        ->toThrow(SourceAborted::class, 'has no data rows');

    $short = VendorSync::footsureFile(fn (array $rows): array => array_slice($rows, 0, 3));
    file_put_contents($short, file_get_contents($short)."a,b,c\r\n");
    expect(fn () => sourceFor($short)->fetch())->toThrow(SourceAborted::class, 'Row 4 of footsure.csv has 3 columns, expected');

    VendorSync::footsureFile(header: str_replace('Barcode', 'EAN', $header));
    expect(fn () => sourceFor(VendorSync::path('incoming').'/footsure.csv')->fetch())
        ->toThrow(SourceAborted::class, 'missing expected columns: Barcode');
});

it('closes the file so it can be archived once a run has finished', function (): void {
    VendorSync::footsureFile();

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('succeeded')
        ->and(glob(VendorSync::path('incoming').'/*.csv'))->toBe([])
        ->and(glob(VendorSync::path('archive').'/footsure-*.csv'))->toHaveCount(1);
});

it('reads 20,000 scattered rows with flat memory', function (): void {
    $megabyte = 1048576;
    $products = 2000;
    $perProduct = 10;

    $path = VendorSync::footsureFile(function (array $rows) use ($products, $perProduct): array {
        $big = [];

        for ($n = 0; $n < $products; $n++) {
            for ($i = 0; $i < $perProduct; $i++) {
                $row = $rows[($n + $i) % count($rows)];
                $row['ProductID'] = (string) (100000 + $n);
                $row['Family Product Reference'] = (100000 + $n).'-1';
                $row['Product Reference'] = "R{$n}-{$i}";
                $big[] = $row;
            }
        }

        mt_srand(42);
        shuffle($big);

        return $big;
    });

    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);

    $batch = sourceFor($path)->fetch();
    $rowsSeen = 0;
    $productsSeen = 0;
    $afterTenPercent = null;

    foreach ($batch->records as $record) {
        $productsSeen++;
        $rowsSeen += count($record->payload['rows']);

        if ($productsSeen === 200) {
            gc_collect_cycles();
            $afterTenPercent = memory_get_usage();
        }

        unset($record);
    }

    gc_collect_cycles();
    $end = memory_get_usage();
    $peak = memory_get_peak_usage(true);

    expect(filesize($path) / $megabyte)->toBeGreaterThan(10.0)
        ->and($batch->total)->toBe($products)
        ->and($productsSeen)->toBe($products)
        ->and($rowsSeen)->toBe($products * $perProduct)
        // Memory after the last product is within 5 MB of memory after the first 10%.
        ->and(abs($end - $afterTenPercent) / $megabyte)->toBeLessThan(5.0)
        // The whole file is over 10 MB, and the source never held it.
        ->and(($peak - $baseline) / $megabyte)->toBeLessThan(8.0);

    fwrite(STDOUT, sprintf(
        "\n  [footsure source] %d rows, file %.1f MB, peak growth %.1f MB, end vs 10%%: %.2f MB\n",
        $rowsSeen,
        filesize($path) / $megabyte,
        ($peak - $baseline) / $megabyte,
        ($end - $afterTenPercent) / $megabyte,
    ));
});

it('does not touch the database while reading the source', function (): void {
    $path = VendorSync::footsureFile();

    DB::enableQueryLog();
    readAll(sourceFor($path));

    expect(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});
