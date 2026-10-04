<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;

/**
 * The project id set by API key authentication; `unscoped` when absent.
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
