<?php

declare(strict_types=1);

use App\Livewire\Catalogue\CategoryListing;
use App\Livewire\Catalogue\ProductPage;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
Route::view('/products', 'pages.products')->name('products');

Route::get('/c/{slug}', CategoryListing::class)->name('category');
Route::get('/search', CategoryListing::class)->name('search');
Route::get('/p/{slug}', ProductPage::class)->name('product');

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

foreach ($placeholders as $name => [$uri, $heading]) {
    Route::view($uri, 'pages.placeholder', ['heading' => $heading])->name($name);
}
