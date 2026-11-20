<?php

declare(strict_types=1);

namespace App\Providers;

use App\Admin\Extensions\CustomerAccessEditExtension;
use App\Admin\Extensions\CustomerAccessResourceExtension;
use App\Admin\Extensions\CustomerAccessViewExtension;
use App\Domain\Accounts\RateLimits;
use App\Domain\Cart\Delivery\StoreShippingModifier;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Domain\Catalogue\Urls\CollectionPath;
use App\Domain\Catalogue\Urls\Redirects\CollectionRedirectObserver;
use App\Domain\Checkout\CardPayments;
use App\Domain\Checkout\StripeCardPayments;
use App\Domain\Favourites\Favourites;
use App\Domain\Pricing\Vat\StoreTaxDriver;
use App\Support\Store;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Lunar\Admin\Filament\Resources\CustomerResource;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\EditCustomer;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Base\ShippingModifiers;
use Lunar\Facades\Taxes;
use Lunar\Facades\Telemetry;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Url;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The storefront access toggles on the Customer screens (docs/accounts.md).
        LunarPanel::extensions([
            CustomerResource::class => CustomerAccessResourceExtension::class,
            EditCustomer::class => CustomerAccessEditExtension::class,
            ViewCustomer::class => CustomerAccessViewExtension::class,
        ]);

        // A denser admin: root font size x .85 and tighter rows (resources/views/admin/density.blade.php).
        // The storefront's logo on a transparent background: the white file in dark mode, the
        // standard file in light mode (a white logo would vanish on the light sidebar).
        LunarPanel::panel(fn (Panel $panel): Panel => $panel
            ->path('admin')
            ->brandName(Store::name())
            ->brandLogo(asset('images/frontline-logo.png'))
            ->darkModeBrandLogo(asset('images/frontline-logo-white.png'))
            ->favicon(asset('favicon.png'))
            ->brandLogoHeight('1.8rem')
            ->maxContentWidth(Width::Full)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('admin.density')),
        )->register();

        $this->app->scoped(CatalogueKeys::class);
        $this->app->scoped(Favourites::class);
        $this->app->bind(CardPayments::class, StripeCardPayments::class);
        $this->app->scoped(CollectionPath::class);
        $this->app->scoped(CollectionRedirectObserver::class);
    }

    public function boot(): void
    {
        Telemetry::optOut();

        // VAT: the system driver plus the employer rate for zero-protective lines. Delivery: one
        // rule, offered on every cart. See docs/cart.md.
        // The closure is rebound to the tax manager, so it must not use $this.
        Taxes::extend('store', fn () => app(StoreTaxDriver::class));
        $this->app->make(ShippingModifiers::class)->add(StoreShippingModifier::class);

        // Customer accounts: named limiters for the account routes and the password rules
        // for register, reset and change password.
        RateLimits::register();
        Password::defaults(fn (): Password => Password::min(10)->letters()->numbers());

        // The storefront menu is cached. Any change to a collection, its URL
        // or the collection group clears it, whoever makes the change.
        foreach ([Collection::class, CollectionGroup::class, Url::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::{$event}(fn () => CategoryMenu::forget());
            }
        }

        // A category URL change (rename, move, new parent) records a redirect
        // from the old path. See CollectionRedirectObserver.
        foreach (['saved', 'deleted'] as $event) {
            Collection::{$event}(fn (Collection $collection) => app(CollectionRedirectObserver::class)->collectionChanged($collection));
            Url::{$event}(fn (Url $url) => app(CollectionRedirectObserver::class)->urlChanged($url));
        }
    }
}
