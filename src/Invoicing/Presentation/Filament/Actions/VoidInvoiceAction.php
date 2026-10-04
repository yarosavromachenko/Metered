<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Metered\Invoicing\Application\Command\VoidInvoice;
use Metered\Invoicing\Application\Command\VoidInvoiceHandler;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * A finalized invoice needs a reason, printed on the credit note.
 */
final class VoidInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('void')
            ->label(static fn(Invoice $record): string => $record->status === InvoiceStatus::Draft ? 'Discard' : 'Void')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(static fn(Invoice $record): string => $record->status === InvoiceStatus::Draft
                ? 'The draft is dropped. It was never numbered or booked.'
                : sprintf('A credit note reverses the whole %s. The invoice stays, marked void.', $record->total()))
            ->schema(static fn(Invoice $record): array => $record->status === InvoiceStatus::Draft ? [] : [
                Textarea::make('reason')->label('Reason, printed on the credit note')->required()->maxLength(500),
            ])
            ->visible(static fn(Invoice $record): bool => in_array($record->status, [InvoiceStatus::Draft, InvoiceStatus::Finalized], true)
                && app(PanelScope::class)->may(Permission::MoveMoney))
            ->action(static fn(Invoice $record, array $data): null => self::run($record->id, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $invoiceId, array $data): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($invoiceId, $data): string {
            $reason = $data['reason'] ?? '';

            $invoice = app(VoidInvoiceHandler::class)->handle(new VoidInvoice(
                $tenant,
                Uuid::fromString($invoiceId),
                is_string($reason) ? $reason : '',
                app(PanelScope::class)->actor(),
            ));

            return $invoice->number === null ? 'Draft discarded.' : sprintf('Invoice %s voided.', $invoice->number);
        });
    }
}
