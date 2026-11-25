<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Catalogue\Media\ProductMediaDefinitions;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\ResourceExtension;
use Lunar\Models\Product;

/**
 * A "Product videos" upload on the Edit Product screen. The files are stored in the product's media collection `videos` and
 * the product page shows them under the vendor's own video (the `video_url` attribute), in the order they are listed here.
 * The upload saves with the rest of the form, through the media library, so there is nothing to load or save by hand.
 */
final class ProductVideosResourceExtension extends ResourceExtension
{
    /** The largest video, in kilobytes (200 MB). The server's own upload limits (PHP, the web server) must allow it too. */
    public const int MAX_KILOBYTES = 204800;

    public function extendForm(Schema $schema): Schema
    {
        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            Section::make('Product videos')
                ->description('Shown on the product page under the vendor\'s own video, in this order. MP4 or WebM, up to 200 MB each.')
                ->columnSpanFull()
                ->collapsible()
                // Edit page only: a new product has no record to attach a file to yet.
                ->visible(fn (?Model $record, mixed $livewire): bool => $livewire instanceof EditRecord && $record instanceof Product)
                ->schema([
                    SpatieMediaLibraryFileUpload::make('videos')
                        ->label('Videos')
                        ->collection(ProductMediaDefinitions::VIDEOS)
                        ->disk(ProductMediaDefinitions::DISK)
                        ->visibility('public')
                        ->multiple()
                        ->reorderable()
                        ->downloadable()
                        ->acceptedFileTypes(['video/mp4', 'video/webm'])
                        ->maxSize(self::MAX_KILOBYTES)
                        ->helperText('Drag to change the order. Remove one to take it off the product page.'),
                ]),
        ]);
    }
}
