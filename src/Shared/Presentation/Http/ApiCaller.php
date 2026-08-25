<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;
use Metered\Shared\Domain\Access\Actor;

/**
 * Who an API request acts as. An admin key is not a person: its authority
 * was settled by the scope middleware before the controller ran, and the
 * audit log names the key that made each change.
 */
final class ApiCaller
{
    public static function actor(Request $request): Actor
    {
        return Actor::system('api-key:' . (TenantRequest::apiKeyId($request)->value ?? 'unknown'));
    }
}
