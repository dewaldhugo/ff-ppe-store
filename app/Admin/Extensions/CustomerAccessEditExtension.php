<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Accounts\SetCustomerAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Filament\Resources\CustomerResource;
use Lunar\Admin\Support\Extending\EditPageExtension;
use Lunar\Models\Customer;

/**
 * Loads the two storefront access toggles into the Edit Customer form and applies them on save.
 * They are not Customer attributes, so they are removed from the data before Lunar updates the
 * customer. Activating a login emails the customer (SetCustomerAccess).
 */
final class CustomerAccessEditExtension extends EditPageExtension
{
    public function headerActions(array $actions): array
    {
        return [
            ApproveCustomerAction::make()
                // The form's toggles were loaded before the approval: reload the page so they show it.
                ->successRedirectUrl(fn (?Model $record, mixed $livewire): ?string => ApproveCustomerAction::customer($record, $livewire) === null
                    ? null
                    : CustomerResource::getUrl('edit', ['record' => ApproveCustomerAction::customer($record, $livewire)])),
            ...$actions,
        ];
    }

    public function beforeFill(array $data): array
    {
        $record = $this->caller?->getRecord();

        if (! $record instanceof Customer) {
            return $data;
        }

        $users = $record->users()->get();

        $data[CustomerAccessResourceExtension::LOGIN_FIELD] = $users->isNotEmpty() && $users->every(fn (User $user): bool => $user->isApproved());
        $data[CustomerAccessResourceExtension::TERMS_FIELD] = $users->contains(fn (User $user): bool => $user->hasAccountTerms());

        return $data;
    }

    public function beforeUpdate(array $data, Model $record): array
    {
        $hasField = array_key_exists(CustomerAccessResourceExtension::LOGIN_FIELD, $data);
        $active = (bool) ($data[CustomerAccessResourceExtension::LOGIN_FIELD] ?? false);
        $terms = (bool) ($data[CustomerAccessResourceExtension::TERMS_FIELD] ?? false);

        unset($data[CustomerAccessResourceExtension::LOGIN_FIELD], $data[CustomerAccessResourceExtension::TERMS_FIELD]);

        // A customer with no login (made in the admin) has no section and nothing to apply.
        if ($hasField && $record instanceof Customer && $record->users()->exists()) {
            app(SetCustomerAccess::class)($record, $active, $terms);
        }

        return $data;
    }
}
