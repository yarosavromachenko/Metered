<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\JsonResponse;

/**
 * Checks no dependencies: a database outage must not restart the process.
 */
final readonly class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'live']);
    }
}
