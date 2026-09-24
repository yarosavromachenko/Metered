<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;

/**
 * Reads the scope from a request attribute: the project id the API key
 * authentication sets. A request that reached this without it shares the
 * `unscoped` bucket — stated here rather than left to be discovered.
 */
final class RequestAttributeScope implements IdempotencyScope
{
    public const string ATTRIBUTE = 'metered.project_id';

    public function forRequest(Request $request): string
    {
        $scope = $request->attributes->get(self::ATTRIBUTE);

        return is_string($scope) && $scope !== '' ? $scope : 'unscoped';
    }
}
