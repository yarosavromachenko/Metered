<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;

final readonly class ListEndpointsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);

        $query = WebhookEndpoint::query()
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
        $query->getQuery()->orderBy('created_at')->orderBy('id');

        return new JsonResponse(['data' => $query->get()->map(WebhookJson::endpointRow(...))->values()->all()]);
    }
}
