<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounts\RateLimits;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Domain\Catalogue\Urls\CollectionPath;
use App\Domain\Catalogue\Urls\Redirects\CollectionRedirectObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Facades\Telemetry;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Url;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        LunarPanel::register();

        $this->app->scoped(CatalogueKeys::class);
        $this->app->scoped(CollectionPath::class);
        $this->app->scoped(CollectionRedirectObserver::class);
    }

    public function boot(): void
    {
        Telemetry::optOut();

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
