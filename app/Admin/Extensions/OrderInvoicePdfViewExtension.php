<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use Lunar\Admin\Support\Extending\ViewPageExtension;

/**
 * The Invoice PDF button in the header of the order page, next to Lunar's own actions.
 */
final class OrderInvoicePdfViewExtension extends ViewPageExtension
{
    public function headerActions(array $actions): array
    {
        return [...$actions, DownloadInvoicePdfAction::make()];
    }
}
