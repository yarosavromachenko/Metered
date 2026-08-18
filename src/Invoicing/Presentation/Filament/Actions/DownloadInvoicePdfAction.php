<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Invoicing\Presentation\Pdf\InvoicePdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadInvoicePdfAction
{
    public static function make(): Action
    {
        return Action::make('pdf')
            ->label('PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(static fn(Invoice $record): StreamedResponse => response()->streamDownload(
                static function () use ($record): void {
                    echo InvoicePdf::render($record);
                },
                InvoicePdf::filename($record),
                ['Content-Type' => 'application/pdf'],
            ));
    }
}
