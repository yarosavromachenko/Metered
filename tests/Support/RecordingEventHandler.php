<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Shared\Application\Inbox\IntegrationEventHandler;
use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * An integration event handler that only remembers being called.
 *
 * Named rather than anonymous on purpose: two anonymous classes declared on the
 * same line share a class name, and the first version of these tests failed
 * because of exactly that.
 */
final class RecordingEventHandler implements IntegrationEventHandler
{
    public ?OutboxMessage $received = null;

    /**
     * @param  list<string>  $types
     */
    public function __construct(
        private readonly string $name,
        private readonly array $types,
        private readonly ?CallLog $log = null,
    ) {}

    public function consumerName(): string
    {
        return $this->name;
    }

    public function subscribesTo(): array
    {
        return $this->types;
    }

    public function handle(OutboxMessage $message): void
    {
        $this->received = $message;
        $this->log?->record($this->name);
    }
}
