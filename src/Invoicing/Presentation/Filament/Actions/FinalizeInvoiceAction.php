<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Invoicing\Application\Command\FinalizeInvoice;
use Metered\Invoicing\Application\Command\FinalizeInvoiceHandler;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * Finalizes a draft the period close left behind — a finalization that
 * failed after its draft was built.
 */
final class FinalizeInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('finalize')
            ->label('Finalize')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalDescription('The invoice is numbered and booked, and can no longer change.')
            ->visible(static fn(Invoice $record): bool => $record->status === InvoiceStatus::Draft
                && app(PanelScope::class)->may(Permission::MoveMoney))
            ->action(static fn(Invoice $record): null => self::run($record->id));
    }

    public static function run(string $invoiceId): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($invoiceId): string {
            $invoice = app(FinalizeInvoiceHandler::class)->handle(new FinalizeInvoice(
                $tenant,
                Uuid::fromString($invoiceId),
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Invoice %s finalized.', $invoice->number);
        });
    }
}
