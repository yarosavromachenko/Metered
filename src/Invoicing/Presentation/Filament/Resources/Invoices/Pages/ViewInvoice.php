<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Resources\Invoices\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Invoicing\Presentation\Filament\Actions\DownloadInvoicePdfAction;
use Metered\Invoicing\Presentation\Filament\Actions\FinalizeInvoiceAction;
use Metered\Invoicing\Presentation\Filament\Actions\PayInvoiceAction;
use Metered\Invoicing\Presentation\Filament\Actions\VoidInvoiceAction;
use Metered\Invoicing\Presentation\Filament\Resources\Invoices\InvoiceResource;

final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Invoice
            ? sprintf('Invoice %s', $record->printedNumber() ?? '(draft)')
            : 'Invoice';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            FinalizeInvoiceAction::make(),
            PayInvoiceAction::make(),
            VoidInvoiceAction::make(),
            DownloadInvoicePdfAction::make(),
        ];
    }
}
