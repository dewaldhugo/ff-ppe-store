<?php

declare(strict_types=1);

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

final class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
