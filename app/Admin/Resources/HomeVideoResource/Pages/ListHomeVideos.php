<?php

declare(strict_types=1);

namespace App\Admin\Resources\HomeVideoResource\Pages;

use App\Admin\Resources\HomeVideoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListHomeVideos extends ListRecords
{
    protected static string $resource = HomeVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a video')];
    }
}
