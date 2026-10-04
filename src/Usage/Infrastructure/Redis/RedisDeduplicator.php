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
 * `SET key occurred_at NX GET EX ttl` per event, pipelined: claim and lookup
 * in one atomic step. Same stored timestamp: pass to the database. Different:
 * a resend, dropped. Keys are per project.
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
            // false: claimed now; string: the earlier claim's timestamp.
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
     * Microseconds since the epoch, timezone-independent.
     */
    private function stamp(UsageEvent $event): string
    {
        return $event->occurredAt->format('U.u');
    }
}
