<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Usage\Application\Metrics\UsageMetrics;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before authentication and rate limiting, so 401, 429 and 503 are
 * measured too.
 */
final readonly class MeasureIngestion
{
    public function __construct(private Metrics $metrics) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $labels = ['status' => (string) $response->getStatusCode()];

        $this->metrics->add(UsageMetrics::ingestRequests(), 1, $labels);
        $this->metrics->record(UsageMetrics::ingestDuration(), (int) (hrtime(true) - $started), $labels);

        return $response;
    }
}
