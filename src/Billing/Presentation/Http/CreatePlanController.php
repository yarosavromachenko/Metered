<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Billing\Application\Command\CreatePlan;
use Metered\Billing\Application\Command\CreatePlanHandler;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class CreatePlanController
{
    public function __construct(private CreatePlanHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{code: string, name: string} $input */
        $input = Validator::make($request->all(), [
            // Lowercase letters and digits, with single hyphens or
            // underscores between them; normalised to lowercase.
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
        ])->validate();

        $plan = $this->handler->handle(new CreatePlan(
            TenantRequest::tenant($request),
            $input['code'],
            $input['name'],
            ApiCaller::actor($request),
        ));

        return new JsonResponse(CatalogJson::plan($plan, [], []), 201);
    }
}
