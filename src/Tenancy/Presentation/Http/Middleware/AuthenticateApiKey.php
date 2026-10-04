<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Metered\Shared\Presentation\Http\Problem;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authentication\AuthenticationFailed;
use Metered\Tenancy\Domain\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Authorization: Bearer mk_…`. Routes name the required scope
 * (`api-key:usage:write`); without one any valid key passes. Keeps no state:
 * it is a singleton under Octane, so the tenant goes onto the request.
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

        // from(), not tryFrom(): a typo in a route must throw.
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

        TenantRequest::attach($request, $key->tenant, $key->id);

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
