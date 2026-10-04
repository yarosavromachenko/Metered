<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use DateTimeImmutable;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Payloads of the invoice integration events, one shape for all.
 */
final class InvoiceMessages
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function about(Uuid $id, Invoice $invoice, string $type, DateTimeImmutable $at, array $extra = []): OutboxMessage
    {
        return new OutboxMessage(
            $id,
            'invoice',
            $invoice->id,
            $type,
            [
                'invoice_id' => $invoice->id->value,
                'organization_id' => $invoice->tenant->organizationId->value,
                'project_id' => $invoice->tenant->projectId->value,
                'customer_id' => $invoice->customerId->value,
                'subscription_id' => $invoice->subscriptionId->value,
                'number' => $invoice->number instanceof DocumentNumber ? (string) $invoice->number : null,
                'status' => $invoice->status->value,
                'total_minor' => $invoice->total()->minorUnits(),
                'currency' => $invoice->currency,
                'period_start' => $invoice->period->start->format(DATE_ATOM),
                'period_end' => $invoice->period->end->format(DATE_ATOM),
                ...$extra,
            ],
            [],
            $at,
        );
    }
}
