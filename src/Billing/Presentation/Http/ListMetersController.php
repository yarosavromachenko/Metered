<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class ListMetersController
{
    public function __construct(private MeterRepository $meters) {}

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                CatalogJson::meter(...),
                $this->meters->listFor(TenantRequest::tenant($request)),
            ),
        ]);
    }
}
