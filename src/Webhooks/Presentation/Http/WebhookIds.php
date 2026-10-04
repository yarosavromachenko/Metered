<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Webhooks\Application\Command\WebhookNotFound;

/**
 * A malformed id is answered like an unknown one (404).
 */
final class WebhookIds
{
    public static function parse(string $what, string $given): Uuid
    {
        return Uuid::isValid($given)
            ? Uuid::fromString($given)
            : throw WebhookNotFound::reference($what, $given);
    }
}
