<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Presentation\Http\Problem;
use Metered\Shared\Presentation\Http\TenantRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per API key (not per IP, tenants share NAT), fixed one-minute window.
 * Headers use the IETF draft names from docs/api.md (`RateLimit-Limit`), not
 * Laravel's `X-RateLimit-*`.
 */
final readonly class ThrottleApiKey
{
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private RateLimiter $limiter,
        private int $defaultPerMinute,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $perMinute = null): Response
    {
        $limit = $perMinute === null ? $this->defaultPerMinute : (int) $perMinute;
        $bucket = $this->bucketFor($request);

        if ($this->limiter->tooManyAttempts($bucket, $limit)) {
            $retryAfter = $this->limiter->availableIn($bucket);

            return $this->withRateLimitHeaders(
                Problem::response(
                    'rate-limit-exceeded',
                    'Too many requests',
                    429,
                    sprintf('This API key is limited to %d requests per minute.', $limit),
                    Problem::instanceFor($request),
                )->withHeaders(['Retry-After' => (string) $retryAfter]),
                $limit,
                0,
                $retryAfter,
            );
        }

        $this->limiter->hit($bucket, self::WINDOW_SECONDS);

        return $this->withRateLimitHeaders(
            $next($request),
            $limit,
            $this->limiter->remaining($bucket, $limit),
            $this->limiter->availableIn($bucket),
        );
    }

    /**
     * Falls back to the IP if a route without authentication uses this.
     */
    private function bucketFor(Request $request): string
    {
        $key = TenantRequest::apiKeyId($request);

        return $key instanceof Uuid
            ? 'api-key:' . $key->value
            : 'api-ip:' . $request->ip();
    }

    private function withRateLimitHeaders(Response $response, int $limit, int $remaining, int $reset): Response
    {
        $response->headers->set('RateLimit-Limit', (string) $limit);
        $response->headers->set('RateLimit-Remaining', (string) max($remaining, 0));
        $response->headers->set('RateLimit-Reset', (string) $reset);

        return $response;
    }
}
