<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Every plan with its versions, newest version first, so a client can pick
 * the version id a subscription starts on.
 */
final readonly class ListPlansController
{
    public function __construct(
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private MeterRepository $meters,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);
        $codes = MeterCodes::of($this->meters->listFor($tenant));

        return new JsonResponse([
            'data' => array_map(
                fn(Plan $plan): array => CatalogJson::plan($plan, $this->versions->listForPlan($tenant, $plan->id), $codes),
                $this->plans->listFor($tenant),
            ),
        ]);
    }
}
