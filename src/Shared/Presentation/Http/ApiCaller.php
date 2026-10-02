<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;
use Metered\Shared\Domain\Access\Actor;

/**
 * The actor for API requests: the API key, already authorised by the scope
 * middleware.
 */
final class ApiCaller
{
    public static function actor(Request $request): Actor
    {
        return Actor::system('api-key:' . (TenantRequest::apiKeyId($request)->value ?? 'unknown'));
    }
}
