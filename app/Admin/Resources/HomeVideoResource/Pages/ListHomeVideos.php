<?php

declare(strict_types=1);

namespace App\Admin\Resources\HomeVideoResource\Pages;

use App\Admin\Resources\HomeVideoResource;
use App\Domain\Catalogue\Home\HomeVideos;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListHomeVideos extends ListRecords
{
    protected static string $resource = HomeVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a video'),
            Action::make('importStandard')
                ->label('Add the standard videos')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Copies the nine standard videos of the home page into this list so you can edit, reorder or hide them. A video that is already here is not added again.')
                ->action(function (): void {
                    $added = app(HomeVideos::class)->importStandard();

                    Notification::make()->title($added === 0 ? 'The standard videos are already in the list.' : "Added {$added} videos.")->success()->send();
                }),
        ];
    }
}
