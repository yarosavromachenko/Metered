<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidMeterCode;
use Metered\Billing\Domain\MeterCode;

it('accepts the shapes a client would reasonably send', function (string $given): void {
    expect((string) MeterCode::fromString($given))->toBe($given);
})->with([
    'a word' => ['requests'],
    'dotted' => ['api.requests'],
    'snake case' => ['api_requests'],
    'hyphenated' => ['api-requests'],
    'with digits' => ['s3.storage_gb.v2'],
    'the shortest there is' => ['ab'],
]);

it('reads a code the same however the client capitalised it', function (): void {
    // The code is typed into a client's instrumentation once and sent forever
    // after. A meter that quietly stops counting because a deploy changed
    // "API.Calls" to "api.calls" is the kind of bug nobody finds until the
    // invoice is wrong.
    expect((string) MeterCode::fromString('API.Calls'))->toBe('api.calls')
        ->and((string) MeterCode::fromString('  api.calls  '))->toBe('api.calls')
        ->and(MeterCode::fromString('API.Calls')->equals(MeterCode::fromString('api.calls')))->toBeTrue();
});

it('refuses a shape that would be ambiguous in an event', function (string $given): void {
    expect(static fn(): MeterCode => MeterCode::fromString($given))
        ->toThrow(InvalidMeterCode::class, 'is not a valid meter code');
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
    'inner space' => ['api calls'],
    'leading separator' => ['.requests'],
    'trailing separator' => ['requests.'],
    'doubled separator' => ['api..requests'],
    'punctuation' => ['api:requests'],
    'a slash' => ['api/requests'],
    'non-ascii' => ['запросы'],
]);

it('refuses a code too short to mean anything', function (): void {
    expect(static fn(): MeterCode => MeterCode::fromString('a'))
        ->toThrow(InvalidMeterCode::class, 'at least 2');
});

it('refuses a code longer than the column that stores it', function (): void {
    expect(static fn(): MeterCode => MeterCode::fromString(str_repeat('a', 65)))
        ->toThrow(InvalidMeterCode::class, 'at most 64');
});

it('takes a code exactly as long as the column', function (): void {
    expect((string) MeterCode::fromString(str_repeat('m', 64)))->toHaveLength(64);
});

it('tells the author of a malformed code what a code may contain', function (): void {
    expect(static fn(): MeterCode => MeterCode::fromString('api requests'))
        ->toThrow(
            InvalidMeterCode::class,
            '"api requests" is not a valid meter code: lowercase letters, digits, and single dots, '
            . 'hyphens or underscores between them.',
        );
});
