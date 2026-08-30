<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Billing\Application\Command\StartSubscription;
use Metered\Billing\Application\Command\StartSubscriptionHandler;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\Problem;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class StartSubscriptionController
{
    public function __construct(
        private StartSubscriptionHandler $handler,
        private CustomerRepository $customers,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);

        /** @var array{customer_ref: string, plan_version_id: string, starts_at?: string} $input */
        $input = Validator::make($request->all(), [
            // The customer's reference, as usage events name it.
            'customer_ref' => ['required', 'string', 'max:128'],
            'plan_version_id' => ['required', 'uuid'],
            // RFC 3339, any offset; backdates the subscription when given.
            'starts_at' => ['sometimes', 'string', 'date'],
        ])->validate();

        $customer = $this->customers->findByReference($tenant, CustomerReference::fromString($input['customer_ref']));

        if (! $customer instanceof Customer) {
            return Problem::response(
                'not-found',
                'Not found',
                404,
                sprintf('No customer in this project is registered as "%s".', $input['customer_ref']),
                Problem::instanceFor($request),
            );
        }

        $subscription = $this->handler->handle(new StartSubscription(
            $tenant,
            $customer->id,
            Uuid::fromString($input['plan_version_id']),
            ApiCaller::actor($request),
            isset($input['starts_at']) ? new DateTimeImmutable($input['starts_at']) : null,
        ));

        return new JsonResponse(CatalogJson::subscription($subscription), 201);
    }
}
