<?php

declare(strict_types=1);

namespace App\Admin\Resources\HeroContentResource\Pages;

use App\Admin\Resources\HeroContentResource;
use App\Domain\Catalogue\Home\HeroContents;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListHeroContents extends ListRecords
{
    protected static string $resource = HeroContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add hero content'),
            Action::make('importStandard')
                ->label('Add the standard headings')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Copies the home page\'s standard headings into this list, each with the standard sub-heading and a View all products button, so you can edit them. A heading that is already here is not added again.')
                ->action(function (): void {
                    $added = app(HeroContents::class)->importStandard();

                    Notification::make()->title($added === 0 ? 'The standard headings are already in the list.' : "Added {$added} sets.")->success()->send();
                }),
        ];
    }
}
