<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Exception\InvalidEndpoint;

/**
 * Event names as a caller spells them, read into the types they name.
 */
final class EventTypes
{
    /**
     * @param list<string> $names
     *
     * @return list<EventType>
     */
    public static function parse(array $names): array
    {
        return array_map(
            static fn(string $name): EventType => EventType::tryFrom(strtolower(trim($name)))
                ?? throw InvalidEndpoint::unknownEvent($name, EventType::names()),
            $names,
        );
    }
}
