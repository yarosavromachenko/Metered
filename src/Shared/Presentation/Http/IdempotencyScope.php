<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\Request;

/**
 * Decides which scope an idempotency key belongs to.
 *
 * Keys are unique per scope rather than globally, so two tenants choosing the
 * same key never collide. Until API keys exist the scope is the project the
 * request was authenticated for, and everything else falls back to a shared
 * bucket.
 */
interface IdempotencyScope
{
    public function forRequest(Request $request): string;
}
