<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use RuntimeException;

/**
 * The authenticated tenant, stored as a request attribute. A singleton or a
 * static would leak into the next request under Octane.
 */
final class TenantRequest
{
    public const string TENANT = 'metered.tenant';

    public const string API_KEY = 'metered.api_key';

    /**
     * Called by the API key middleware with the key's id, not the key.
     */
    public static function attach(Request $request, TenantContext $tenant, Uuid $apiKeyId): void
    {
        $request->attributes->set(self::TENANT, $tenant);
        $request->attributes->set(self::API_KEY, $apiKeyId);

        // Read by RequestAttributeScope.
        $request->attributes->set(RequestAttributeScope::ATTRIBUTE, $tenant->projectId->value);
    }

    public static function tenant(Request $request): TenantContext
    {
        $tenant = $request->attributes->get(self::TENANT);

        if (! $tenant instanceof TenantContext) {
            // Only reachable on a route without the authentication middleware.
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
