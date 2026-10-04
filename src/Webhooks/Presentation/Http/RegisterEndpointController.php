<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;

/**
 * The response is the only place `signing_secret` is shown.
 */
final readonly class RegisterEndpointController
{
    public function __construct(private RegisterEndpointHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{url: string, description?: string, events: list<string>} $input */
        $input = Validator::make($request->all(), [
            'url' => ['required', 'string', 'max:2048'],
            'description' => ['sometimes', 'string', 'max:255'],
            'events' => ['required', 'array', 'min:1', 'list'],
            'events.*' => ['string', 'max:64'],
        ])->validate();

        $registered = $this->handler->handle(new RegisterEndpoint(
            TenantRequest::tenant($request),
            $input['url'],
            $input['description'] ?? '',
            $input['events'],
            ApiCaller::actor($request),
        ));

        return new JsonResponse(WebhookJson::endpoint($registered->endpoint, $registered->secret), 201);
    }
}
