<?php

declare(strict_types=1);

namespace App\Domain\VendorSync;

use App\Domain\VendorSync\Console\CatalogueCommand;
use App\Domain\VendorSync\Console\ImagesCommand;
use App\Domain\VendorSync\Console\StatusCommand;
use App\Domain\VendorSync\Console\StockCommand;
use App\Domain\VendorSync\Footsure\FootsureMapper;
use App\Domain\VendorSync\Footsure\FootsureSource;
use App\Domain\VendorSync\Images\ImageDownloader;
use App\Domain\VendorSync\Normalisers\Features;
use App\Domain\VendorSync\Normalisers\PriceCalculator;
use App\Domain\VendorSync\SafetyJogger\ApiClient;
use App\Domain\VendorSync\SafetyJogger\SafetyJoggerMapper;
use App\Domain\VendorSync\SafetyJogger\SafetyJoggerSource;
use App\Domain\VendorSync\SafetyJogger\SafetyJoggerStockFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the ingestion module: one source and one mapper per vendor code,
 * the artisan commands and the schedule.
 *
 * Adding a vendor means binding `vendorsync.source.{CODE}` and
 * `vendorsync.mapper.{CODE}` here. The writer is not touched.
 */
final class VendorSyncServiceProvider extends ServiceProvider
{
    private const string TIMEZONE = 'Europe/London';

    public function register(): void
    {
        $this->app->bind(Features::class, fn (): Features => new Features(config('vendorsync.features')));
        $this->app->bind(PriceCalculator::class, fn (): PriceCalculator => new PriceCalculator((string) config('vendorsync.markup')));

        $this->app->bind(ApiClient::class, fn (Application $app): ApiClient => new ApiClient(
            $app->make(Http::class),
            $app->make('cache.store'),
            config('vendorsync.safety_jogger'),
        ));

        $this->app->bind(ImageDownloader::class, fn (Application $app): ImageDownloader => new ImageDownloader(
            $app->make(Http::class),
            $app->make('cache.store'),
            config('vendorsync.images'),
        ));

        // Safety Jogger
        $this->app->bind('vendorsync.source.SJ', fn (Application $app): SafetyJoggerSource => new SafetyJoggerSource(
            $app->make(ApiClient::class),
            config('vendorsync.safety_jogger'),
        ));
        $this->app->bind('vendorsync.mapper.SJ', fn (Application $app): SafetyJoggerMapper => new SafetyJoggerMapper(
            config('vendorsync.safety_jogger'),
            config('vendorsync.size_charts.SJ'),
            $app->make(Features::class),
            $app->make(PriceCalculator::class),
        ));
        $this->app->bind('vendorsync.stock.SJ', fn (Application $app): SafetyJoggerStockFeed => new SafetyJoggerStockFeed(
            $app->make(ApiClient::class),
        ));

        // Footsure
        $this->app->bind('vendorsync.source.FS', fn (): FootsureSource => new FootsureSource(config('vendorsync.footsure')));
        $this->app->bind('vendorsync.mapper.FS', fn (Application $app): FootsureMapper => new FootsureMapper(
            config('vendorsync.footsure'),
            $app->make(Features::class),
            $app->make(PriceCalculator::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CatalogueCommand::class,
                StockCommand::class,
                ImagesCommand::class,
                StatusCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->schedule($schedule);
        });
    }

    /**
     * Only registers when VENDORSYNC_SCHEDULE_ENABLED=true, so a developer
     * machine never polls the production API on its own. Each catalogue
     * command queues its image jobs when it finishes.
     */
    private function schedule(Schedule $schedule): void
    {
        if (! config('vendorsync.schedule_enabled')) {
            return;
        }

        $schedule->command('vendorsync:catalogue SJ')
            ->dailyAt('02:15')->timezone(self::TIMEZONE)->withoutOverlapping()->onOneServer();

        $schedule->command('vendorsync:stock SJ')
            ->everyFifteenMinutes()->timezone(self::TIMEZONE)->withoutOverlapping()->onOneServer();

        // The Footsure export is created around 06:05.
        $schedule->command('vendorsync:catalogue FS')
            ->dailyAt('07:20')->timezone(self::TIMEZONE)->withoutOverlapping()->onOneServer();

        // Weekly full image refresh.
        $schedule->command('vendorsync:images SJ --refresh')
            ->weeklyOn(0, '03:30')->timezone(self::TIMEZONE)->withoutOverlapping()->onOneServer();

        $schedule->command('vendorsync:images FS --refresh')
            ->weeklyOn(0, '08:30')->timezone(self::TIMEZONE)->withoutOverlapping()->onOneServer();
    }
}
