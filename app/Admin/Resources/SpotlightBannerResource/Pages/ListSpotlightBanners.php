<?php

declare(strict_types=1);

namespace App\Admin\Resources\SpotlightBannerResource\Pages;

use App\Admin\Resources\SpotlightBannerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSpotlightBanners extends ListRecords
{
    protected static string $resource = SpotlightBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a banner')];
    }
}
