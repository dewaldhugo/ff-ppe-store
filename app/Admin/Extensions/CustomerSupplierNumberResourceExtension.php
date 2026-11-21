<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\ResourceExtension;
use Lunar\Models\Customer;

/**
 * A "Supplier number" box on the Edit Customer screen: the number this customer uses for the store in
 * their own purchasing system. It is filled in on their checkout, can be changed there, and is printed
 * on their invoices (docs/accounts.md, Invoices). Like the logo it is not a Customer column:
 * CustomerSupplierNumberEditExtension loads it from, and saves it to, the customer's meta.
 */
final class CustomerSupplierNumberResourceExtension extends ResourceExtension
{
    public const string FIELD = 'storefront_supplier_number';

    public const string META_KEY = 'supplier_number';

    public function extendForm(Schema $schema): Schema
    {
        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            Section::make('Invoice details')
                ->description('Printed on this customer\'s invoices and filled in on their checkout.')
                ->columnSpanFull()
                // Edit page only, and only for a customer who has a login to check out with.
                ->visible(fn (?Model $record, mixed $livewire): bool => $livewire instanceof EditRecord && $record instanceof Customer && $record->users()->exists())
                ->schema([
                    TextInput::make(self::FIELD)
                        ->label('Supplier number')
                        ->helperText('The number this customer uses for us in their purchasing system. Leave empty if they have none.')
                        ->maxLength(50),
                ]),
        ]);
    }
}
