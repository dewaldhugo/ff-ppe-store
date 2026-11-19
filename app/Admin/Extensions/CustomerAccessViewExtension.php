<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use Lunar\Admin\Support\Extending\ViewPageExtension;

/**
 * The Approve button on the View Customer page, next to Edit and Delete.
 */
final class CustomerAccessViewExtension extends ViewPageExtension
{
    public function headerActions(array $actions): array
    {
        return [ApproveCustomerAction::make(), ...$actions];
    }
}
