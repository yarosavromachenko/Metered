<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Http;

use Illuminate\Http\Request;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * The actor behind an API request: the key, named in the audit log by its id.
 */
final class ApiCaller
{
    public static function actor(Request $request): Actor
    {
        return Actor::system('api-key:' . (TenantRequest::apiKeyId($request)->value ?? 'unknown'));
    }
}
