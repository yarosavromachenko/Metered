<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Metering\Aggregation;

/**
 * Exposed instead of the Meter entity, so the entity stays internal to Billing.
 */
final readonly class MeterDescriptor
{
    public function __construct(
        public Uuid $id,
        public string $code,
        public Aggregation $aggregation,
    ) {}
}
