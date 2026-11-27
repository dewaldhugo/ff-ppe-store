<?php

declare(strict_types=1);

namespace App\Admin;

use Filament\Facades\Filament;

/**
 * Who sees the money pages of the admin: Invoices and Financial reporting. The staff who manage orders (`sales:manage-orders`,
 * Lunar's own permission) do, as they always have, and so do staff with `reports:view`, a permission of the store's own (the
 * Reports role gives it, migration create_reports_role_and_permission). That one shows the dashboard, Invoices and Financial
 * reporting and nothing else, so someone who only needs the figures is not given the orders, customers or products too. An admin
 * has every permission.
 */
final class ReportsAccess
{
    public const string PERMISSION = 'reports:view';

    public const string ROLE = 'reports';

    /** May open Invoices and Financial reporting. */
    public static function allowed(): bool
    {
        $user = Filament::auth()->user();

        return $user?->can(self::PERMISSION) === true || $user?->can('sales:manage-orders') === true;
    }

    /** May open an order from a row of a list (a Reports user may not: the orders area is not theirs). */
    public static function canOpenOrders(): bool
    {
        return Filament::auth()->user()?->can('sales:manage-orders') === true;
    }
}
