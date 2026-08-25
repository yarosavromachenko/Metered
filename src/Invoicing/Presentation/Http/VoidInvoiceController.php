<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Invoicing\Application\Command\InvoiceNotFound;
use Metered\Invoicing\Application\Command\VoidInvoice;
use Metered\Invoicing\Application\Command\VoidInvoiceHandler;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Discards a draft, or voids a finalized invoice with a credit note carrying
 * the reason given.
 */
final readonly class VoidInvoiceController
{
    public function __construct(
        private VoidInvoiceHandler $handler,
        private CreditNoteRepository $creditNotes,
    ) {}

    public function __invoke(Request $request, string $invoice): JsonResponse
    {
        /** @var array{reason: string} $input */
        $input = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        $tenant = TenantRequest::tenant($request);
        $id = Uuid::isValid($invoice) ? Uuid::fromString($invoice) : throw InvoiceNotFound::reference($invoice);

        $voided = $this->handler->handle(new VoidInvoice($tenant, $id, $input['reason'], ApiCaller::actor($request)));

        return new JsonResponse(InvoiceJson::invoice($voided, $this->creditNotes->forInvoice($tenant, $id)));
    }
}
