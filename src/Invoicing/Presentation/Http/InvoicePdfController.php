<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Metered\Invoicing\Application\Command\InvoiceNotFound;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Invoicing\Presentation\Pdf\InvoicePdf;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class InvoicePdfController
{
    public function __invoke(Request $request, string $invoice): Response
    {
        $tenant = TenantRequest::tenant($request);

        $found = Uuid::isValid($invoice) ? Invoice::query()
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->find($invoice) : null;

        if (! $found instanceof Invoice) {
            throw InvoiceNotFound::reference($invoice);
        }

        return new Response(InvoicePdf::render($found), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', InvoicePdf::filename($found)),
        ]);
    }
}
