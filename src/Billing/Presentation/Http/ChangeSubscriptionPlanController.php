<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Billing\Application\Command\ChangeSubscriptionPlan;
use Metered\Billing\Application\Command\ChangeSubscriptionPlanHandler;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Moves a subscription to another published version at the end of its
 * current period; the new phase in the response says exactly when.
 */
final readonly class ChangeSubscriptionPlanController
{
    public function __construct(private ChangeSubscriptionPlanHandler $handler) {}

    public function __invoke(Request $request, string $subscription): JsonResponse
    {
        /** @var array{plan_version_id: string} $input */
        $input = Validator::make($request->all(), [
            'plan_version_id' => ['required', 'uuid'],
        ])->validate();

        $changed = $this->handler->handle(new ChangeSubscriptionPlan(
            TenantRequest::tenant($request),
            Uuid::fromString($subscription),
            Uuid::fromString($input['plan_version_id']),
            ApiCaller::actor($request),
        ));

        return new JsonResponse(CatalogJson::subscription($changed));
    }
}
