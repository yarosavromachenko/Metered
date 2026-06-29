<?php

declare(strict_types=1);

use Metered\Shared\Infrastructure\Persistence\RowReader;

it('accepts a string', function (): void {
    expect(RowReader::string('invoice.finalized', 'type'))->toBe('invoice.finalized');
});

it('refuses anything that is not a string, naming the column', function (mixed $value): void {
    expect(static fn(): string => RowReader::string($value, 'type'))
        ->toThrow(RuntimeException::class, 'Column "type" was expected to hold a string');
})->with([
    'null' => [null],
    'integer' => [42],
    'array' => [[]],
    'object' => [new stdClass()],
]);

it('accepts an integer however the driver returned it', function (mixed $value): void {
    // Some PDO builds hand back PostgreSQL integers as strings, and a row that
    // is correct must not depend on which build is installed.
    expect(RowReader::int($value, 'attempts'))->toBe(7);
})->with([
    'native integer' => [7],
    'numeric string' => ['7'],
]);

it('refuses a value that is not a whole number', function (mixed $value): void {
    expect(static fn(): int => RowReader::int($value, 'attempts'))
        ->toThrow(RuntimeException::class, 'Column "attempts" was expected to hold a int');
})->with([
    'decimal string' => ['7.5'],
    'words' => ['seven'],
    'null' => [null],
    'float' => [7.0],
]);

it('accepts a negative count, because a wrong number is better read than guessed', function (): void {
    expect(RowReader::int('-3', 'attempts'))->toBe(-3);
});

it('decodes a JSON object', function (): void {
    expect(RowReader::jsonObject('{"total":1999,"late":true}', 'payload'))
        ->toBe(['total' => 1999, 'late' => true]);
});

it('decodes an empty JSON object', function (): void {
    expect(RowReader::jsonObject('{}', 'payload'))->toBe([]);
});

it('refuses JSON it cannot parse', function (): void {
    expect(static fn(): array => RowReader::jsonObject('{not json', 'payload'))
        ->toThrow(RuntimeException::class, 'Column "payload" does not contain valid JSON');
});

it('refuses JSON that is not an object', function (string $json): void {
    expect(static fn(): array => RowReader::jsonObject($json, 'payload'))
        ->toThrow(RuntimeException::class, 'expected to hold a JSON object');
})->with([
    'a number' => ['42'],
    'a string' => ['"text"'],
    'a boolean' => ['true'],
    'null' => ['null'],
]);

it('decodes a map of strings', function (): void {
    expect(RowReader::jsonStringMap('{"traceparent":"00-abc-def-01"}', 'headers'))
        ->toBe(['traceparent' => '00-abc-def-01']);
});

it('refuses a header map whose values are not strings, naming the key', function (): void {
    expect(static fn(): array => RowReader::jsonStringMap('{"retries":3}', 'headers'))
        ->toThrow(RuntimeException::class, 'Column "headers.retries" was expected to hold a string');
});

it('reads a timestamp as the instant it names, whatever offset it arrived with', function (): void {
    // PostgreSQL hands a timestamptz back in the session's time zone. Two rows
    // written a moment apart can therefore come back written differently, and
    // the domain compares instants, not spellings.
    $utc = RowReader::instant('2026-09-22 09:00:00+00', 'occurred_at');
    $elsewhere = RowReader::instant('2026-09-22 12:00:00+03', 'occurred_at');

    expect($utc->format(DATE_ATOM))->toBe('2026-09-22T09:00:00+00:00')
        ->and($elsewhere->getTimestamp())->toBe($utc->getTimestamp())
        ->and($elsewhere->getTimezone()->getName())->toBe('UTC');
});

it('keeps the microseconds a hash or a window depends on', function (): void {
    expect(RowReader::instant('2026-09-22 09:00:00.123456+00', 'occurred_at')->format('u'))
        ->toBe('123456');
});

it('passes a null timestamp through as one', function (): void {
    expect(RowReader::instantOrNull(null, 'revoked_at'))->toBeNull()
        ->and(RowReader::instantOrNull('2026-09-22 09:00:00+00', 'revoked_at')?->format(DATE_ATOM))
        ->toBe('2026-09-22T09:00:00+00:00');
});

it('refuses a timestamp column holding something that is not one', function (): void {
    expect(static fn(): DateTimeImmutable => RowReader::instant(1758531600, 'occurred_at'))
        ->toThrow(RuntimeException::class, 'Column "occurred_at" was expected to hold a string');
});

it('decodes a JSON array as a list', function (): void {
    expect(RowReader::stringList('["usage:write","admin"]', 'scopes'))->toBe(['usage:write', 'admin'])
        ->and(array_is_list(RowReader::stringList('[]', 'scopes')))->toBeTrue();
});

it('refuses a list whose items are not strings, naming the position', function (): void {
    expect(static fn(): array => RowReader::stringList('["usage:write",7]', 'scopes'))
        ->toThrow(RuntimeException::class, 'Column "scopes.1" was expected to hold a string');
});
