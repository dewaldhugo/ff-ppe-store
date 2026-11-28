<?php

declare(strict_types=1);

namespace App\Admin\Resources\HomeVideoResource\Pages;

use App\Admin\Resources\HomeVideoResource;
use App\Models\HomeVideo;
use Filament\Resources\Pages\CreateRecord;

final class CreateHomeVideo extends CreateRecord
{
    protected static string $resource = HomeVideoResource::class;

    /** Only the field for the chosen source is kept, so a stale address or file is never saved beside the other. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data['source'] === HomeVideo::UPLOAD ? [...$data, 'url' => null] : [...$data, 'file' => null];
    }
}
