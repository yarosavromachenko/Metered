<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Metered\Shared\Presentation\Http\Problem;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authentication\AuthenticationFailed;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Presentation\Http\TenantRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns `Authorization: Bearer mk_…` into a tenant, or into a problem.
 *
 * Routes declare the scope they need — `api-key:usage:write` — so the
 * authority a route requires is visible in the route file rather than buried
 * in a controller. A route that declares no scope only requires a valid key.
 *
 * Nothing is cached on this object and nothing is written to the container:
 * the middleware is a singleton that outlives the request under Octane, and
 * the only per-request state it produces goes onto the request itself.
 */
final readonly class AuthenticateApiKey
{
    public function __construct(private ApiKeyAuthenticator $authenticator) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            return $this->unauthorized(
                $request,
                'invalid-api-key',
                'This endpoint requires an API key: Authorization: Bearer mk_<environment>_<prefix>_<secret>.',
            );
        }

        try {
            $key = $this->authenticator->authenticate($token);
        } catch (AuthenticationFailed $failure) {
            return $this->unauthorized($request, $failure->problem, $failure->getMessage());
        }

        // Scope::from rather than tryFrom: a scope named in a route file that
        // does not exist is a typo in the route, and silently requiring
        // nothing is the worst available answer to it.
        $required = $scope === null ? null : Scope::from($scope);

        if ($required !== null && ! $key->allows($required)) {
            return Problem::response(
                'insufficient-scope',
                'Insufficient scope',
                403,
                sprintf('This API key does not carry the "%s" scope.', $required->value),
                Problem::instanceFor($request),
                ['required_scope' => $required->value],
            );
        }

        TenantRequest::attach($request, $key);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    private function unauthorized(Request $request, string $type, string $detail): Response
    {
        return Problem::response(
            $type,
            'Unauthorized',
            401,
            $detail,
            Problem::instanceFor($request),
        )->withHeaders(['WWW-Authenticate' => 'Bearer realm="metered"']);
    }
}
