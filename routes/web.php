<?php

declare(strict_types=1);

use App\Domain\Catalogue\Urls\CollectionPath;
use App\Http\Middleware\RedirectTrailingSlash;
use App\Http\Middleware\ResolveCategoryPath;
use App\Http\Middleware\ResolveProductSlug;
use App\Livewire\Catalogue\CategoryListing;
use App\Livewire\Catalogue\ProductPage;
use Illuminate\Support\Facades\Route;

// Pages without content yet. Each renders the standard layout with a heading,
// "Content coming soon" and noindex.
$placeholders = [
    'about' => ['/about', 'About us'],
    'contact' => ['/contact', 'Contact us'],
    'account' => ['/account', 'Account'],
    'cart' => ['/cart', 'Cart'],
    'checkout' => ['/checkout', 'Checkout'],
    'terms' => ['/terms', 'Terms and Conditions of Sale'],
    'privacy' => ['/privacy', 'Privacy Policy'],
    'cookies' => ['/cookies', 'Cookie Policy'],
    'returns' => ['/returns', 'Returns and Refunds'],
    'delivery' => ['/delivery', 'Delivery Information'],
    'accessibility' => ['/accessibility', 'Accessibility Statement'],
];

// Only the storefront gets the trailing slash redirect.
Route::middleware(RedirectTrailingSlash::class)->group(function () use ($placeholders): void {
    Route::view('/', 'home')->name('home');

    Route::view(CollectionPath::index(), 'pages.shop')->name('shop');

    // /products/{slug} now exists, so a visitor trimming a product URL lands here.
    Route::permanentRedirect('/products', CollectionPath::index());

    // The path is the collection's ancestors' URL slugs plus its own. The
    // middleware resolves it and verifies the ancestry before the page mounts.
    Route::get(CollectionPath::index().'/{path}', CategoryListing::class)
        ->where('path', '.+')
        ->middleware(ResolveCategoryPath::class)
        ->name('category');

    Route::get('/products/{slug}', ProductPage::class)
        ->middleware(ResolveProductSlug::class)
        ->name('product');

    Route::get('/search', CategoryListing::class)->name('search');

    Route::view('/size-guide', 'pages.size-guide')->name('size-guide');
    Route::view('/technical-features', 'pages.technical-features')->name('technical-features');

    foreach ($placeholders as $name => [$uri, $heading]) {
        Route::view($uri, 'pages.placeholder', ['heading' => $heading])->name($name);
    }
});
