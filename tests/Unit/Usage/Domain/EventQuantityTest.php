<?php

declare(strict_types=1);

use Metered\Shared\Domain\Exception\InvalidDecimal;
use Metered\Usage\Domain\EventQuantity;
use Metered\Usage\Domain\Exception\InvalidEventQuantity;

it('takes any quantity the column that stores an event can hold', function (string $given, string $stored): void {
    expect((string) EventQuantity::fromString($given))->toBe($stored);
})->with([
    'zero' => ['0', '0.000000'],
    'a fraction' => ['2.5', '2.500000'],
    'six decimal places' => ['0.000001', '0.000001'],
    'fourteen whole digits' => ['99999999999999', '99999999999999.000000'],
    'the largest it can store' => ['99999999999999.999999', '99999999999999.999999'],
]);

it('refuses a quantity too large for the column, rather than accepting what cannot be written', function (string $given): void {
    expect(static fn(): mixed => EventQuantity::fromString($given))
        ->toThrow(InvalidEventQuantity::class, 'less than 100000000000000');
})->with([
    'exactly the limit' => ['100000000000000'],
    'the limit with decimals' => ['100000000000000.000001'],
    'far beyond it' => ['1000000000000000'],
    'in exponent form' => ['1e15'],
]);

it('quotes the refused quantity as the client meant it, without what a copy-paste left around it', function (): void {
    expect(static fn(): mixed => EventQuantity::fromString("  100000000000000\n"))
        ->toThrow(InvalidEventQuantity::class, '"100000000000000" is more than one event can carry');
});

it('leaves the other rules of a quantity to the quantity', function (string $given, string $message): void {
    expect(static fn(): mixed => EventQuantity::fromString($given))
        ->toThrow(InvalidDecimal::class, $message);
})->with([
    'not a number' => ['many', 'not a decimal number'],
    'negative' => ['-1', 'negative'],
    'too precise' => ['0.0000001', 'more than 6 decimal places'],
]);
