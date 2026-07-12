<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Application\Ingestion\Deduplicator;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\UsageEvent;
use Redis;

/**
 * `SET key occurred_at NX GET EX ttl`, once per event, pipelined.
 *
 * `NX` is what makes this a claim rather than a lookup: the answer and the
 * reservation are one operation, so two consumers handed the same redelivered
 * batch cannot both conclude they were first. A GET followed by a SET would
 * be a race with a customer's invoice as the stake.
 *
 * `GET` returns what the key already held, which is the timestamp the event
 * was first claimed for. The same timestamp is the same event, possibly
 * claimed by a consumer that died before its write committed, and is passed
 * through to the database's unique key. A different timestamp is the resend
 * this layer exists for, and is dropped.
 *
 * The keys are namespaced per project, because an event id is the client's
 * own string and two tenants may pick the same one.
 */
final readonly class RedisDeduplicator implements Deduplicator
{
    public function __construct(
        private PhpRedisConnection $connection,
        private int $ttlSeconds,
    ) {}

    public function claim(TenantContext $tenant, array $events): array
    {
        if ($events === []) {
            return [];
        }

        $claims = [];

        foreach ($events as $event) {
            $claims[] = [$this->key($tenant, $event->eventId), $this->stamp($event)];
        }

        $ttl = $this->ttlSeconds;

        /** @var list<mixed> $answers */
        $answers = $this->connection->pipeline(static function (Redis $pipe) use ($claims, $ttl): void {
            foreach ($claims as [$key, $stamp]) {
                $pipe->set($key, $stamp, ['nx', 'ex' => $ttl, 'get']);
            }
        });

        $passed = [];

        foreach ($events as $index => $event) {
            // False means the key was not there and is now ours; a string is
            // the timestamp somebody claimed this event id for before.
            $previous = $answers[$index] ?? false;

            if ($previous === false || $previous === $claims[$index][1]) {
                $passed[] = $event;
            }
        }

        return $passed;
    }

    private function key(TenantContext $tenant, EventId $eventId): string
    {
        return 'usage:dedup:' . $tenant->projectId->value . ':' . $eventId->value;
    }

    /**
     * Microseconds since the epoch: the precision `occurred_at` is stored at,
     * and a form no timezone can make two different strings of.
     */
    private function stamp(UsageEvent $event): string
    {
        return $event->occurredAt->format('U.u');
    }
}
