<?php

declare(strict_types=1);

namespace App\Providers;

use App\Admin\Extensions\CustomerAccessEditExtension;
use App\Admin\Extensions\CustomerAccessResourceExtension;
use App\Admin\Extensions\CustomerAccessViewExtension;
use App\Admin\Extensions\CustomerLogoEditExtension;
use App\Admin\Extensions\CustomerLogoResourceExtension;
use App\Admin\Extensions\CustomerSupplierNumberEditExtension;
use App\Admin\Extensions\CustomerSupplierNumberResourceExtension;
use App\Admin\Extensions\DashboardTopWidgetsExtension;
use App\Admin\Extensions\OrderDeleteResourceExtension;
use App\Admin\Extensions\OrderDeleteViewExtension;
use App\Admin\Extensions\OrderInvoicePdfViewExtension;
use App\Admin\Extensions\ProductVideosResourceExtension;
use App\Admin\Middleware\EnforceAdminAccess;
use App\Admin\Pages\FinancialReporting;
use App\Admin\Resources\HomeVideoResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Widgets\RecentOrdersWidget;
use App\Admin\Widgets\SalesTilesWidget;
use App\Domain\Accounts\RateLimits;
use App\Domain\Cart\Delivery\StoreShippingModifier;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Domain\Catalogue\Urls\CollectionPath;
use App\Domain\Catalogue\Urls\Redirects\CollectionRedirectObserver;
use App\Domain\Checkout\CardPayments;
use App\Domain\Checkout\StripeCardPayments;
use App\Domain\Compare\Compare;
use App\Domain\Favourites\Favourites;
use App\Domain\Orders\InvoiceNumbers;
use App\Domain\Orders\NotifyStaffOfNewOrder;
use App\Domain\Pricing\Vat\StoreTaxDriver;
use App\Models\ProductVideoLink;
use App\Support\Store;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Lunar\Admin\Filament\Pages\Dashboard;
use Lunar\Admin\Filament\Resources\CustomerResource;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\EditCustomer;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Base\ShippingModifiers;
use Lunar\Facades\Taxes;
use Lunar\Facades\Telemetry;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The storefront access toggles on the Customer screens (docs/accounts.md).
        LunarPanel::extensions([
            CustomerResource::class => [CustomerAccessResourceExtension::class, CustomerLogoResourceExtension::class, CustomerSupplierNumberResourceExtension::class],
            EditCustomer::class => [CustomerAccessEditExtension::class, CustomerLogoEditExtension::class, CustomerSupplierNumberEditExtension::class],
            ViewCustomer::class => CustomerAccessViewExtension::class,
            // Delete order, which Lunar does not have: on the orders list (row and bulk) and on the order page.
            OrderResource::class => OrderDeleteResourceExtension::class,
            // The order page's header: Delete order, and the Invoice PDF.
            ManageOrder::class => [OrderDeleteViewExtension::class, OrderInvoicePdfViewExtension::class],
            // A Product videos upload on the Edit Product screen (shown on the product page under the vendor's video).
            ProductResource::class => ProductVideosResourceExtension::class,
            // The sales tiles and the latest orders list at the top of the dashboard.
            Dashboard::class => DashboardTopWidgetsExtension::class,
        ]);

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
            // A top bar across the admin: the logo top left, the global search and the user menu top right. Lunar
            // switches it off and puts both in the sidebar; the navigation stays in the sidebar below the bar.
            ->topbar()
            // The dashboard's top widgets (DashboardTopWidgetsExtension puts them on the page). A widget has to be
            // registered with the panel as well, or Livewire cannot find it when the page loads it.
            ->widgets([SalesTilesWidget::class, RecentOrdersWidget::class])
            // Invoices, under Sales beside Orders: the list, with the Invoice PDF download.
            ->resources([InvoiceResource::class, HomeVideoResource::class])
            // Financial reporting, under Sales: a page for each year (revenue, gross profit, units, margin and the charts).
            ->pages([FinancialReporting::class])
            // Keeps staff out of the pages their permissions do not cover (Lunar only hides them from the menu).
            ->authMiddleware([EnforceAdminAccess::class])
            // The colours of the dashboard tiles. The rest of the admin keeps Lunar's own styling.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('admin.dashboard-tiles'))
            // The Taxes link, which Lunar shows to everyone, is hidden from a Reports user (the page itself is a 403 for them).
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('admin.reports-only-menu'))
            // The sign-in page's background: a photo under a dark overlay (resources/views/admin/login-background.blade.php).
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('admin.login-background')),
        )->register();

        $this->app->scoped(CatalogueKeys::class);
        $this->app->scoped(Favourites::class);
        $this->app->scoped(Compare::class);
        $this->app->bind(CardPayments::class, StripeCardPayments::class);
        $this->app->scoped(CollectionPath::class);
        $this->app->scoped(CollectionRedirectObserver::class);
    }

    public function boot(): void
    {
        Telemetry::optOut();

        // The videos staff add to a product by web address (the Product videos section of Edit Product, ProductVideoLink).
        Product::resolveRelationUsing('videoLinks', fn (Product $product): HasMany => $product->hasMany(ProductVideoLink::class)->orderBy('position')->orderBy('id'));

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

        // An order gets its invoice number the moment it is placed, whoever places it (the checkout,
        // the Stripe webhook, staff). A failure here must never stop an order being placed: the
        // number is then given the first time the invoice is looked at (InvoiceNumbers::for).
        Order::saved(function (Order $order): void {
            if ($order->placed_at === null) {
                return;
            }

            try {
                $numbers = app(InvoiceNumbers::class);

                if (! $numbers->has($order)) {
                    $numbers->assign($order);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        // Staff are emailed once, when an order is placed: this save is the one that gave it a placed
        // date (an account order at checkout, a card order when Stripe confirms the payment). It runs
        // after the invoice number is given, so the email carries it. A failure is reported and never
        // stops the order.
        Order::saved(function (Order $order): void {
            if ($order->placed_at === null || ! $order->wasChanged('placed_at') || $order->getOriginal('placed_at') !== null) {
                return;
            }

            try {
                app(NotifyStaffOfNewOrder::class)($order);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        // A category URL change (rename, move, new parent) records a redirect
        // from the old path. See CollectionRedirectObserver.
        foreach (['saved', 'deleted'] as $event) {
            Collection::{$event}(fn (Collection $collection) => app(CollectionRedirectObserver::class)->collectionChanged($collection));
            Url::{$event}(fn (Url $url) => app(CollectionRedirectObserver::class)->urlChanged($url));
        }
    }
}
