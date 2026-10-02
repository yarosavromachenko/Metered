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
 * Stream message format: a flat string map with a version field; unknown
 * versions are rejected. The quantity is a string, never a float.
 * `traceparent` and `tracestate` are optional (ADR-0012).
 */
final readonly class StreamEnvelope
{
    public const string VERSION = '1';

    /** @var list<string> */
    private const array TRACE_FIELDS = ['traceparent', 'tracestate'];

    public function __construct(
        public TenantContext $tenant,
        public Uuid $requestId,
        public DateTimeImmutable $receivedAt,
        public SubmittedEvent $event,
        /** @var array<string, string> */
        public array $trace = [],
    ) {}

    /**
     * @param  array<string, string>  $trace  the context of the request that accepted the batch
     * @return list<array<string, string>>
     */
    public static function encodeBatch(Batch $batch, array $trace = []): array
    {
        $messages = [];
        $traceFields = self::traceFields($trace);

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
                ...$traceFields,
            ];
        }

        return $messages;
    }

    /**
     * Never throws; null for anything unreadable.
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
                self::traceFields($fields),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private static function traceFields(array $fields): array
    {
        return array_intersect_key($fields, array_flip(self::TRACE_FIELDS));
    }

    private static function encodeProperties(Properties $properties): string
    {
        try {
            return json_encode($properties->all(), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Validated at the endpoint; unreachable in practice.
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

        // Converted to UTC: an offset in the string overrides the zone argument.
        return new DateTimeImmutable($value, $utc)->setTimezone($utc);
    }
}
