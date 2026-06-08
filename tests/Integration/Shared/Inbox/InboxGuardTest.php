<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;

function messageId(): Uuid
{
    return app(IdentifierGenerator::class)->generate();
}

it('runs the handler the first time it sees a message', function (): void {
    $ran = 0;

    $executed = app(InboxGuard::class)->once('billing', messageId(), function () use (&$ran): void {
        $ran++;
    });

    expect($executed)->toBeTrue()
        ->and($ran)->toBe(1);
});

it('skips a message it has already processed', function (): void {
    $guard = app(InboxGuard::class);
    $id = messageId();
    $ran = 0;

    $handler = function () use (&$ran): void {
        $ran++;
    };

    expect($guard->once('billing', $id, $handler))->toBeTrue()
        ->and($guard->once('billing', $id, $handler))->toBeFalse()
        ->and($guard->once('billing', $id, $handler))->toBeFalse()
        // At-least-once delivery, exactly-once effect. This is the whole point.
        ->and($ran)->toBe(1);
});

it('gives every consumer its own turn at the same message', function (): void {
    $guard = app(InboxGuard::class);
    $id = messageId();
    $seenBy = [];

    foreach (['billing', 'webhooks', 'analytics'] as $consumer) {
        $guard->once($consumer, $id, function () use (&$seenBy, $consumer): void {
            $seenBy[] = $consumer;
        });
    }

    expect($seenBy)->toBe(['billing', 'webhooks', 'analytics'])
        ->and(DB::table('inbox_messages')->where('message_id', $id->value)->count())->toBe(3);
});

it('forgets the claim when the handler fails, so the message can come back', function (): void {
    $guard = app(InboxGuard::class);
    $id = messageId();
    $attempts = 0;

    $flaky = function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('the first attempt fails');
        }
    };

    expect(static fn(): bool => $guard->once('billing', $id, $flaky))
        ->toThrow(RuntimeException::class, 'the first attempt fails');

    // The claim rolled back with the handler, so nothing was silently
    // swallowed: redelivery gets a real second attempt.
    expect(DB::table('inbox_messages')->where('message_id', $id->value)->count())->toBe(0)
        ->and($guard->once('billing', $id, $flaky))->toBeTrue()
        ->and($attempts)->toBe(2);
});

it('records when it processed a message', function (): void {
    $id = messageId();

    app(InboxGuard::class)->once('billing', $id, static function (): void {});

    $row = DB::table('inbox_messages')->where('message_id', $id->value)->first();

    expect($row?->consumer)->toBe('billing')
        ->and($row?->processed_at)->not->toBeNull();
});
