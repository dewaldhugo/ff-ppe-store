<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use Filament\Tables\Table;
use Lunar\Admin\Support\Extending\ResourceExtension;

/**
 * Delete order on each row of the orders list, and Delete selected as a bulk action.
 */
final class OrderDeleteResourceExtension extends ResourceExtension
{
    public function extendTable(Table $table): Table
    {
        return $table
            ->pushRecordActions([DeleteOrderAction::make()])
            ->pushToolbarActions([DeleteOrderAction::bulk()]);
    }
}
