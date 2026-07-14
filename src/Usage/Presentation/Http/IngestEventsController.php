<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Presentation\Http\Problem;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Usage\Application\Command\IngestEvents;
use Metered\Usage\Application\Command\IngestEventsHandler;
use Metered\Usage\Application\Command\IngestionOverloaded;
use Metered\Usage\Application\Command\SubmittedEvent;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Properties;

/**
 * `POST /api/v1/usage/events` — the endpoint a tenant's product calls.
 *
 * It answers `202 Accepted`, and the word means what ADR-0003 says it means:
 * the batch is durably in the stream. Not in PostgreSQL, and not checked
 * against the catalog — a meter that does not exist is found out later and
 * shows up as a rejection, not as a status code here.
 *
 * What is checked here is everything that can be checked without leaving the
 * process: the shape, the numbers, the timestamps, the labels. A client that
 * sent nonsense gets told immediately and with a pointer to the event that
 * caused it, because "one of your hundred events was wrong" is not an answer
 * anybody can act on.
 */
final readonly class IngestEventsController
{
    public function __construct(
        private IngestEventsHandler $handler,
        private int $batchLimit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);

        /** @var array{events: list<array<string, mixed>>} $payload */
        $payload = Validator::make($request->all(), [
            'events' => ['required', 'array', 'min:1', 'max:' . $this->batchLimit],
            'events.*.event_id' => ['required', 'string', 'max:128'],
            'events.*.meter_code' => ['required', 'string', 'max:64'],
            'events.*.customer_ref' => ['required', 'string', 'max:128'],
            // A non-negative decimal. Send it as a string ("2.5") to keep its
            // precision; a JSON number is accepted too, and is turned into a
            // decimal string before anything adds it up.
            'events.*.quantity' => ['required', 'numeric'],
            'events.*.occurred_at' => ['required', 'date'],
            /**
             * Free-form attributes of the event, stored with it.
             *
             * @var array<string, mixed>
             */
            'events.*.properties' => ['sometimes', 'array'],
        ])->validate();

        $events = [];

        foreach ($payload['events'] as $index => $raw) {
            try {
                $events[] = $this->toEvent($raw);
            } catch (DomainException $invalid) {
                return $this->unprocessable($request, $index, $invalid->getMessage());
            }
        }

        try {
            $receipt = $this->handler->handle(new IngestEvents($tenant, $events));
        } catch (IngestionOverloaded $overloaded) {
            return Problem::response(
                'ingestion-overloaded',
                'Ingestion is shedding load',
                503,
                $overloaded->getMessage() . ' Retry after ' . $overloaded->retryAfterSeconds . ' seconds.',
                Problem::instanceFor($request),
            )->withHeaders(['Retry-After' => (string) $overloaded->retryAfterSeconds]);
        }

        return new JsonResponse(
            [
                'accepted' => $receipt->accepted,
                'request_id' => $receipt->requestId->value,
            ],
            202,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function toEvent(array $raw): SubmittedEvent
    {
        $properties = $raw['properties'] ?? [];

        return new SubmittedEvent(
            EventId::fromString($this->string($raw['event_id'] ?? null)),
            $this->string($raw['meter_code'] ?? null),
            $this->string($raw['customer_ref'] ?? null),
            Quantity::fromString($this->string($raw['quantity'] ?? null)),
            // Whatever offset the client wrote, the instant travels in UTC:
            // the partition, the bucket and every comparison downstream are
            // UTC, and an offset that survived this far would render one
            // moment as two.
            $this->instant($this->string($raw['occurred_at'] ?? null)),
            Properties::fromArray(is_array($properties) ? $properties : []),
        );
    }

    private function instant(string $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return new DateTimeImmutable($value, $utc)->setTimezone($utc);
    }

    /**
     * A JSON number arriving where a string was expected is a client sending
     * a quantity unquoted, which is reasonable. Anything else has already
     * failed validation, and an empty string fails the value object next.
     */
    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function unprocessable(Request $request, int $index, string $detail): JsonResponse
    {
        return Problem::response(
            'invalid-event',
            'Invalid event',
            422,
            $detail,
            Problem::instanceFor($request),
            // A pointer, because a batch of a hundred with one bad member is
            // the case this endpoint exists to survive.
            ['pointer' => sprintf('/events/%d', $index)],
        );
    }
}
