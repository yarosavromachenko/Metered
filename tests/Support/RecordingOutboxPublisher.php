<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Shared\Application\Outbox\OutboxPublisher;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use RuntimeException;

/**
 * A publisher that remembers what it was asked to publish, and can be told to
 * fail — which is how the relay's failure handling gets exercised without
 * breaking a real queue.
 */
final class RecordingOutboxPublisher implements OutboxPublisher
{
    /** @var list<OutboxMessage> */
    public array $published = [];

    public function __construct(private readonly ?string $failWith = null) {}

    public function publish(OutboxMessage $message): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->published[] = $message;
    }

    /**
     * @return list<string>
     */
    public function publishedTypes(): array
    {
        return array_map(static fn(OutboxMessage $m): string => $m->type, $this->published);
    }
}
