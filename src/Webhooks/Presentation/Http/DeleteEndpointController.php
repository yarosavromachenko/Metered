<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Application\Command\RemoveEndpoint;
use Metered\Webhooks\Application\Command\RemoveEndpointHandler;

final readonly class DeleteEndpointController
{
    public function __construct(private RemoveEndpointHandler $handler) {}

    public function __invoke(Request $request, string $endpoint): Response
    {
        $this->handler->handle(new RemoveEndpoint(
            TenantRequest::tenant($request),
            WebhookIds::parse('endpoint', $endpoint),
            ApiCaller::actor($request),
        ));

        return new Response(status: 204);
    }
}
