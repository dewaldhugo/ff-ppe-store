<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalogue\Search\CatalogueSearch;
use App\Domain\Catalogue\Search\ListingQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lunar\Models\Product;
use Throwable;

/**
 * Type-ahead for the site search box: the first few products for what has been typed, as JSON. Listings are only ever queried
 * through CatalogueSearch, so this is the same search as /search with a small page. Two letters at least, and rate limited by the
 * route. When the index cannot be reached the answer is an empty list (and the error is reported), so typing never breaks the page.
 */
final class SearchSuggestController extends Controller
{
    private const int LIMIT = 6;

    public function __invoke(Request $request, CatalogueSearch $search): JsonResponse
    {
        $term = trim(mb_substr((string) $request->query('q', ''), 0, 80));

        if (mb_strlen($term) < 2) {
            return response()->json(['products' => [], 'all' => null]);
        }

        try {
            $result = $search->search(new ListingQuery(term: $term, perPage: self::LIMIT));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['products' => [], 'all' => null]);
        }

        $products = Product::query()
            ->whereIn('id', $result->productIds)
            ->where('status', 'published')
            ->with(['brand', 'defaultUrl', 'thumbnail'])
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($result->productIds as $id) {
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            $rows[] = [
                'name' => (string) $product->translateAttribute('name'),
                'brand' => $product->brand?->name,
                'url' => route('product', $product->defaultUrl?->slug ?? $product->id),
                'image' => $product->thumbnail?->getUrl('thumb'),
            ];
        }

        return response()->json([
            'products' => $rows,
            'all' => ['url' => route('search', ['q' => $term]), 'total' => $result->total],
        ]);
    }
}
