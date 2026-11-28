<?php

declare(strict_types=1);

namespace App\Admin\Resources\SpotlightBannerResource\Pages;

use App\Admin\Resources\SpotlightBannerResource;
use App\Domain\Catalogue\Home\HomeSpotlight;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListSpotlightBanners extends ListRecords
{
    protected static string $resource = SpotlightBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a banner'),
            Action::make('importStandard')
                ->label('Add the standard banners')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Copies the home page\'s standard spotlight campaigns (the banner and its product) into this list so you can edit, reorder or switch them off. A product that already has a banner here is not added again.')
                ->action(function (): void {
                    $added = app(HomeSpotlight::class)->importStandard();

                    Notification::make()->title($added === 0 ? 'The standard banners are already in the list, or their products are not published.' : "Added {$added} banners.")->success()->send();
                }),
        ];
    }
}
