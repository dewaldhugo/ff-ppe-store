<?php

declare(strict_types=1);

namespace App\Admin\Resources\HeroContentResource\Pages;

use App\Admin\Resources\HeroContentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditHeroContent extends EditRecord
{
    protected static string $resource = HeroContentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
