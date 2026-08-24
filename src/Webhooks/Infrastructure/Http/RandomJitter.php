<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

use Metered\Webhooks\Application\Delivery\Jitter;
use Metered\Webhooks\Domain\Delivery\RetrySchedule;

final readonly class RandomJitter implements Jitter
{
    public function draw(): int
    {
        return random_int(-RetrySchedule::JITTER_PERMILLE, RetrySchedule::JITTER_PERMILLE);
    }
}
