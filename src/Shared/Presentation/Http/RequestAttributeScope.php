<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;

/**
 * Reads the scope from a request attribute, which the authentication
 * middleware sets once API keys exist (M2). Before then, every caller shares
 * one bucket — stated here rather than left to be discovered.
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
