<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * "Accepted" means stored in the stream, not yet validated against the
 * catalog or written to PostgreSQL (documented in docs/api.md).
 */
final readonly class IngestionReceipt
{
    public function __construct(
        public int $accepted,
        public Uuid $requestId,
    ) {}
}
