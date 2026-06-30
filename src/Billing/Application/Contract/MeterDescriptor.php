<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Metering\Aggregation;

/**
 * What another module is told about a meter: its id, the code events name it
 * by, and how those events fold.
 *
 * Deliberately not the Meter entity. Handing out the aggregate would make
 * every field of it — and every change to it — part of Billing's public
 * surface, and the consumer that reads this needs exactly three things.
 */
final readonly class MeterDescriptor
{
    public function __construct(
        public Uuid $id,
        public string $code,
        public Aggregation $aggregation,
    ) {}
}
