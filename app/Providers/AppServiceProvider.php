<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Facades\Telemetry;

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
    }
}
