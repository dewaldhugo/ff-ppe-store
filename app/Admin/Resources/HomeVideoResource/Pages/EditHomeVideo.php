<?php

declare(strict_types=1);

namespace App\Admin\Resources\HomeVideoResource\Pages;

use App\Admin\Resources\HomeVideoResource;
use App\Models\HomeVideo;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditHomeVideo extends EditRecord
{
    protected static string $resource = HomeVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** Only the field for the chosen source is kept (and a file the person switched away from is deleted by the model). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data['source'] === HomeVideo::UPLOAD ? [...$data, 'url' => null] : [...$data, 'file' => null];
    }
}
