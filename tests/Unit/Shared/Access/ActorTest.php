<?php

declare(strict_types=1);

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;

it('names a person by the address they signed in with, however they typed it', function (): void {
    // The label is what the audit log shows; one person must not appear as
    // two because they capitalised their address differently one morning.
    $actor = Actor::user(Uuid::fromString('01a0ca27-2709-735f-be49-901cb66db508'), "  Ada@NorthWind.Example\n");

    expect($actor->label)->toBe('user:ada@northwind.example')
        ->and($actor->isSystem())->toBeFalse();
});

it('names a caller with no person behind it by what it is', function (): void {
    $actor = Actor::system('console:org:create');

    expect($actor->label)->toBe('console:org:create')
        ->and($actor->userId)->toBeNull()
        ->and($actor->isSystem())->toBeTrue();
});
