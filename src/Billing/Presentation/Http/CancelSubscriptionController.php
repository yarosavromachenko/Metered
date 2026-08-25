<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Billing\Application\Command\CancelSubscription;
use Metered\Billing\Application\Command\CancelSubscriptionHandler;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Cancels at the end of the current period, or at once with
 * `immediately: true`.
 */
final readonly class CancelSubscriptionController
{
    public function __construct(private CancelSubscriptionHandler $handler) {}

    public function __invoke(Request $request, string $subscription): JsonResponse
    {
        /** @var array{immediately?: bool} $input */
        $input = Validator::make($request->all(), [
            'immediately' => ['sometimes', 'boolean'],
        ])->validate();

        $canceled = $this->handler->handle(new CancelSubscription(
            TenantRequest::tenant($request),
            Uuid::fromString($subscription),
            $input['immediately'] ?? false,
            ApiCaller::actor($request),
        ));

        return new JsonResponse(CatalogJson::subscription($canceled));
    }
}
