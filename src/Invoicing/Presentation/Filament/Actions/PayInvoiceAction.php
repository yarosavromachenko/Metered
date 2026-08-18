<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Invoicing\Application\Command\PayInvoice;
use Metered\Invoicing\Application\Command\PayInvoiceHandler;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * Collects a finalized invoice through the payment gateway — the fake one,
 * in this system.
 */
final class PayInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('pay')
            ->label('Collect payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(static fn(Invoice $record): string => sprintf('Charge %s for %s through the payment gateway.', $record->customer_ref, $record->total()))
            ->visible(static fn(Invoice $record): bool => $record->status === InvoiceStatus::Finalized
                && app(PanelScope::class)->may(Permission::MoveMoney))
            ->action(static fn(Invoice $record): null => self::run($record->id));
    }

    public static function run(string $invoiceId): null
    {
        return Attempt::change(static function (TenantContext $tenant) use ($invoiceId): string {
            $invoice = app(PayInvoiceHandler::class)->handle(new PayInvoice(
                $tenant,
                Uuid::fromString($invoiceId),
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Invoice %s paid.', $invoice->number);
        });
    }
}
