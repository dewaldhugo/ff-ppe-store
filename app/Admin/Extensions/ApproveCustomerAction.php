<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Accounts\SetCustomerAccess;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Customer;

/**
 * The "Approve" button for new registrations, on the customers list (row and bulk) and on the
 * Edit and View Customer pages. It activates every login on the customer through
 * SetCustomerAccess, which also emails them the login details. A customer's 30 day terms are
 * left as they are. Shown only for a customer with at least one login still waiting.
 */
final class ApproveCustomerAction
{
    public static function make(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this customer?')
            ->modalDescription('Their login is activated and they are emailed the login details.')
            ->modalSubmitActionLabel('Approve and email')
            ->visible(fn (?Model $record, mixed $livewire): bool => ($customer = self::customer($record, $livewire)) !== null && self::isPending($customer))
            ->action(function (?Model $record, mixed $livewire): void {
                self::approve(self::customer($record, $livewire) ?? throw new \LogicException('No customer to approve.'));

                Notification::make()->title('Customer approved and emailed')->success()->send();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('approve')
            ->label('Approve selected')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve the selected customers?')
            ->modalDescription('Each customer still waiting is activated and emailed their login details.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $approved = 0;

                foreach ($records as $record) {
                    if ($record instanceof Customer && self::isPending($record)) {
                        self::approve($record);
                        $approved++;
                    }
                }

                Notification::make()->title($approved.' '.($approved === 1 ? 'customer' : 'customers').' approved and emailed')->success()->send();
            });
    }

    /**
     * The customer the action runs on: the table row's record, or the record of the page it sits on
     * (page header actions are not always handed the record).
     */
    public static function customer(?Model $record, mixed $livewire): ?Customer
    {
        $record ??= is_object($livewire) && method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return $record instanceof Customer ? $record : null;
    }

    public static function isPending(Customer $customer): bool
    {
        return $customer->users()->whereNull('approved_at')->exists();
    }

    private static function approve(Customer $customer): void
    {
        $terms = $customer->users()->get()->contains(fn (User $user): bool => $user->hasAccountTerms());

        app(SetCustomerAccess::class)($customer, true, $terms);
    }
}
