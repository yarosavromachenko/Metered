<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use RuntimeException;

/**
 * Where the authenticated tenant lives for the duration of one request.
 *
 * On the request object, deliberately, and nowhere else. It lives in the
 * shared kernel because every module's endpoints read it and none of them may
 * reach into Tenancy to do so.
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

    /**
     * Called by whatever authenticated the request — today the API key
     * middleware, and it hands over the tenant and the key's id rather than
     * the key itself: the shared kernel has no business knowing what an API
     * key is, and a controller downstream has no business reading its hash.
     */
    public static function attach(Request $request, TenantContext $tenant, Uuid $apiKeyId): void
    {
        $request->attributes->set(self::TENANT, $tenant);
        $request->attributes->set(self::API_KEY, $apiKeyId);

        // The idempotency middleware scopes keys per project, and this is
        // where it learns which one (see RequestAttributeScope).
        $request->attributes->set(RequestAttributeScope::ATTRIBUTE, $tenant->projectId->value);
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
        $id = $request->attributes->get(self::API_KEY);

        return $id instanceof Uuid ? $id : null;
    }
}
