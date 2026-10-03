<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Application\Command\ReplayDelivery;
use Metered\Webhooks\Application\Command\ReplayDeliveryHandler;

final readonly class ReplayDeliveryController
{
    public function __construct(private ReplayDeliveryHandler $handler) {}

    public function __invoke(Request $request, string $delivery): JsonResponse
    {
        $replayed = $this->handler->handle(new ReplayDelivery(
            TenantRequest::tenant($request),
            WebhookIds::parse('delivery', $delivery),
            ApiCaller::actor($request),
        ));

        return new JsonResponse(WebhookJson::delivery($replayed));
    }
}
