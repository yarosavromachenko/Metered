<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * What the client is told: how many events were taken, and the handle to ask
 * about them later.
 *
 * "Accepted" means durably in the stream, not in PostgreSQL, and not
 * validated against the catalog. The API reference says so in those words,
 * because the failure mode people resent is the undocumented one.
 */
final readonly class IngestionReceipt
{
    public function __construct(
        public int $accepted,
        public Uuid $requestId,
    ) {}
}
