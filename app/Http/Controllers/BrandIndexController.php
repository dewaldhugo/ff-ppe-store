<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalogue\Brands\BrandDirectory;
use Illuminate\Contracts\View\View;

/**
 * /brands: every brand with products, as logo tiles. Customers pick one or
 * more and go to /brands/{slugs}.
 */
final class BrandIndexController extends Controller
{
    public function __invoke(BrandDirectory $directory): View
    {
        return view('pages.brands', ['tiles' => $directory->withProducts()]);
    }
}
