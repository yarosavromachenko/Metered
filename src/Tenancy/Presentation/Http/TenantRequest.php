<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Http;

use Illuminate\Http\Request;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Http\RequestAttributeScope;
use Metered\Tenancy\Domain\ApiKey;
use RuntimeException;

/**
 * Where the authenticated tenant lives for the duration of one request.
 *
 * On the request object, deliberately, and nowhere else. A container
 * singleton or a static would survive the request under Octane, where the
 * worker handles the next one without being rebuilt: the second request would
 * inherit the first tenant's scope, and every query would succeed against the
 * wrong rows. A request attribute cannot outlive its request.
 */
final class TenantRequest
{
    public const string TENANT = 'metered.tenant';

    public const string API_KEY = 'metered.api_key';

    public static function attach(Request $request, ApiKey $key): void
    {
        $request->attributes->set(self::TENANT, $key->tenant);
        $request->attributes->set(self::API_KEY, $key);

        // The idempotency middleware scopes keys per project, and this is
        // where it learns which one (see RequestAttributeScope).
        $request->attributes->set(RequestAttributeScope::ATTRIBUTE, $key->tenant->projectId->value);
    }

    public static function tenant(Request $request): TenantContext
    {
        $tenant = $request->attributes->get(self::TENANT);

        if (! $tenant instanceof TenantContext) {
            // Reached only by routing an endpoint without the authentication
            // middleware. Failing loudly beats returning an empty scope that
            // a repository would happily query with.
            throw new RuntimeException('This request was never authenticated, so it has no tenant.');
        }

        return $tenant;
    }

    public static function apiKeyId(Request $request): ?Uuid
    {
        $key = $request->attributes->get(self::API_KEY);

        return $key instanceof ApiKey ? $key->id : null;
    }
}
