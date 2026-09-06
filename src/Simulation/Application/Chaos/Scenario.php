<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Chaos;

enum Scenario: string
{
    case KillConsumer = 'kill-consumer';
    case KillRedisBrief = 'kill-redis-brief';
    case KillRelay = 'kill-relay';
    case SlowWebhook = 'slow-webhook';
    case FailingWebhook = 'failing-webhook';

    public function describe(): string
    {
        return match ($this) {
            self::KillConsumer => 'A usage consumer is killed while it holds messages; none may be lost or counted twice.',
            self::KillRedisBrief => 'Redis stops answering for three seconds under ingestion; every accepted event must still count once.',
            self::KillRelay => 'The outbox relay is killed mid-run; every event must still reach the endpoint, once.',
            self::SlowWebhook => 'One endpoint answers after the timeout; the healthy one beside it must not wait, and nothing may be lost.',
            self::FailingWebhook => 'One endpoint always fails; its breaker must open, the healthy one must get everything, nothing may be lost.',
        };
    }
}
