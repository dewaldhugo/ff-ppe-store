<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Models\User;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\ResourceExtension;
use Lunar\Models\Customer;

/**
 * Adds the storefront access controls to Lunar's Customer screens (docs/accounts.md):
 * "Login active" (a new registration is off until staff switch it on) and "30 day account
 * terms" (off for every new customer). The fields are not Customer columns: they are read from
 * and saved to the customer's logins by CustomerAccessEditExtension and SetCustomerAccess.
 */
final class CustomerAccessResourceExtension extends ResourceExtension
{
    public const string LOGIN_FIELD = 'storefront_login_active';

    public const string TERMS_FIELD = 'storefront_account_terms';

    public function extendForm(Schema $schema): Schema
    {
        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            Section::make('Storefront access')
                ->description('Controls whether this customer can sign in to the shop and how they can pay.')
                ->columnSpanFull()
                // Edit page only: the read-only view page is not filled by the edit extension.
                ->visible(fn (?Model $record, mixed $livewire): bool => $livewire instanceof EditRecord && $record instanceof Customer && $record->users()->exists())
                ->schema([
                    Toggle::make(self::LOGIN_FIELD)
                        ->label('Login active')
                        ->helperText('New registrations start switched off. Switching this on emails the customer their login details.'),
                    Toggle::make(self::TERMS_FIELD)
                        ->label('30 day account terms')
                        ->helperText('Off by default: new customers can only pay by card. Switch on to also offer "30 day account" at checkout.'),
                ]),
        ]);
    }

    public function extendTable(Table $table): Table
    {
        return $table
            ->pushColumns([
                IconColumn::make('storefront_login')
                    ->label('Login')
                    ->boolean()
                    ->state(fn (Customer $record): bool => $record->users->isNotEmpty() && $record->users->every(fn (User $user): bool => $user->isApproved())),
                IconColumn::make('storefront_terms')
                    ->label('30 day terms')
                    ->boolean()
                    ->state(fn (Customer $record): bool => $record->users->contains(fn (User $user): bool => $user->hasAccountTerms())),
            ])
            ->pushRecordActions([ApproveCustomerAction::make()])
            ->pushToolbarActions([ApproveCustomerAction::bulk()])
            ->pushFilters([
                Filter::make('awaiting_activation')
                    ->label('Awaiting activation')
                    ->query(fn (Builder $query): Builder => $query->whereHas('users', fn (Builder $users): Builder => $users->whereNull('approved_at'))),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('users'));
    }
}
