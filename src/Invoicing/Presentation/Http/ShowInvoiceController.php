<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Invoicing\Application\Command\InvoiceNotFound;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class ShowInvoiceController
{
    public function __construct(
        private InvoiceRepository $invoices,
        private CreditNoteRepository $creditNotes,
    ) {}

    public function __invoke(Request $request, string $invoice): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);
        $id = Uuid::isValid($invoice) ? Uuid::fromString($invoice) : throw InvoiceNotFound::reference($invoice);
        $found = $this->invoices->find($tenant, $id);

        if (! $found instanceof Invoice) {
            throw InvoiceNotFound::of($id);
        }

        return new JsonResponse(InvoiceJson::invoice($found, $this->creditNotes->forInvoice($tenant, $id)));
    }
}
