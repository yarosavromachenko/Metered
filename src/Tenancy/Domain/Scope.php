<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

/**
 * What an API key is allowed to do.
 *
 * Two scopes, not a permission matrix. Ingestion is the one credential that
 * gets deployed widely — into every service that reports usage — and the whole
 * point is that it can write events and nothing else. Anything richer belongs
 * to a person, and people authenticate to the panel instead (ADR-0017).
 *
 * The scopes do not nest: `admin` does not imply `usage:write`. A management
 * key that silently gained the ability to write billable events would be a
 * surprising kind of authority to acquire by implication.
 */
enum Scope: string
{
    case UsageWrite = 'usage:write';
    case Admin = 'admin';
}
