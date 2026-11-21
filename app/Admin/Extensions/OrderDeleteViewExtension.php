<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use Lunar\Admin\Support\Extending\ViewPageExtension;

/**
 * The Delete order button in the header of the order page, after Lunar's own actions.
 */
final class OrderDeleteViewExtension extends ViewPageExtension
{
    public function headerActions(array $actions): array
    {
        return [...$actions, DeleteOrderAction::make()];
    }
}
