<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Application\Command\ReconfigureEndpoint;
use Metered\Webhooks\Application\Command\ReconfigureEndpointHandler;
use Metered\Webhooks\Application\Command\WebhookNotFound;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EventType;

/**
 * Changes what is given and leaves the rest as it is.
 */
final readonly class UpdateEndpointController
{
    public function __construct(
        private ReconfigureEndpointHandler $handler,
        private EndpointRepository $endpoints,
    ) {}

    public function __invoke(Request $request, string $endpoint): JsonResponse
    {
        /** @var array{url?: string, description?: string, events?: list<string>, enabled?: bool} $input */
        $input = Validator::make($request->all(), [
            'url' => ['sometimes', 'string', 'max:2048'],
            'description' => ['sometimes', 'string', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1', 'list'],
            'events.*' => ['string', 'max:64'],
            'enabled' => ['sometimes', 'boolean'],
        ])->validate();

        $tenant = TenantRequest::tenant($request);
        $id = WebhookIds::parse('endpoint', $endpoint);
        $current = $this->endpoints->find($tenant, $id);

        if (! $current instanceof Endpoint) {
            throw WebhookNotFound::of('endpoint', $id);
        }

        $changed = $this->handler->handle(new ReconfigureEndpoint(
            $tenant,
            $id,
            $input['url'] ?? $current->url->value,
            $input['description'] ?? $current->description,
            $input['events'] ?? array_map(static fn(EventType $t): string => $t->value, $current->eventTypes),
            $input['enabled'] ?? $current->enabled,
            ApiCaller::actor($request),
        ));

        return new JsonResponse(WebhookJson::endpoint($changed));
    }
}
