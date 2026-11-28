<?php

declare(strict_types=1);

namespace App\Admin\Resources\SpotlightBannerResource\Pages;

use App\Admin\Resources\SpotlightBannerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSpotlightBanner extends EditRecord
{
    protected static string $resource = SpotlightBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
