<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Resources\Invoices\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Invoicing\Presentation\Filament\Resources\Invoices\InvoiceResource;

final class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
