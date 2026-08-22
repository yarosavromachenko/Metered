<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class DeliveryRefused extends DomainException
{
    public static function notReplayable(string $status): self
    {
        return new self(sprintf('A %s delivery cannot be replayed; only a dead or failed one can.', $status));
    }
}
