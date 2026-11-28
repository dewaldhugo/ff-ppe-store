<?php

declare(strict_types=1);

namespace App\Admin\Resources\HeroContentResource\Pages;

use App\Admin\Resources\HeroContentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListHeroContents extends ListRecords
{
    protected static string $resource = HeroContentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add hero content')];
    }
}
