<?php

declare(strict_types=1);

namespace App\Admin\Resources;

use App\Admin\Resources\HeroContentResource\Pages\CreateHeroContent;
use App\Admin\Resources\HeroContentResource\Pages\EditHeroContent;
use App\Admin\Resources\HeroContentResource\Pages\ListHeroContents;
use App\Models\HeroContent;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Hero content in the admin: the sets of words for the home page hero (heading, sub-heading, and the first button's wording and
 * link). A visitor sees one active set, picked at random on every page load. The second button is fixed. For staff who manage
 * products (`catalog:manage-products`), and admins.
 */
final class HeroContentResource extends Resource
{
    protected static ?string $model = HeroContent::class;

    protected static ?string $slug = 'hero-content';

    protected static ?string $modelLabel = 'hero content';

    protected static ?string $pluralModelLabel = 'hero content';

    protected static ?int $navigationSort = 5;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-megaphone';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lunarpanel::global.sections.catalog');
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
        return $schema->components([
            TextInput::make('heading')
                ->label('Heading')
                ->required()
                ->maxLength(200)
                ->helperText('The large line at the top of the home page. Plain text.'),
            Textarea::make('sub_heading')
                ->label('Sub-heading')
                ->rows(2)
                ->maxLength(300)
                ->helperText('The line under the heading (not shown on a phone). Leave empty for none.'),
            TextInput::make('cta_text')
                ->label('Button text')
                ->required()
                ->maxLength(60)
                ->helperText('The wording of the first button, for example View all products.'),
            TextInput::make('cta_link')
                ->label('Button link')
                ->required()
                ->maxLength(500)
                ->placeholder('/shop/safety-footwear')
                ->helperText('A page of this shop, starting with a slash (/shop, /brands, /wear-trials), or a full https address.')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $link = trim((string) $value);
                    $isPath = str_starts_with($link, '/') && ! str_starts_with($link, '//') && ! preg_match('/\s/', $link);
                    $isHttps = str_starts_with($link, 'https://') && filter_var($link, FILTER_VALIDATE_URL) !== false;

                    if (! $isPath && ! $isHttps) {
                        $fail('Use a path of this shop such as /shop, or a full address starting https://.');
                    }
                }),
            TextInput::make('position')
                ->label('Position')
                ->numeric()
                ->minValue(0)
                ->default(fn (): int => (int) HeroContent::query()->max('position') + 1)
                ->helperText('Only for the order of this list. The site picks one at random.'),
            Toggle::make('active')
                ->label('In the random pick')
                ->default(true)
                ->helperText('Switch off to keep a set here without showing it.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('heading')->label('Heading')->weight('bold')->limit(60)->searchable(),
                TextColumn::make('sub_heading')->label('Sub-heading')->limit(50)->placeholder(''),
                TextColumn::make('cta_text')->label('Button'),
                TextColumn::make('cta_link')->label('Links to')->limit(40),
                ToggleColumn::make('active')->label('In the pick'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No hero content yet')
            ->emptyStateDescription('Until you add some, the home page uses its standard headings. Add the standard ones here to edit them, or add your own.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHeroContents::route('/'),
            'create' => CreateHeroContent::route('/create'),
            'edit' => EditHeroContent::route('/{record}/edit'),
        ];
    }
}
