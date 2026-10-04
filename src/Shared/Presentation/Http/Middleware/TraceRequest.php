<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Starts a trace, or continues the caller's when it sends `traceparent`.
 */
final readonly class TraceRequest
{
    public function __construct(private Tracing $tracing) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $span = $this->tracing->tracer()
            ->spanBuilder($request->getMethod() . ' ' . $this->routePattern($request))
            ->setParent($this->tracing->extract($this->headers($request)))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $request->getMethod())
            ->setAttribute('url.path', '/' . ltrim($request->path(), '/'))
            ->startSpan();

        $scope = $span->activate();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $scope->detach();
            $span->end();

            throw $e;
        }

        $span->setAttribute('http.response.status_code', $response->getStatusCode());

        // Only 5xx marks the span as an error; 4xx is a client mistake.
        if ($response->getStatusCode() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $scope->detach();
        $span->end();

        return $response;
    }

    /**
     * Route pattern, not the path, to keep span names low-cardinality.
     */
    private function routePattern(Request $request): string
    {
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            $uri = $route->uri();

            if (is_string($uri)) {
                return '/' . ltrim($uri, '/');
            }
        }

        return '/' . ltrim($request->path(), '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;

            if (is_string($first)) {
                $headers[$name] = $first;
            }
        }

        return $headers;
    }
}
