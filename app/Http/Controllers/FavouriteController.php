<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Favourites\Favourites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Lunar\Models\Product;

/**
 * Favourites for the signed-in customer (`auth:web` on both routes). The heart button posts here
 * with fetch and gets JSON; without JavaScript it is a plain form post that goes back to the page.
 */
final class FavouriteController extends Controller
{
    public function index(Favourites $favourites): View
    {
        return view('favourites.index', ['products' => $favourites->products()]);
    }

    public function toggle(Request $request, Favourites $favourites, int $productId): JsonResponse|RedirectResponse
    {
        $model = Product::query()->findOrFail($productId);

        $favourited = $favourites->toggle($model);

        if ($request->expectsJson()) {
            return response()->json(['favourited' => $favourited, 'count' => $favourites->count()]);
        }

        return back();
    }
}
