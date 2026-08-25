<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Application\Command\RotateEndpointSecret;
use Metered\Webhooks\Application\Command\RotateEndpointSecretHandler;

/**
 * A new signing secret, shown once. The old one keeps signing for a day.
 */
final readonly class RotateSecretController
{
    public function __construct(private RotateEndpointSecretHandler $handler) {}

    public function __invoke(Request $request, string $endpoint): JsonResponse
    {
        $rotated = $this->handler->handle(new RotateEndpointSecret(
            TenantRequest::tenant($request),
            WebhookIds::parse('endpoint', $endpoint),
            ApiCaller::actor($request),
        ));

        return new JsonResponse(WebhookJson::endpoint($rotated->endpoint, $rotated->secret));
    }
}
