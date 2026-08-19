<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Invoicing\Application\Command\InvoiceNotFound;
use Metered\Invoicing\Application\Command\PayInvoice;
use Metered\Invoicing\Application\Command\PayInvoiceHandler;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Collects a finalized invoice through the payment gateway.
 */
final readonly class PayInvoiceController
{
    public function __construct(
        private PayInvoiceHandler $handler,
        private CreditNoteRepository $creditNotes,
    ) {}

    public function __invoke(Request $request, string $invoice): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);
        $id = Uuid::isValid($invoice) ? Uuid::fromString($invoice) : throw InvoiceNotFound::reference($invoice);

        $paid = $this->handler->handle(new PayInvoice($tenant, $id, ApiCaller::actor($request)));

        return new JsonResponse(InvoiceJson::invoice($paid, $this->creditNotes->forInvoice($tenant, $id)));
    }
}
