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
 * Relay lag: age of the oldest unpublished message. Messages out of attempts
 * are excluded.
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

        // App clock, not now(): messages are stamped with it, and the demo moves it.
        $age = is_string($oldest)
            ? max(0, $this->clock->now()->getTimestamp() - new DateTimeImmutable($oldest)->getTimestamp())
            : 0;

        return [new GaugeReading(SharedMetrics::outboxAge(), $age)];
    }
}
