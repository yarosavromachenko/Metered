<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

/**
 * API key scopes (ADR-0017). `usage:write` keys can only send events. Scopes
 * do not nest: `admin` does not include `usage:write`.
 */
enum Scope: string
{
    case UsageWrite = 'usage:write';
    case Admin = 'admin';
}
