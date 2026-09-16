<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Shared\Application\Metrics\SharedMetrics;
use Psr\Clock\ClockInterface;

/**
 * How far behind the relay is: the age of the oldest message it still has
 * to publish. Three rows waiting for a second is healthy; one waiting for an
 * hour is an incident, which a count would not tell apart.
 *
 * Messages that ran out of attempts are left out: the relay no longer tries
 * them, so counted here they would be a lag that never goes down.
 */
final readonly class OutboxGauges implements GaugeSource
{
    public function __construct(
        private DatabaseManager $db,
        private ClockInterface $clock,
        private string $connection,
        private int $maxAttempts,
    ) {}

    public function read(): array
    {
        $oldest = $this->db->connection($this->connection)->table('outbox_messages')
            ->whereNull('published_at')
            ->where('attempts', '<', $this->maxAttempts)
            ->min('occurred_at');

        // The application's clock, not the database's: in a demo it can be
        // moved forward, and the messages are stamped with it.
        $age = is_string($oldest)
            ? max(0, $this->clock->now()->getTimestamp() - new DateTimeImmutable($oldest)->getTimestamp())
            : 0;

        return [new GaugeReading(SharedMetrics::outboxAge(), $age)];
    }
}
