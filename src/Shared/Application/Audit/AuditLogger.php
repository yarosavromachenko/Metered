<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Audit;

use Metered\Shared\Domain\Audit\AuditEntry;

/**
 * Records writes only. Payloads never contain secrets.
 */
interface AuditLogger
{
    public function record(AuditEntry $entry): void;
}
