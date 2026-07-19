<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPlanCode;
use Metered\Billing\Domain\Plan\PlanCode;

it('accepts the codes an integration would name a plan by', function (string $given): void {
    expect((string) PlanCode::fromString($given))->toBe($given);
})->with([
    'a word' => ['starter'],
    'hyphenated' => ['pro-annual'],
    'with digits' => ['growth-2026'],
    'snake case' => ['team_plus'],
    'the shortest there is' => ['s1'],
    'the longest there is' => [str_repeat('p', 64)],
]);

it('reads a code the same however it was capitalised', function (): void {
    expect((string) PlanCode::fromString('  Pro-Annual '))->toBe('pro-annual')
        ->and(PlanCode::fromString('PRO')->equals(PlanCode::fromString('pro')))->toBeTrue()
        ->and(PlanCode::fromString('pro')->equals(PlanCode::fromString('team')))->toBeFalse();
});

it('refuses a code outside its shape and length', function (string $given, string $message): void {
    expect(static fn(): PlanCode => PlanCode::fromString($given))->toThrow(InvalidPlanCode::class, $message);
})->with([
    'empty' => ['', 'is not a valid plan code: lowercase letters and digits, with single hyphens or underscores between them.'],
    'inner space' => ['pro plan', 'is not a valid plan code'],
    'a dot' => ['pro.annual', 'is not a valid plan code'],
    'trailing separator' => ['pro-', 'is not a valid plan code'],
    'one character' => ['p', 'at least 2 characters'],
    'one past the limit' => [str_repeat('p', 65), 'at most 64 characters, 65 given'],
]);
