<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Newest first, without lines.
 */
final readonly class ListInvoicesController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{status?: string, customer_ref?: string, limit?: int} $input */
        $input = Validator::make($request->query(), [
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'customer_ref' => ['sometimes', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $tenant = TenantRequest::tenant($request);

        $query = Invoice::query()
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);

        $query->getQuery()
            ->orderByDesc('built_at')
            ->orderByDesc('id')
            ->limit((int) ($input['limit'] ?? 50));

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (isset($input['customer_ref'])) {
            $query->where('customer_ref', $input['customer_ref']);
        }

        return new JsonResponse([
            'data' => $query->get()->map(InvoiceJson::summary(...))->values()->all(),
        ]);
    }
}
