<?php

declare(strict_types=1);

namespace App\Admin\Resources;

use App\Admin\Resources\SpotlightBannerResource\Pages\CreateSpotlightBanner;
use App\Admin\Resources\SpotlightBannerResource\Pages\EditSpotlightBanner;
use App\Admin\Resources\SpotlightBannerResource\Pages\ListSpotlightBanners;
use App\Admin\WebsiteContent;
use App\Models\SpotlightBanner;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Lunar\Models\Product;

/**
 * Spotlight banners in the admin: the campaigns of the home page's Product Spotlight. Each is a banner picture and a published
 * product; a visitor sees one active campaign, picked at random on every page load. For staff who manage products
 * (`catalog:manage-products`), and admins.
 */
final class SpotlightBannerResource extends Resource
{
    protected static ?string $model = SpotlightBanner::class;

    protected static ?string $slug = 'spotlight-banners';

    protected static ?string $modelLabel = 'spotlight banner';

    protected static ?string $pluralModelLabel = 'spotlight banners';

    protected static ?int $navigationSort = 7;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-sparkles';
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

    private static function productName(Product $product): string
    {
        return (string) $product->translateAttribute('name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->label('Product')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Product::query()
                    ->where('status', 'published')
                    ->where('attribute_data->name->value->en', 'like', '%'.$search.'%')
                    ->orderBy('id')
                    ->limit(30)
                    ->get()
                    ->mapWithKeys(fn (Product $product): array => [$product->id => self::productName($product)])
                    ->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => ($product = Product::query()->find($value)) === null ? null : self::productName($product))
                ->helperText('Type part of the name. Only published products can be chosen. The spotlight shows the product\'s own brand, name, description, price and options beside the banner.'),
            FileUpload::make('image')
                ->label('Banner picture')
                ->required()
                ->image()
                ->disk(SpotlightBanner::DISK)
                ->directory(SpotlightBanner::DIRECTORY)
                ->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5120)
                ->downloadable()
                ->helperText('JPG, PNG or WebP, up to 5 MB. It is shown beside the product details, so a tall picture of about 1200 x 1500 pixels works best.'),
            TextInput::make('alt')
                ->label('Picture description')
                ->maxLength(255)
                ->helperText('For people using a screen reader. The product name is used when this is empty.'),
            TextInput::make('position')
                ->label('Position')
                ->numeric()
                ->minValue(0)
                ->default(fn (): int => (int) SpotlightBanner::query()->max('position') + 1)
                ->helperText('Only for the order of this list. The site picks one at random.'),
            Toggle::make('active')
                ->label('In the random pick')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                ImageColumn::make('image')->label('Banner')->disk(SpotlightBanner::DISK)->height(64),
                TextColumn::make('product')
                    ->label('Product')
                    ->weight('bold')
                    ->getStateUsing(fn (SpotlightBanner $banner): string => $banner->product === null ? '(product removed)' : self::productName($banner->product)),
                TextColumn::make('product.status')->label('Status')->badge()->color(fn (?string $state): string => $state === 'published' ? 'success' : 'warning'),
                TextColumn::make('position')->label('Order')->sortable(),
                ToggleColumn::make('active')->label('In the pick'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No spotlight banners yet')
            ->emptyStateDescription('Until you add some, the home page uses its standard spotlight campaigns. Add a banner and a product and they take over.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpotlightBanners::route('/'),
            'create' => CreateSpotlightBanner::route('/create'),
            'edit' => EditSpotlightBanner::route('/{record}/edit'),
        ];
    }
}
