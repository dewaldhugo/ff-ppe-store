<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Menu\CategoryMenu;
use Illuminate\Support\ServiceProvider;
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
    }

    public function boot(): void
    {
        Telemetry::optOut();

        // The storefront menu is cached. Any change to a collection, its URL
        // or the collection group clears it, whoever makes the change.
        foreach ([Collection::class, CollectionGroup::class, Url::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::{$event}(fn () => CategoryMenu::forget());
            }
        }
    }
}
