<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Catalogue\Media\ProductMediaDefinitions;
use App\Domain\Catalogue\Media\VideoLink;
use App\Domain\Catalogue\Media\VideoUploadLimit;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\ResourceExtension;
use Lunar\Models\Product;

/**
 * A "Product videos" section on the Edit Product screen: an upload and a list of video links. The files are stored in the
 * product's media collection `videos` and the product page shows them under the vendor's own video (the `video_url`
 * attribute), in the order they are listed here. The links (YouTube, Vimeo or a direct MP4 or WebM address, checked by
 * VideoLink) are kept in `product_video_links` and shown after the files. Both save with the rest of the form (the media
 * library, and the `videoLinks` relationship), so there is nothing to load or save by hand.
 */
final class ProductVideosResourceExtension extends ResourceExtension
{
    public function extendForm(Schema $schema): Schema
    {
        // The largest video this server really takes (VideoUploadLimit): the store's 200 MB or what PHP allows, if less.
        $limit = new VideoUploadLimit;

        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            Section::make('Product videos')
                ->description('Shown on the product page under the vendor\'s own video, in this order. Upload MP4 or WebM files or add a video by its web address.')
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
                        // A file over the limit is turned away at once, with the limit named, instead of failing part way.
                        ->maxSize($limit->kilobytes())
                        ->validationMessages($limit->validationMessages())
                        ->helperText('Drag to change the order. Remove one to take it off the product page. '.$limit->helperText()),
                    Repeater::make('videoLinks')
                        ->label('Video links')
                        ->relationship('videoLinks')
                        ->orderColumn('position')
                        ->reorderable()
                        ->addActionLabel('Add a video link')
                        ->defaultItems(0)
                        ->helperText('A YouTube or Vimeo address, or a direct link to an MP4 or WebM file. Shown after the uploaded videos, in this order.')
                        ->schema([
                            TextInput::make('url')
                                ->label('Video address')
                                ->url()
                                ->required()
                                ->maxLength(500)
                                ->placeholder('https://www.youtube.com/watch?v=...')
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (VideoLink::parse((string) $value) === null) {
                                        $fail('Use an https address for a YouTube or Vimeo video, or a direct link to an MP4 or WebM file.');
                                    }
                                }),
                        ]),
                ]),
        ]);
    }
}
