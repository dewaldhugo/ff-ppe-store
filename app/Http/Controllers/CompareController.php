<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Compare\Compare;
use App\Domain\Compare\CompareTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Lunar\Models\Product;

/**
 * Compare up to four products side by side. Open to everyone (the choice is in the session). The tick on a card posts to toggle()
 * with fetch and gets JSON; without JavaScript it is a plain form post that goes back to the page. A shared link carries the
 * products in the address (/compare?p=12,45,78) and shows those instead of the session's.
 */
final class CompareController extends Controller
{
    public function show(Request $request, Compare $compare, CompareTable $table): View
    {
        $ids = $request->filled('p')
            ? array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('p')))))), 0, Compare::MAX)
            : $compare->ids();

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('status', 'published')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices', 'variants.values.option'])
            ->get()
            ->sortBy(fn (Product $product): int => (int) array_search($product->id, $ids, true))
            ->values();

        return view('compare.show', [
            'products' => $products,
            'rows' => $products->isEmpty() ? [] : $table->rows($products),
            'shareUrl' => route('compare', ['p' => $products->pluck('id')->implode(',')]),
        ]);
    }

    public function toggle(Request $request, Compare $compare, int $productId): JsonResponse|RedirectResponse
    {
        $product = Product::query()->where('status', 'published')->findOrFail($productId);

        $result = $compare->toggle($product->id);

        if ($request->expectsJson()) {
            return response()->json([...$result, 'count' => $compare->count(), 'ids' => $compare->ids(), 'max' => Compare::MAX]);
        }

        $back = back();

        return $result['full'] ? $back->with('status', 'You can compare up to '.Compare::MAX.' products at once. Remove one first.') : $back;
    }

    public function clear(Request $request, Compare $compare): JsonResponse|RedirectResponse
    {
        $compare->clear();

        return $request->expectsJson() ? response()->json(['count' => 0, 'ids' => []]) : back();
    }
}
