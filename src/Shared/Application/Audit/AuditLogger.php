<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Audit;

use Metered\Shared\Domain\Audit\AuditEntry;

/**
 * Records who did what.
 *
 * Reads are not recorded, and secrets never appear in a payload: an audit
 * trail that leaks the thing it was protecting has made matters worse.
 */
interface AuditLogger
{
    public function record(AuditEntry $entry): void;
}
