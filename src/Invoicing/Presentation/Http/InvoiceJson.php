<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Metered\Invoicing\Domain\CreditNote\CreditNote;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice as InvoiceRow;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Minor units, decimal strings, RFC 3339 UTC (docs/api.md).
 */
final class InvoiceJson
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(InvoiceRow $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->printedNumber(),
            'status' => $invoice->status->value,
            'customer_id' => $invoice->customer_id,
            'customer_ref' => $invoice->customer_ref,
            'subscription_id' => $invoice->subscription_id,
            'period_start' => $invoice->period_start->format(DATE_ATOM),
            'period_end' => $invoice->period_end->format(DATE_ATOM),
            'total' => self::money($invoice->total()),
            'finalized_at' => $invoice->finalized_at?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $invoice, ?CreditNote $creditNote): array
    {
        return [
            'id' => $invoice->id->value,
            'number' => $invoice->number instanceof DocumentNumber ? (string) $invoice->number : null,
            'status' => $invoice->status->value,
            'customer_id' => $invoice->customerId->value,
            'bill_to' => ['reference' => $invoice->billTo->reference, 'name' => $invoice->billTo->name],
            'subscription_id' => $invoice->subscriptionId->value,
            'period_start' => $invoice->period->start->format(DATE_ATOM),
            'period_end' => $invoice->period->end->format(DATE_ATOM),
            'lines' => array_map(self::line(...), $invoice->lines),
            'total' => self::money($invoice->total()),
            'built_at' => $invoice->builtAt->format(DATE_ATOM),
            'finalized_at' => $invoice->finalizedAt?->format(DATE_ATOM),
            'paid_at' => $invoice->paidAt?->format(DATE_ATOM),
            'voided_at' => $invoice->voidedAt?->format(DATE_ATOM),
            'credit_note' => $creditNote instanceof CreditNote ? [
                'number' => (string) $creditNote->number,
                'amount' => self::money($creditNote->amount),
                'reason' => $creditNote->reason,
                'issued_at' => $creditNote->issuedAt->format(DATE_ATOM),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(InvoiceLine $line): array
    {
        return [
            'kind' => $line->kind->value,
            'description' => $line->description,
            'meter_code' => $line->meterCode,
            'quantity' => $line->quantity instanceof Quantity ? (string) $line->quantity : null,
            'amount' => self::money($line->amount),
            'covers_start' => $line->covers->start->format(DATE_ATOM),
            'covers_end' => $line->covers->end->format(DATE_ATOM),
            'calculation' => $line->calculation,
        ];
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private static function money(Money $money): array
    {
        return ['amount' => $money->minorUnits(), 'currency' => $money->currency()];
    }
}
