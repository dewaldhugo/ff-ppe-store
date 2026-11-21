<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Orders\DeleteOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Models\Order;

/**
 * The "Delete order" button: on each row of the orders list, as a bulk action, and in the header of the
 * order page. Lunar has none. It asks first, says what is lost (DeleteOrder::warning), and is for admin
 * staff only: it is hidden from anyone else and refused if they reach it anyway. The deleting itself is
 * App\Domain\Orders\DeleteOrder.
 */
final class DeleteOrderAction
{
    public static function make(): Action
    {
        return Action::make('delete_order')
            ->label('Delete order')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (?Model $record, mixed $livewire): string => 'Delete order '.(self::order($record, $livewire)?->reference ?? '').'?')
            ->modalDescription(fn (?Model $record, mixed $livewire): string => ($order = self::order($record, $livewire)) === null ? '' : app(DeleteOrder::class)->warning($order))
            ->modalSubmitActionLabel('Delete permanently')
            ->visible(fn (?Model $record, mixed $livewire): bool => self::mayDelete() && self::order($record, $livewire) !== null)
            ->action(function (?Model $record, mixed $livewire): void {
                if (! self::mayDelete()) {
                    self::refuse();

                    return;
                }

                $order = self::order($record, $livewire) ?? throw new \LogicException('No order to delete.');
                $reference = $order->reference;

                app(DeleteOrder::class)($order);

                Notification::make()->title('Order '.$reference.' deleted')->success()->send();

                // On the order's own page there is nothing left to show: go back to the list.
                if ($livewire instanceof ViewRecord) {
                    $livewire->redirect(OrderResource::getUrl('index', panel: 'lunar'));
                }
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('delete_orders')
            ->label('Delete selected')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete the selected orders?')
            ->modalDescription(function (): string {
                return 'Each selected order is permanently deleted with its lines, addresses and payment records. This cannot be undone. Placed orders keep their invoice numbers on record, and a card payment already taken is not refunded: refund it in Stripe first if the customer is owed it.';
            })
            ->modalSubmitActionLabel('Delete permanently')
            ->visible(fn (): bool => self::mayDelete())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                if (! self::mayDelete()) {
                    self::refuse();

                    return;
                }

                $deleted = 0;

                foreach ($records as $record) {
                    if ($record instanceof Order) {
                        app(DeleteOrder::class)($record);
                        $deleted++;
                    }
                }

                Notification::make()->title($deleted.' '.($deleted === 1 ? 'order' : 'orders').' deleted')->success()->send();
            });
    }

    /** Only admin staff may delete an order: a staff member with limited access cannot. */
    public static function mayDelete(): bool
    {
        return auth('staff')->user()?->admin === true;
    }

    /**
     * The order the action runs on: the table row's record, or the record of the page it sits on
     * (page header actions are not always handed the record).
     */
    public static function order(?Model $record, mixed $livewire): ?Order
    {
        $record ??= is_object($livewire) && method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return $record instanceof Order ? $record : null;
    }

    private static function refuse(): void
    {
        Notification::make()->title('Only an admin can delete orders')->danger()->send();
    }
}
