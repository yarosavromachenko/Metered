<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\JsonResponse;

/**
 * `GET /health/live` — the process is up and answering.
 *
 * Touches no dependency on purpose. Liveness is what an orchestrator restarts
 * on, and restarting a healthy process because the database blinked turns
 * one outage into two.
 */
final readonly class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'live']);
    }
}
