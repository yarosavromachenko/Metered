<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Identifier\Uuid;
use RuntimeException;

/**
 * No such endpoint or delivery in the command's project — the same answer
 * whether it does not exist or belongs to another tenant.
 */
final class WebhookNotFound extends RuntimeException implements NotFound
{
    public static function of(string $what, Uuid $id): self
    {
        return self::reference($what, $id->value);
    }

    public static function reference(string $what, string $given): self
    {
        return new self(sprintf('No webhook %s %s in this project.', $what, $given));
    }
}
