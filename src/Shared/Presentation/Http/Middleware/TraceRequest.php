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
 * Opens the trace, or joins the caller's.
 *
 * Joining matters as much as opening: a tenant that instruments its own
 * backend and sends `traceparent` can then see its request and our processing
 * of it as one trace, which is the difference between "your API was slow" and
 * a conversation about which hop was.
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

        // 4xx is the client being told something, not the server failing. Only
        // 5xx marks the span as an error, or every rejected request would look
        // like an outage on a dashboard.
        if ($response->getStatusCode() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $scope->detach();
        $span->end();

        return $response;
    }

    /**
     * The route pattern rather than the path, so that a million customer ids
     * do not become a million span names.
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
