<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Billing\Application\Command\DefineMeter;
use Metered\Billing\Application\Command\DefineMeterHandler;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Presentation\Http\TenantRequest;

final readonly class DefineMeterController
{
    public function __construct(private DefineMeterHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{code: string, name: string, aggregation: string} $input */
        $input = Validator::make($request->all(), [
            // Lowercase letters, digits, and single dots, hyphens or
            // underscores between them; normalised to lowercase.
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'aggregation' => ['required', 'string', 'in:sum,count,max'],
        ])->validate();

        $meter = $this->handler->handle(new DefineMeter(
            TenantRequest::tenant($request),
            $input['code'],
            $input['name'],
            Aggregation::from($input['aggregation']),
            ApiCaller::actor($request),
        ));

        return new JsonResponse(CatalogJson::meter($meter), 201);
    }
}
