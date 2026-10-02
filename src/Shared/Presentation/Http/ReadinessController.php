<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Metered\Shared\Application\Health\Readiness;

/**
 * 200 when every check passes, otherwise 503 listing the failures.
 */
final readonly class ReadinessController
{
    public function __construct(
        private Readiness $readiness,
    ) {}

    public function __invoke(): JsonResponse
    {
        $results = $this->readiness->evaluate();
        $ready = Readiness::isReady($results);

        $checks = [];

        foreach ($results as $name => $result) {
            $checks[$name] = [
                'status' => $result->passed ? 'pass' : 'fail',
                'detail' => $result->detail,
            ];
        }

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
            $ready ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
