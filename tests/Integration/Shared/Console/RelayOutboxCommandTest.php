<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;
use Psr\Clock\ClockInterface;

function writeMessage(string $type): OutboxMessage
{
    $ids = app(IdentifierGenerator::class);

    $message = new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: $type,
        payload: ['total' => 1999],
        headers: [],
        occurredAt: app(ClockInterface::class)->now(),
    );

    app(OutboxWriter::class)->append($message);

    return $message;
}

it('publishes a single batch and exits', function (): void {
    Bus::fake();
    $message = writeMessage('invoice.finalized');

    expect(Artisan::call('outbox:relay', ['--once' => true]))->toBe(0);

    Bus::assertDispatched(
        DeliverIntegrationEvent::class,
        static fn(DeliverIntegrationEvent $job): bool => $job->id === $message->id->value,
    );

    expect(DB::table('outbox_messages')->where('id', $message->id->value)->value('published_at'))
        ->not->toBeNull();
});

it('honours the batch size it is given', function (): void {
    Bus::fake();

    foreach (range(1, 4) as $i) {
        writeMessage('event.' . $i);
    }

    expect(Artisan::call('outbox:relay', ['--once' => true, '--batch' => '2']))->toBe(0);

    Bus::assertDispatchedTimes(DeliverIntegrationEvent::class, 2);
    expect(DB::table('outbox_messages')->whereNull('published_at')->count())->toBe(2);
});

it('says nothing and succeeds when there is nothing to publish', function (): void {
    Bus::fake();

    expect(Artisan::call('outbox:relay', ['--once' => true]))->toBe(0);

    Bus::assertNothingDispatched();
});
