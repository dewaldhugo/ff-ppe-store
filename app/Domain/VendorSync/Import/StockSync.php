<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Contracts\StockFeed;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use App\Domain\VendorSync\Models\Vendor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Throwable;

/**
 * The stock fast path. Updates `vendor_offers.stock` and the Lunar variant
 * stock (after the safety buffer) with bulk updates, without loading
 * products, and reindexes only products whose in-stock flag, in-stock
 * colours or in-stock sizes changed.
 *
 * Rows for SKUs that are not imported are ignored. An offer that a rule has
 * taken off sale keeps a variant stock of 0 whatever the feed says.
 */
final class StockSync
{
    public const string TYPE = 'stock';

    private const int CHUNK = 500;

    public function __construct(
        private readonly Container $container,
        private readonly RunRecorder $runs,
    ) {}

    public function run(string $vendorCode): RunReport
    {
        $code = strtoupper($vendorCode);
        $report = new RunReport($code, self::TYPE);

        if (! $this->container->bound("vendorsync.stock.{$code}")) {
            return $report->finish(RunReport::ABORTED, "{$code} has no stock feed. Its stock is updated by its catalogue run.");
        }

        $vendor = $this->runs->vendor($code);
        $run = $this->runs->start($vendor, self::TYPE);

        try {
            /** @var StockFeed $feed */
            $feed = $this->container->make("vendorsync.stock.{$code}");

            $this->apply($vendor, $feed->stockBySku(), $report);
            $report->finish(RunReport::SUCCEEDED);
        } catch (SourceAborted $exception) {
            $report->finish(RunReport::ABORTED, $exception->getMessage());
        } catch (Throwable $exception) {
            $report->finish(RunReport::FAILED, class_basename($exception).': '.$exception->getMessage());
        }

        $this->runs->finish($run, $report);

        return $report;
    }

    /**
     * @param  array<string, int>  $stockBySku  Vendor stock by vendor SKU.
     */
    public function apply(Vendor $vendor, array $stockBySku, RunReport $report): void
    {
        $buffer = (int) config("vendorsync.vendors.{$vendor->code}.stock_buffer", 0);
        $variants = (new ProductVariant)->getTable();

        $offers = DB::table('vendor_offers')
            ->join($variants, "{$variants}.id", '=', 'vendor_offers.product_variant_id')
            ->where('vendor_offers.vendor_id', $vendor->id)
            ->get([
                'vendor_offers.id as offer_id',
                'vendor_offers.vendor_sku',
                'vendor_offers.stock as offer_stock',
                'vendor_offers.sellable',
                "{$variants}.id as variant_id",
                "{$variants}.stock as variant_stock",
                "{$variants}.product_id",
            ]);

        $report->seen = count($stockBySku);

        $offerUpdates = [];
        $variantUpdates = [];
        $reindex = [];

        foreach ($offers as $offer) {
            $vendorStock = $stockBySku[(string) $offer->vendor_sku] ?? null;

            if ($vendorStock === null) {
                continue;
            }

            $variantStock = $offer->sellable ? max(0, $vendorStock - $buffer) : 0;

            if ((int) $offer->offer_stock !== $vendorStock) {
                $offerUpdates[(int) $offer->offer_id] = $vendorStock;
            }

            if ((int) $offer->variant_stock === $variantStock) {
                $report->unchanged++;

                continue;
            }

            $variantUpdates[(int) $offer->variant_id] = $variantStock;
            $report->updated++;

            // The index only knows whether a colour and size is in stock.
            if (((int) $offer->variant_stock > 0) !== ($variantStock > 0)) {
                $reindex[(int) $offer->product_id] = true;
            }
        }

        DB::transaction(function () use ($offerUpdates, $variantUpdates, $variants): void {
            $this->bulkUpdate('vendor_offers', $offerUpdates);
            $this->bulkUpdate($variants, $variantUpdates);
        });

        $report->productIds = array_keys($reindex);

        if ($reindex !== []) {
            Product::query()->whereIn('id', array_keys($reindex))->get()->searchable();
            $report->reindexedProductIds = array_keys($reindex);
        }
    }

    /**
     * One UPDATE per chunk: `SET stock = CASE id WHEN ? THEN ? ... END`.
     *
     * @param  array<int, int>  $stockById
     */
    private function bulkUpdate(string $table, array $stockById): void
    {
        foreach (array_chunk($stockById, self::CHUNK, preserve_keys: true) as $chunk) {
            $cases = '';
            $bindings = [];

            foreach ($chunk as $id => $stock) {
                $cases .= ' WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = $stock;
            }

            $ids = array_keys($chunk);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $wrapped = DB::getQueryGrammar()->wrapTable($table);

            DB::update(
                "UPDATE {$wrapped} SET stock = CASE id{$cases} END, updated_at = ? WHERE id IN ({$placeholders})",
                [...$bindings, now(), ...$ids],
            );
        }
    }
}
