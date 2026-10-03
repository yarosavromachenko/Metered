<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

use DateTimeImmutable;

interface AttemptLog
{
    public function record(Delivery $delivery, int $number, DateTimeImmutable $at, AttemptResult $result): void;
}
