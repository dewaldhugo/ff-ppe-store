<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Admin\Widgets\RecentOrdersWidget;
use App\Admin\Widgets\SalesTilesWidget;
use Lunar\Admin\Support\Extending\BaseExtension;

/**
 * The sales tiles and the latest orders list, above Lunar's own dashboard widgets (which stay as they were).
 */
final class DashboardTopWidgetsExtension extends BaseExtension
{
    /**
     * @param  array<int, class-string>  $widgets
     * @return array<int, class-string>
     */
    public function getWidgets(array $widgets): array
    {
        return [SalesTilesWidget::class, RecentOrdersWidget::class, ...$widgets];
    }
}
