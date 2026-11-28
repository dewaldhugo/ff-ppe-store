<?php

declare(strict_types=1);

namespace App\Admin\Resources;

use App\Admin\Resources\HomeVideoResource\Pages\CreateHomeVideo;
use App\Admin\Resources\HomeVideoResource\Pages\EditHomeVideo;
use App\Admin\Resources\HomeVideoResource\Pages\ListHomeVideos;
use App\Admin\WebsiteContent;
use App\Domain\Catalogue\Home\HomeVideos;
use App\Domain\Catalogue\Media\VideoUploadLimit;
use App\Models\HomeVideo;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Home videos in the admin: the videos of the home page's "See how it works" section and of the Videos page. Each is a direct web
 * address of an MP4 or WebM file, or an uploaded file; the list shows every one with its source, its place in the order and whether
 * it is shown, and can be reordered by dragging. For staff who manage products (`catalog:manage-products`), and admins.
 */
final class HomeVideoResource extends Resource
{
    protected static ?string $model = HomeVideo::class;

    protected static ?string $slug = 'home-videos';

    protected static ?string $modelLabel = 'home video';

    protected static ?string $pluralModelLabel = 'home videos';

    protected static ?int $navigationSort = 6;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-play-circle';
    }

    public static function getNavigationGroup(): ?string
    {
        return WebsiteContent::GROUP;
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('catalog:manage-products') === true;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        $limit = new VideoUploadLimit;

        return $schema->components([
            TextInput::make('label')
                ->label('Title')
                ->required()
                ->maxLength(150)
                ->helperText('Names the video for people using a screen reader, and for you in this list.'),
            Textarea::make('caption')
                ->label('Caption')
                ->rows(2)
                ->maxLength(255)
                ->helperText('A line under the video. The title is used when this is empty.'),
            Radio::make('source')
                ->label('Where is the video?')
                ->options([HomeVideo::URL => 'A web address (a link to an MP4 or WebM file)', HomeVideo::UPLOAD => 'Upload a file'])
                ->default(HomeVideo::URL)
                ->live()
                ->required(),
            TextInput::make('url')
                ->label('Video address')
                ->url()
                ->maxLength(500)
                ->placeholder('https://example.com/videos/boots.mp4')
                ->visible(fn (Get $get): bool => $get('source') !== HomeVideo::UPLOAD)
                ->required(fn (Get $get): bool => $get('source') !== HomeVideo::UPLOAD)
                ->helperText('A direct link to an MP4 or WebM file, starting https://. YouTube and Vimeo pages are not files and will not play here.')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $path = strtolower((string) parse_url((string) $value, PHP_URL_PATH));
                    $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));

                    if ($scheme !== 'https' || ! preg_match('/\.(mp4|webm)$/', rtrim($path))) {
                        $fail('Use an https address that ends in .mp4 or .webm.');
                    }
                }),
            FileUpload::make('file')
                ->label('Video file')
                ->disk(HomeVideo::DISK)
                ->directory(HomeVideo::DIRECTORY)
                ->visibility('public')
                ->acceptedFileTypes(['video/mp4', 'video/webm'])
                ->maxSize($limit->kilobytes())
                ->validationMessages($limit->validationMessages())
                ->downloadable()
                ->visible(fn (Get $get): bool => $get('source') === HomeVideo::UPLOAD)
                ->required(fn (Get $get): bool => $get('source') === HomeVideo::UPLOAD)
                ->helperText('MP4 or WebM. '.$limit->helperText()),
            Select::make('link_route')
                ->label('Link under the video')
                ->options(HomeVideos::LINKS)
                ->placeholder('No link')
                ->helperText('A page of the shop to send people to next.'),
            TextInput::make('link_label')
                ->label('Link wording')
                ->maxLength(100)
                ->helperText('Optional. The page\'s usual wording is used when this is empty.'),
            TextInput::make('position')
                ->label('Position')
                ->numeric()
                ->minValue(0)
                ->default(fn (): int => (int) HomeVideo::query()->max('position') + 1)
                ->helperText('Videos show in this order. You can also drag them in the list.'),
            Toggle::make('active')
                ->label('Shown on the site')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('label')->label('Title')->weight('bold')->searchable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === HomeVideo::UPLOAD ? 'Uploaded file' : 'Web address')
                    ->color(fn (string $state): string => $state === HomeVideo::UPLOAD ? 'info' : 'gray'),
                TextColumn::make('address')
                    ->label('Address')
                    ->getStateUsing(fn (HomeVideo $video): ?string => $video->source === HomeVideo::UPLOAD ? $video->file : $video->url)
                    ->limit(50)
                    ->tooltip(fn (HomeVideo $video): ?string => $video->address()),
                TextColumn::make('caption')->label('Caption')->limit(40)->placeholder(''),
                TextColumn::make('position')->label('Order')->sortable(),
                ToggleColumn::make('active')->label('Shown'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No home videos yet')
            ->emptyStateDescription('Until you add some, the home page shows its standard videos. Add your own and they take over.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomeVideos::route('/'),
            'create' => CreateHomeVideo::route('/create'),
            'edit' => EditHomeVideo::route('/{record}/edit'),
        ];
    }
}
