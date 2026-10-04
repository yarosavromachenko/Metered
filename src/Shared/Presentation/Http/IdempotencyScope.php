<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;

/**
 * Idempotency keys are unique per scope (the project), so tenants never
 * collide.
 */
interface IdempotencyScope
{
    public function forRequest(Request $request): string;
}
