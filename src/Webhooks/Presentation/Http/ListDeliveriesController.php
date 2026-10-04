<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookDelivery;

/**
 * Newest first.
 */
final readonly class ListDeliveriesController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{status?: string, endpoint_id?: string, limit?: int} $input */
        $input = Validator::make($request->query(), [
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
            'endpoint_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $tenant = TenantRequest::tenant($request);

        $query = WebhookDelivery::query()
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (isset($input['endpoint_id'])) {
            $query->where('endpoint_id', $input['endpoint_id']);
        }

        $query->getQuery()->orderByDesc('created_at')->orderByDesc('id')->limit((int) ($input['limit'] ?? 50));

        return new JsonResponse(['data' => $query->get()->map(WebhookJson::deliveryRow(...))->values()->all()]);
    }
}
