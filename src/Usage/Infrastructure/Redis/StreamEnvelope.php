<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Application\Command\SubmittedEvent;
use Metered\Usage\Application\Stream\Batch;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Properties;
use Throwable;

/**
 * How one event is written to the stream, and read back off it.
 *
 * A stream message is a flat map of strings, and the shape below is the
 * contract between the endpoint and the consumer — the one place where a
 * change breaks messages already in flight. Hence the version field: a
 * consumer that meets a version it does not know refuses the message rather
 * than guessing, which turns a deploy ordering mistake into a dead-lettered
 * message instead of a silently mis-parsed one.
 *
 * Everything travels as a string, including the quantity. A number that goes
 * through a JSON float on the way to a billing calculation is a rounding
 * error with a customer's name on it.
 */
final readonly class StreamEnvelope
{
    public const string VERSION = '1';

    public function __construct(
        public TenantContext $tenant,
        public Uuid $requestId,
        public DateTimeImmutable $receivedAt,
        public SubmittedEvent $event,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public static function encodeBatch(Batch $batch): array
    {
        $messages = [];

        foreach ($batch->events as $event) {
            $messages[] = [
                'v' => self::VERSION,
                'organization_id' => $batch->tenant->organizationId->value,
                'project_id' => $batch->tenant->projectId->value,
                'request_id' => $batch->requestId->value,
                'received_at' => $batch->receivedAt->format(DateTimeImmutable::RFC3339_EXTENDED),
                'event_id' => $event->eventId->value,
                'meter_code' => $event->meterCode,
                'customer_ref' => $event->customerReference,
                'quantity' => (string) $event->quantity,
                'occurred_at' => $event->occurredAt->format(DateTimeImmutable::RFC3339_EXTENDED),
                'properties' => self::encodeProperties($event->properties),
            ];
        }

        return $messages;
    }

    /**
     * Reads a message back, or returns null when it is not one.
     *
     * Total on purpose. The consumer meets whatever is in the stream —
     * a message from a future version, a truncated write, something a person
     * added by hand while debugging — and none of those may throw their way
     * out of a batch of five hundred. A message that cannot be read is
     * rejected as malformed, with itself as the evidence.
     *
     * @param  array<string, string>  $fields
     */
    public static function decode(array $fields): ?self
    {
        if (($fields['v'] ?? null) !== self::VERSION) {
            return null;
        }

        try {
            $tenant = new TenantContext(
                Uuid::fromString($fields['organization_id'] ?? ''),
                Uuid::fromString($fields['project_id'] ?? ''),
            );

            return new self(
                $tenant,
                Uuid::fromString($fields['request_id'] ?? ''),
                self::instant($fields['received_at'] ?? ''),
                new SubmittedEvent(
                    EventId::fromString($fields['event_id'] ?? ''),
                    $fields['meter_code'] ?? '',
                    $fields['customer_ref'] ?? '',
                    Quantity::fromString($fields['quantity'] ?? ''),
                    self::instant($fields['occurred_at'] ?? ''),
                    self::decodeProperties($fields['properties'] ?? '{}'),
                ),
            );
        } catch (Throwable) {
            // Deliberately broad: every failure here has the same answer, and
            // that answer is never "stop consuming".
            return null;
        }
    }

    private static function encodeProperties(Properties $properties): string
    {
        try {
            return json_encode($properties->all(), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Properties were validated as scalars at the edge, so this cannot
            // happen with data that got this far — and if it somehow did, an
            // event without its labels beats a batch that could not be sent.
            return '{}';
        }
    }

    private static function decodeProperties(string $encoded): Properties
    {
        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? Properties::fromArray($decoded) : Properties::none();
    }

    private static function instant(string $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        // Converted, not merely constructed: an offset inside the string wins
        // over the zone passed beside it, and a value that keeps +03:00 is the
        // same instant rendered as a different one.
        return new DateTimeImmutable($value, $utc)->setTimezone($utc);
    }
}
