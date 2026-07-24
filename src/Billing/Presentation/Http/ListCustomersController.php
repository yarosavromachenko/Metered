<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class ListCustomersController
{
    public function __construct(private CustomerRepository $customers) {}

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                CatalogJson::customer(...),
                $this->customers->listFor(TenantRequest::tenant($request)),
            ),
        ]);
    }
}
