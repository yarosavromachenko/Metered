<?php

declare(strict_types=1);

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Metered\Shared\Infrastructure\Persistence\DatabaseOutage;

function pdoFailure(string $state, string $message = 'it failed'): PDOException
{
    $failure = new PDOException(sprintf('SQLSTATE[%s]: %s', $state, $message));
    new ReflectionProperty(PDOException::class, 'code')->setValue($failure, $state);

    return $failure;
}

function queryFailure(string $state, string $message = 'it failed'): QueryException
{
    return new QueryException('pgsql', 'insert into usage_events values (?)', [], pdoFailure($state, $message));
}

it('recognises a database that could not be reached', function (Throwable $failure): void {
    expect(new DatabaseOutage(new LostConnectionDetector())->causedBy($failure))->toBeTrue();
})->with([
    'connection failure' => [queryFailure('08006')],
    'cannot connect' => [queryFailure('08001')],
    'administrator shutdown' => [queryFailure('57P01')],
    'crash shutdown' => [queryFailure('57P02')],
    'starting up' => [queryFailure('57P03')],
    'too many connections' => [queryFailure('53300')],
    'PgBouncer gave up waiting for a server' => [queryFailure('08P01', 'ERROR:  query_wait_timeout')],
    'a lost connection Laravel knows by its message' => [queryFailure('HY000', 'server closed the connection unexpectedly')],
    'wrapped in another exception' => [new RuntimeException('the batch failed', 0, queryFailure('08006'))],
]);

it('does not mistake a refusal of the data for an outage', function (Throwable $failure): void {
    expect(new DatabaseOutage(new LostConnectionDetector())->causedBy($failure))->toBeFalse();
})->with([
    'unique violation' => [queryFailure('23505')],
    'numeric out of range' => [queryFailure('22003')],
    'invalid text in jsonb' => [queryFailure('22P05')],
    'serialization failure' => [queryFailure('40001')],
    'missing table' => [queryFailure('42P01')],
    'not a database error' => [new RuntimeException('something else broke')],
]);
