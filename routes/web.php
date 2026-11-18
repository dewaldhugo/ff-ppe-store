<?php

declare(strict_types=1);

use App\Domain\Catalogue\Urls\CollectionPath;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BrandIndexController;
use App\Http\Middleware\RedirectTrailingSlash;
use App\Http\Middleware\ResolveBrandSlugs;
use App\Http\Middleware\ResolveCategoryPath;
use App\Http\Middleware\ResolveProductSlug;
use App\Livewire\Catalogue\CategoryListing;
use App\Livewire\Catalogue\ProductPage;
use Illuminate\Support\Facades\Route;

// Pages without content yet. Each renders the standard layout with a heading,
// "Content coming soon" and noindex.
$placeholders = [
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

    Route::get('/brands', BrandIndexController::class)->name('brands');

    // One slug or several joined by commas, sorted: /brands/amblers,safety-jogger.
    // The middleware resolves them (301 to the sorted form, 404 when unknown).
    Route::get('/brands/{brands}', CategoryListing::class)
        ->where('brands', '[A-Za-z0-9\-]+(,[A-Za-z0-9\-]+)*')
        ->middleware(ResolveBrandSlugs::class)
        ->name('brands.show');

    Route::view('/size-guide', 'pages.size-guide')->name('size-guide');
    Route::view('/technical-features', 'pages.technical-features')->name('technical-features');
    Route::view('/about', 'pages.about')->name('about');
    Route::view('/contact', 'pages.contact')->name('contact');

    // Customer accounts. Every route names the `web` guard: staff use the separate
    // `staff` guard (Lunar) and are never customers.
    Route::middleware('guest:web')->group(function (): void {
        Route::get('/register', [RegisterController::class, 'create'])->name('register');
        Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');

        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');

        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:password-email')
            ->name('password.email');

        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:password-reset')
            ->name('password.store');
    });

    // Open to everyone, so a double click or an expired session just lands on the home page.
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // My Account: signed-in customers only, with a verified email address while that is required.
    Route::middleware(['auth:web', 'account.verified'])->group(function (): void {
        Route::get('/account', [AccountController::class, 'show'])->name('account');
        Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
        Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
    });

    // Email verification. The names are the ones Laravel's notification and request classes look for.
    Route::middleware('auth:web')->group(function (): void {
        Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:verification-send')
            ->name('verification.send');
    });

    foreach ($placeholders as $name => [$uri, $heading]) {
        Route::view($uri, 'pages.placeholder', ['heading' => $heading])->name($name);
    }
});
