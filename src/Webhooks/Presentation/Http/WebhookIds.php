<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Webhooks\Application\Command\WebhookNotFound;

/**
 * An id from the path. Something that is not a UUID is answered like an id
 * that does not exist, so the two cannot be told apart from outside.
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
