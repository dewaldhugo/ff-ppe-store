<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Accounts\CustomerLogo;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\ResourceExtension;
use Lunar\Models\Customer;

/**
 * A "Company logo" upload on the Edit Customer screen. Staff set it; the customer sees it on My
 * Account (docs/accounts.md). Like the storefront access toggles it is not a Customer column:
 * CustomerLogoEditExtension loads it from, and saves it to, the customer's meta (CustomerLogo).
 */
final class CustomerLogoResourceExtension extends ResourceExtension
{
    public const string FIELD = 'storefront_logo';

    public function extendForm(Schema $schema): Schema
    {
        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            Section::make('Company logo')
                ->description('Shown to this customer on their My Account page. PNG, JPG or WebP, up to 2 MB.')
                ->columnSpanFull()
                // Edit page only, and only for a customer who has a login to see it.
                ->visible(fn (?Model $record, mixed $livewire): bool => $livewire instanceof EditRecord && $record instanceof Customer && $record->users()->exists())
                ->schema([
                    FileUpload::make(self::FIELD)
                        ->label('Logo')
                        ->disk(CustomerLogo::DISK)
                        ->directory(CustomerLogo::DIRECTORY)
                        ->visibility('public')
                        // image() sets the accepted types to image/*, which includes SVG, so it must come BEFORE
                        // the explicit list: the list replaces it. SVG is left out on purpose: opened on its own,
                        // an SVG file can run scripts.
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(2048)
                        ->imagePreviewHeight('96')
                        ->helperText('Remove it to show no logo.'),
                ]),
        ]);
    }
}
