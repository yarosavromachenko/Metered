<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Billing\Application\Command\RegisterCustomer;
use Metered\Billing\Application\Command\RegisterCustomerHandler;
use Metered\Shared\Presentation\Http\ApiCaller;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class RegisterCustomerController
{
    public function __construct(private RegisterCustomerHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{reference: string, name: string} $input */
        $input = Validator::make($request->all(), [
            // The id the client's own system knows the customer by; usage
            // events name the customer with it.
            'reference' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:120'],
        ])->validate();

        $customer = $this->handler->handle(new RegisterCustomer(
            TenantRequest::tenant($request),
            $input['reference'],
            $input['name'],
            ApiCaller::actor($request),
        ));

        return new JsonResponse(CatalogJson::customer($customer), 201);
    }
}
