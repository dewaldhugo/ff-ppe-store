<?php

declare(strict_types=1);

namespace App\Admin\Resources\SpotlightBannerResource\Pages;

use App\Admin\Resources\SpotlightBannerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSpotlightBanner extends CreateRecord
{
    protected static string $resource = SpotlightBannerResource::class;
}
