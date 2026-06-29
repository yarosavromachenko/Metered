<?php

declare(strict_types=1);

use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\Exception\InvalidCustomerReference;

it('takes the identifier the tenant already uses, in the shape they use it', function (string $given): void {
    expect((string) CustomerReference::fromString($given))->toBe($given);
})->with([
    'stripe-looking' => ['cus_NffrFeUfNV2Hib'],
    'a uuid' => ['018f3a2e-5b40-7c8e-8a01-2b9c5d6e7f80'],
    'an email' => ['billing@northwind.example'],
    'a number' => ['4471'],
    'a path' => ['tenants/44/accounts/7'],
    'one character' => ['x'],
]);

it('keeps the case it was given, because the tenant’s own ids are theirs', function (): void {
    // Unlike a meter code, this is a foreign key into somebody else's system.
    // Folding its case would merge two of their customers into one of ours.
    expect((string) CustomerReference::fromString('CUS_Abc'))->toBe('CUS_Abc')
        ->and(CustomerReference::fromString('CUS_Abc')->equals(CustomerReference::fromString('cus_abc')))
        ->toBeFalse();
});

it('trims the whitespace a copy-paste leaves behind', function (): void {
    expect((string) CustomerReference::fromString("  cus_42\n"))->toBe('cus_42');
});

it('refuses what could not survive a URL or a log line', function (string $given): void {
    expect(static fn(): CustomerReference => CustomerReference::fromString($given))
        ->toThrow(InvalidCustomerReference::class);
})->with([
    'empty' => [''],
    'whitespace only' => ["  \t "],
    'an inner space' => ['cus 42'],
    'a newline inside' => ["cus\n42"],
    'a control character' => ["cus\x00 42"],
]);

it('refuses a reference longer than the column that stores it', function (): void {
    expect(static fn(): CustomerReference => CustomerReference::fromString(str_repeat('c', 129)))
        ->toThrow(InvalidCustomerReference::class, 'at most 128');
});
