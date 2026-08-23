<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

use DateTimeImmutable;

/**
 * Every attempt a delivery made: when, how long, and what came back. Support
 * is impossible without it.
 */
interface AttemptLog
{
    public function record(Delivery $delivery, int $number, DateTimeImmutable $at, AttemptResult $result): void;
}
