<?php

declare(strict_types=1);

namespace App\Admin\Resources\HeroContentResource\Pages;

use App\Admin\Resources\HeroContentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateHeroContent extends CreateRecord
{
    protected static string $resource = HeroContentResource::class;
}
